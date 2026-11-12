<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — REJECT BOOKING (host side).
 *
 * 1:1 port of legacy booking/reject-booking.php — reconstructed from
 * the caller that already targets it and from the hard evidence in
 * the RoomHive schema/dump (the legacy file itself was never pasted,
 * same situation as BookingCancelController):
 *
 *   • bookings.status enum carries 'rejected' — the legacy decision
 *     state this endpoint writes;
 *   • the Pending Tenants page's confirm dialog promises the guest:
 *     "Reject %s's application for this listing? Their reserve will
 *     be refunded in full." — so the refund here is FULL (100% of
 *     bookings.amount_paid back to the guest's wallet). This is
 *     deliberately DIFFERENT from the guest-side cancel, where the
 *     dump row #41 proves legacy deducted a 2% platform fee: a cancel
 *     is the guest walking away; a reject is the HOST's decision, and
 *     the UI promise (authored by legacy itself) says the guest keeps
 *     everything. Nothing was ever paid out to the host either way —
 *     reject only runs while the booking is still pending.
 *
 *   POST /host/bookings/{id}/reject   name app_host_booking_reject
 *                                     (route shape all the host
 *                                     templates already fetch — id
 *                                     from the URL)
 *   POST /booking/reject-booking      name app_booking_reject
 *                                     (exact legacy URL — id from the
 *                                     POST body)
 * Callers also send booking_id in the body; the route {id} (when
 * present) wins, otherwise the body value is used — same precedence as
 * AcceptBookingController.
 *
 * Responds JSON: { success: bool, message?: string }
 * HTTP codes mirror AcceptBookingController exactly:
 *   401 not logged in · 400 bad id · 404 booking missing ·
 *   403 not the listing's host · 500 transaction failure ·
 *   "already decided" → HTTP 200 with success=false (legacy sent no
 *   status header for it, and the JS caller treats it as a soft fail).
 *
 * GUARDS (all inside one transaction, FOR UPDATE row lock):
 *   - a double-click or a race with an accept click cannot both go
 *     through (belt: status check; braces: WHERE status='pending' +
 *     rowCount check on the UPDATE);
 *   - only the host who owns the listing behind this booking may
 *     reject it;
 *   - only while the booking is still pending — a confirmed booking
 *     has already paid the host's 97% share out and can never be
 *     rejected (it must be cancelled through the guest flow instead);
 *   - no occupancy guard needed: rejecting only FREES the space, it
 *     never occupies it.
 *
 * REFUND: whatever the guest has actually paid into this booking
 * (bookings.amount_paid) goes back in FULL. refunded_amount is
 * stamped with the full paid figure and refunded_at with NOW();
 * payment_status stays untouched — the payments history page derives
 * its "refunded" display from refunded_amount > 0, and dump row #41
 * proves legacy left the column at 'paid' after refunding (matched
 * 1:1 with BookingCancelController).
 *
 * Wallet: legacy credits ONLY the users.wallet_balance row (no
 * wallet_transactions ledger entry) — same convention as
 * AcceptBookingController's host payout and BookingCancelController's
 * refund, matched 1:1.
 *
 * GUEST NOTIFICATION runs AFTER the COMMIT — a failure there can
 * never affect the rejection above (same rule as
 * AcceptBookingController / BookingCancelController). Link is the
 * guest's bookings list (/bookings — ported; the booking itself is
 * dead, so unlike accept there is no details page to deep-link).
 *
 * Port record — booking/reject-booking.php (this file).
 */
final class RejectBookingController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /* =====================================================================
     |  POST /host/bookings/{id}/reject   ·   POST /booking/reject-booking
     | ================================================================== */
    #[Route('/host/bookings/{id}/reject', name: 'app_host_booking_reject', methods: ['POST'])]
    #[Route('/booking/reject-booking', name: 'app_booking_reject', methods: ['POST'], priority: 20)]
    public function reject(Request $request, int $id = 0): Response
    {
        $session = $request->getSession();
        $userId  = (int) $session->get('user_id', 0);

        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(['success' => false, 'message' => 'You must be logged in.'], 401);
        }

        /* Route {id} (host-page contract) wins; body booking_id
           (legacy contract) is the fallback. is_numeric + int cast
           exactly like legacy / AcceptBookingController. */
        $rawId = $id > 0 ? (string) $id : (string) $request->request->get('booking_id', '');
        $bookingId = is_numeric($rawId) ? (int) $rawId : 0;

        if ($bookingId <= 0) {
            return $this->json(['success' => false, 'message' => 'Missing or invalid booking id.'], 400);
        }

        /* -----------------------------------------------------
           TRANSACTION — FOR UPDATE row lock + full refund + commit
        ----------------------------------------------------- */
        $tenantId    = 0;
        $refund      = 0.0;
        $hostName    = 'The host';
        $listingTitle = '';

        $this->db->beginTransaction();

        try {
            /* FOR UPDATE locks this row — a double-click or a race with
               an accept click on the same booking can't both go through. */
            $booking = $this->db->executeQuery(
                "SELECT b.id, b.status, b.amount_paid,
                        b.user_id AS tenant_id,
                        l.user_id AS host_id, l.title AS listing_title
                   FROM bookings b
                   JOIN listings l ON l.id = b.listing_id
                  WHERE b.id = :booking_id
                    FOR UPDATE",
                ['booking_id' => $bookingId]
            )->fetchAssociative();

            if ($booking === false) {
                $this->db->rollBack();
                return $this->json(['success' => false, 'message' => 'Application not found.'], 404);
            }

            /* Only the host who owns the listing behind this booking
               may reject it. */
            if ((int) $booking['host_id'] !== $userId) {
                $this->db->rollBack();
                return $this->json(['success' => false, 'message' => 'You do not have permission to update this application.'], 403);
            }

            /* ...and only while it's still pending. A confirmed booking
               has already paid the host's share out and can never be
               rejected. */
            if ((string) $booking['status'] !== 'pending') {
                $this->db->rollBack();
                return $this->json(['success' => false, 'message' => 'This application has already been decided.']);
            }

            /* FULL refund — the reserve promise the Pending Tenants
               confirm dialog makes to the guest. Nothing paid →
               nothing refunded. */
            $amountPaid = (float) $booking['amount_paid'];
            $refund     = $amountPaid > 0.005 ? round($amountPaid, 2) : 0.0;

            $affected = $this->db->executeStatement(
                "UPDATE bookings
                    SET status = 'rejected',
                        refunded_amount = :refund,
                        refunded_at = :refunded_at
                  WHERE id = :booking_id AND status = 'pending'",
                [
                    'refund'      => $refund,
                    'refunded_at' => $refund > 0 ? date('Y-m-d H:i:s') : null,
                    'booking_id'  => $bookingId,
                ]
            );

            if ($affected === 0) {
                $this->db->rollBack();
                return $this->json(['success' => false, 'message' => 'This application has already been decided.']);
            }

            /* Credit the guest's wallet with the full refund — wallet
               row only, no ledger entry (legacy convention, matched
               1:1). */
            $tenantId = (int) $booking['tenant_id'];

            if ($refund > 0 && $tenantId > 0) {
                $this->db->executeStatement(
                    'UPDATE users SET wallet_balance = wallet_balance + :amount WHERE id = :user_id',
                    [
                        'amount'  => $refund,
                        'user_id' => $tenantId,
                    ]
                );
            }

            $hostName     = $this->fetchUserName($userId);
            $listingTitle = (string) $booking['listing_title'];

            $this->db->commit();

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log('reject-booking: ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Could not reject this application. Please try again.'], 500);
        }

        /* -----------------------------------------------------
           NOTIFY THE GUEST (after the COMMIT; failure here can
           never affect the rejection above).
        ----------------------------------------------------- */
        try {
            if ($tenantId > 0) {
                $message = $hostName . ' rejected your booking request for "' . $listingTitle . '".';
                if ($refund > 0) {
                    /* Real ₱ character — the dump's notification rows
                       store the raw UTF-8 peso sign, never the entity. */
                    $message .= ' Your ₱' . number_format($refund, 2) . ' refund has been credited to your wallet.';
                }

                $this->db->insert('notifications', [
                    'user_id'    => $tenantId,
                    'message'    => mb_substr($message, 0, 240),
                    'link'       => '/bookings',
                    'is_read'    => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('reject-booking guest notification failed: ' . $e->getMessage());
        }

        return $this->json(['success' => true]);
    }

    /**
     * Host display name for the guest notification — the DB is the
     * source of truth (same fallback rule as
     * AcceptBookingController::fetchUserName).
     */
    private function fetchUserName(int $userId): string
    {
        try {
            $name = $this->db->fetchOne('SELECT name FROM users WHERE id = :id', ['id' => $userId]);
            return ($name === false || (string) $name === '') ? 'The host' : (string) $name;
        } catch (\Throwable) {
            return 'The host';
        }
    }
}
