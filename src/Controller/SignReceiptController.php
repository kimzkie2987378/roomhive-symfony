<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — SIGN RECEIPT (host side). STEP 1 of sign-to-accept.
 *
 * 1:1 port of legacy booking/sign-receipt.php — reconstructed from the
 * two callers that already target it and from the reader side of the
 * contract (the legacy file itself was never pasted, same situation as
 * BookingCancelController):
 *
 *   • host/pending-tenants.html.twig — the "Sign & Accept" pad posts
 *     booking_id + signature (PNG dataURL) to /host/bookings/{id}/sign,
 *     then POSTs /host/bookings/{id}/accept, then redirects to the
 *     receipt (/payment/confirmation/{id}).
 *   • payment/confirmation.html.twig — the receipt's own signing pad
 *     posts the same pair to the exact legacy URL /booking/sign-receipt,
 *     then (only when the booking is still pending) POSTs
 *     /booking/accept-booking and reloads.
 *   • PaymentConfirmationController — the READER of what this file
 *     writes: it looks for
 *         public/uploads/signatures/booking-{id}.png   (the signature)
 *         public/uploads/signatures/booking-{id}.json  (sidecar, reads
 *                                                       signed_at)
 *     and, when the .png exists, renders "Signed & Accepted by Host"
 *     + the signature image + "Signed on {date}" — plus the
 *     "Host Signature · Signed · {date}" row in the receipt meta.
 *
 * What signing means in RoomHive: the host's drawn signature is the
 * acceptance mark stamped on the guest's official FULL receipt. The
 * money side is handled by the accept step that always follows
 * (AcceptBookingController): bookings.amount_paid is split 97% to the
 * host's users.wallet_balance / 3% platform fee, payout columns
 * stamped — so the host receives the money exactly when the receipt
 * gets signed and the application is accepted.
 *
 * TWO ROUTES, ONE ACTION (same legacy endpoint served at both URLs —
 * the pending-tenants pad and the receipt pad each carry their own
 * historical URL contract, matching the AcceptBookingController
 * dual-route pattern):
 *   POST /host/bookings/{id}/sign   name app_host_booking_sign
 *                                   (route shape the pending-tenants
 *                                   pad already fetches — id from the
 *                                   URL)
 *   POST /booking/sign-receipt      name app_booking_sign_receipt
 *                                   (exact legacy URL — id from the
 *                                   POST body; the receipt pad's JS
 *                                   posts here)
 * Both callers also send booking_id in the body; the route {id} (when
 * present) wins, otherwise the body value is used — same precedence as
 * AcceptBookingController.
 *
 * Responds JSON: { success: bool, message?: string, signed_at?: string }
 * Callers only branch on success — everything else is alert text.
 * HTTP codes mirror AcceptBookingController exactly:
 *   401 not logged in · 400 bad id or bad signature data ·
 *   404 booking missing · 403 not the listing's host ·
 *   500 signature could not be written ·
 *   booking already decided → HTTP 200 with success=false (soft fail,
 *   same convention as accept's "already decided").
 *
 * STATUS GUARD: only 'pending' or 'confirmed' bookings may be signed —
 * exactly the rule PaymentConfirmationController uses to decide
 * whether to render the pad (can_host_sign). A 'cancelled'/'rejected'/
 * 'completed' booking is refused (soft 200).
 *
 * SIGNATURE VALIDATION (the pad sends canvas.toDataURL('image/png')):
 *   - must be the data:image/png;base64, prefix;
 *   - base64 decoded strictly (base64_decode strict mode);
 *   - decoded size capped at 3 MB (a 640×280 canvas PNG is ~20 KB —
 *     the cap only blocks abuse, never real signatures);
 *   - getimagesizefromstring() must confirm a real PNG (IMAGETYPE_PNG)
 *     — nothing but a PNG image ever lands on disk under /uploads.
 *
 * STORAGE (files only — legacy stamped NOTHING on the bookings row for
 * signatures; the receipt derives "signed" purely from file existence):
 *   public/uploads/signatures/booking-{id}.png   — raw PNG bytes
 *   public/uploads/signatures/booking-{id}.json  — {booking_id,
 *                                                   signed_at,
 *                                                   signed_by}
 *   signed_at is written in the same 'Y-m-d H:i:s' shape the receipt's
 *   strtotime() sidecar reader expects; signed_by records the host
 *   user id for audit. Re-signing overwrites both files (the receipt
 *   pad hides itself once the .png exists, so overwrites only happen
 *   through direct POSTs — legacy had the same overwrite semantics).
 *
 * The directory is created on demand (0775, recursive) so a fresh
 * deploy works without a manual mkdir — a failure there is a 500, it
 * can never leave the flow silently broken.
 *
 * NOTE — no transaction/row lock here: this endpoint writes no DB
 * rows at all, so there is nothing to lock. The accept step that
 * follows owns all DB mutation (FOR UPDATE + payout), unchanged.
 *
 * Port record — booking/sign-receipt.php (this file).
 */
final class SignReceiptController extends AbstractController
{
    /** The pad sends canvas.toDataURL('image/png') — accept nothing else. */
    private const PNG_DATA_PREFIX = 'data:image/png;base64,';

    /** Decoded-PNG cap — blocks abuse, never a real signature. */
    private const MAX_SIGNATURE_BYTES = 3145728; // 3 MB

    public function __construct(private readonly Connection $db) {}

    /* =====================================================================
     |  POST /host/bookings/{id}/sign   ·   POST /booking/sign-receipt
     | ================================================================== */
    #[Route('/host/bookings/{id}/sign', name: 'app_host_booking_sign', methods: ['POST'])]
    #[Route('/booking/sign-receipt', name: 'app_booking_sign_receipt', methods: ['POST'], priority: 20)]
    public function sign(Request $request, int $id = 0): Response
    {
        $session = $request->getSession();
        $userId  = (int) $session->get('user_id', 0);

        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(['success' => false, 'message' => 'You must be logged in.'], 401);
        }

        /* Route {id} (pending-tenants contract) wins; body booking_id
           (legacy contract — the receipt pad) is the fallback.
           is_numeric + int cast exactly like legacy / accept. */
        $rawId = $id > 0 ? (string) $id : (string) $request->request->get('booking_id', '');
        $bookingId = is_numeric($rawId) ? (int) $rawId : 0;

        if ($bookingId <= 0) {
            return $this->json(['success' => false, 'message' => 'Missing or invalid booking id.'], 400);
        }

        /* -----------------------------------------------------
           BOOKING + PERMISSION + STATUS GUARDS (read-only; no
           transaction needed — this endpoint writes files only)
        ----------------------------------------------------- */
        $booking = $this->db->fetchAssociative(
            "SELECT b.id, b.status,
                    l.user_id AS host_id
               FROM bookings b
               JOIN listings l ON l.id = b.listing_id
              WHERE b.id = :booking_id
              LIMIT 1",
            ['booking_id' => $bookingId]
        );

        if ($booking === false) {
            return $this->json(['success' => false, 'message' => 'Application not found.'], 404);
        }

        /* Only the host who owns the listing behind this booking may
           sign its receipt. */
        if ((int) $booking['host_id'] !== $userId) {
            return $this->json(['success' => false, 'message' => 'You do not have permission to sign this receipt.'], 403);
        }

        /* Only while the booking is pending or confirmed — the exact
           rule PaymentConfirmationController uses for can_host_sign. */
        if (!in_array((string) $booking['status'], ['pending', 'confirmed'], true)) {
            return $this->json(['success' => false, 'message' => 'This booking can no longer be signed.']);
        }

        /* -----------------------------------------------------
           DECODE + VALIDATE THE SIGNATURE PNG
        ----------------------------------------------------- */
        $dataUrl = (string) $request->request->get('signature', '');

        if (!str_starts_with($dataUrl, self::PNG_DATA_PREFIX)) {
            return $this->json(['success' => false, 'message' => 'Signature data is missing or is not a PNG image.'], 400);
        }

        $binary = base64_decode(substr($dataUrl, strlen(self::PNG_DATA_PREFIX)), true);

        if ($binary === false || $binary === '') {
            return $this->json(['success' => false, 'message' => 'Signature data could not be decoded.'], 400);
        }

        if (strlen($binary) > self::MAX_SIGNATURE_BYTES) {
            return $this->json(['success' => false, 'message' => 'Signature image is too large.'], 400);
        }

        $imgInfo = @getimagesizefromstring($binary);

        if ($imgInfo === false || (int) ($imgInfo[2] ?? 0) !== IMAGETYPE_PNG) {
            return $this->json(['success' => false, 'message' => 'Signature data is not a valid PNG image.'], 400);
        }

        /* -----------------------------------------------------
           WRITE THE PNG + JSON SIDECAR — the exact pair
           PaymentConfirmationController reads to render
           "Signed & Accepted by Host" on the full receipt
        ----------------------------------------------------- */
        try {
            $projectDir = (string) $this->getParameter('kernel.project_dir');
            $sigDir     = $projectDir . '/public/uploads/signatures';

            if (!is_dir($sigDir) && !@mkdir($sigDir, 0775, true) && !is_dir($sigDir)) {
                throw new \RuntimeException('Unable to create signature directory: ' . $sigDir);
            }

            $signedAt = date('Y-m-d H:i:s');

            $sigFile = $sigDir . '/booking-' . $bookingId . '.png';
            if (@file_put_contents($sigFile, $binary) === false) {
                throw new \RuntimeException('Unable to write signature file: ' . $sigFile);
            }

            $sigJson = $sigDir . '/booking-' . $bookingId . '.json';
            @file_put_contents(
                $sigJson,
                (string) json_encode(
                    [
                        'booking_id' => $bookingId,
                        'signed_at'  => $signedAt,
                        'signed_by'  => $userId,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                )
            );
        } catch (\Throwable $e) {
            error_log('sign-receipt: ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Could not save the signature. Please try again.'], 500);
        }

        return $this->json(['success' => true, 'signed_at' => $signedAt]);
    }
}
