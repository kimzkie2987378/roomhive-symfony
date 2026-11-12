<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Pending Tenants (SIGN-TO-ACCEPT).
 * 1:1 port of legacy booking/pendingtenants.php.
 *
 *   GET /host/pending-tenants   → tenants who applied for the
 *     host's spaces (bookings.status = 'pending'), as clickable
 *     cards: listing photo + title/location (map-pin), applicant
 *     row (avatar or the temporary navy/gold circle-user-round
 *     icon when no avatar is uploaded) with Verified / Not
 *     Verified pill, payment status pill (Fully Paid / Advance
 *     Paid / Pending), "View Receipt" link, dates strip and
 *     Reject / Accept buttons. Clicking a card opens the
 *     Application Details modal; Accept opens the SIGNATURE PAD
 *     (sign-to-accept) — the host must draw their signature,
 *     which is saved and stamped onto the guest's official
 *     receipt, and only then is the application accepted.
 *
 * TENANT VISIBILITY (legacy docblock):
 *   - Verified pill = same auto-verify rule as the gate: ID
 *     uploaded (non-rejected user_id_documents row) + complete
 *     profile (name, phone, age, location). is_user_verified()
 *     lived in verification_gate.php, which was never pasted —
 *     tenantVerification() below reconstructs exactly that rule;
 *     TODO: diff against the original gate when it is pasted.
 *   - personal_photo (the guest's in-person recognition photo)
 *     is shown in the details modal; the column self-heals
 *     (SHOW COLUMNS → ALTER TABLE ADD) exactly like legacy.
 *
 * SIGN-TO-ACCEPT FLOW (template JS):
 *     POST /host/bookings/{id}/sign    legacy sign-receipt.php —
 *                                      body booking_id + signature
 *                                      (canvas dataURL); JSON
 *                                      {success} | {error}/{message}
 *     POST /host/bookings/{id}/accept  legacy accept-booking.php —
 *                                      body booking_id; JSON
 *                                      {success} | {message}
 *     both are TODO routes (the legacy endpoint files have not
 *     been pasted yet) — same situation as the accept/reject
 *     endpoints HostListingsController already documents. This
 *     page deliberately contains NO booking-mutation logic.
 *   After accept succeeds the page redirects to
 *     /payment/confirmation/{id}  (legacy payment-confirmation.php
 *     ?id=N — already ported in PaymentController,
 *     route app_payment_confirmation).
 *
 * Auth: same guard as HostProfileController / HostListingsController
 *   — logged_in + user_id (else app_login), then is_host = 1
 *   (else /host/become-a-host).
 *
 * NO POST HANDLING HERE — the legacy page renders markup only;
 *   every action is a fetch to its own endpoint.
 */
class HostPendingTenantsController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /* Legacy's pending-applications query — verbatim (JOIN listing +
       tenant, LEFT JOIN cover photo only). */
    private const PENDING_SQL = <<<SQL
        SELECT b.id AS booking_id, b.total, COALESCE(b.amount_paid, 0) AS amount_paid,
               b.booked_at, b.paid_at,
               b.checkin_date, b.checkout_date, b.guests,
               l.id AS listing_id, l.title AS listing_title, l.location AS listing_location, l.price,
               p.photo_path AS cover_photo,
               u.id AS tenant_id, u.name AS tenant_name, u.email AS tenant_email,
               u.avatar_path AS tenant_avatar, u.personal_photo
         FROM bookings b
         JOIN listings l ON l.id = b.listing_id
         JOIN users u ON u.id = b.user_id
         LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.photo_type = 'cover'
         WHERE l.user_id = :id AND b.status = 'pending'
         ORDER BY b.booked_at DESC
        SQL;

    #[Route('/host/pending-tenants', name: 'app_host_pending_tenants', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostProfileController) ---- */
        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* ---- Load the user row ---- */
        $dbUser = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host, created_at
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if (!$dbUser) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }

        /* ---- HOST GUARD — non-hosts go to Become a Host ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* =========================================================
           BADGE COUNTS — unread notifications computed ONCE (legacy
           $notification_count + $hostNotifUnread), shared by the
           navbar badge and the sidebar badge.
        ========================================================= */
        $notificationCount = $this->countUnreadNotifications($userId);

        /* ---- Host array (mirrors legacy $host) ---- */
        $host = [
            'name'         => (string) $dbUser['name'],
            'avatar'       => !empty($dbUser['avatar_path'])
                ? $this->resolvePhoto((string) $dbUser['avatar_path'], '/images/default-avatar.png')
                : '/images/default-avatar.png',
            'member_since' => date('F Y', strtotime((string) $dbUser['created_at'])),
            'location'     => '',
            'email'        => (string) $dbUser['email'],
            'phone'        => '',
            'age'          => '',
            'about'        => '',
        ];

        /* =========================================================
           PENDING APPLICATIONS — legacy query; if the users.
           personal_photo column is missing, self-heal (SHOW
           COLUMNS → ALTER TABLE ADD) and retry, exactly like the
           legacy try/catch.
        ========================================================= */
        try {
            $pendingApplications = $this->db->fetchAllAssociative(self::PENDING_SQL, ['id' => $userId]);
        } catch (\Throwable $e) {
            $pendingApplications = [];
            try {
                $ppCol = $this->db->fetchOne("SHOW COLUMNS FROM users LIKE 'personal_photo'");
                if (!$ppCol) {
                    $this->db->executeStatement(
                        'ALTER TABLE users ADD COLUMN personal_photo VARCHAR(255) NULL'
                    );
                }
                $pendingApplications = $this->db->fetchAllAssociative(self::PENDING_SQL, ['id' => $userId]);
            } catch (\Throwable $e2) {
                error_log('pending-tenants: query failed: ' . $e2->getMessage());
            }
        }

        /* =========================================================
           NORMALIZE ROWS — verification + personal photo per
           applicant, then the legacy pt_* label/breakdown helpers.
        ========================================================= */
        $rows = [];
        foreach ($pendingApplications as $app) {
            $bookingId = (int) $app['booking_id'];

            $ver = $this->tenantVerification((int) $app['tenant_id']);

            $checkin  = (string) ($app['checkin_date'] ?? '');
            $checkout = (string) ($app['checkout_date'] ?? '');
            $payment  = $this->paymentBreakdown(
                (float) $app['total'],
                (float) ($app['amount_paid'] ?? 0)
            );

            /* ★ TEMP PROFILE — real avatar vs temporary icon. A
               "real" avatar is any non-empty path that isn't
               default-avatar.png (legacy $tenantHasAvatar). */
            $tenantAvatarRaw = trim((string) ($app['tenant_avatar'] ?? ''));
            $tenantHasAvatar = $tenantAvatarRaw !== ''
                && stripos($tenantAvatarRaw, 'default-avatar') === false;

            /* Guest personal photo — resolved only when present
               (empty keeps the modal section hidden). */
            $personal = trim((string) ($ver['personal_photo'] ?? ''));
            $personalUrl = $personal !== ''
                ? $this->resolvePhoto($personal, '')
                : '';

            $rows[] = [
                'booking_id'        => $bookingId,
                'booking_ref'       => str_pad((string) $bookingId, 6, '0', STR_PAD_LEFT),
                'listing_id'        => (int) $app['listing_id'],
                'listing_title'     => (string) $app['listing_title'],
                'listing_location'  => (string) $app['listing_location'],
                'listing_photo'     => $this->resolvePhoto(
                    (string) ($app['cover_photo'] ?? ''),
                    '/images/ListingPlaceholder.png'
                ),
                'tenant_name'       => (string) $app['tenant_name'],
                'tenant_email'      => (string) $app['tenant_email'],
                'tenant_avatar'     => $this->resolvePhoto($tenantAvatarRaw, '/images/default-avatar.png'),
                'tenant_has_avatar' => $tenantHasAvatar,
                'tenant_personal'   => $personalUrl,
                'tenant_verified'   => (bool) $ver['verified'],
                'checkin_label'     => $this->checkinLabel($checkin),
                'checkout_label'    => $this->checkoutLabel($checkin, $checkout),
                'date_range'        => $this->dateRangeLabel($checkin, $checkout),
                'payment_status'    => $payment['status_label'],
                'payment_class'     => $payment['status_class'],
                'payment_time'      => $this->paymentTimeLabel((string) ($app['paid_at'] ?? '')),
                'guests'            => ($app['guests'] !== null && $app['guests'] !== '')
                    ? (string) $app['guests']
                    : 'Not specified',
                'applied'           => date('M j, Y', strtotime((string) $app['booked_at'])),
                'total'             => number_format((float) $app['total'], 2),
                'paid'              => $payment['amount_paid_fmt'],
                'balance'           => number_format($payment['balance_due'], 2),
                'receipt_url'       => $payment['payment_state'] !== 'none'
                    ? '/payment/confirmation/' . $bookingId
                    : '',
            ];
        }

        return $this->render('host/pending-tenants.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => count($rows),
            'is_host'               => true,

            /* Page data */
            'applications'          => $rows,
        ]);
    }

    /* =====================================================================
     *  TENANT VERIFICATION — reconstruction of verification_gate.php's
     *  is_user_verified() contract: VERIFIED = ID uploaded (a non-
     *  rejected user_id_documents row exists) + complete profile
     *  (name, phone, age, location all filled). Everything is guarded
     *  like the legacy try/catch — a missing column or table can only
     *  downgrade to "Not Verified", never break the page.
     *  TODO: diff against the original gate file when it is pasted.
     * ================================================================= */

    private function tenantVerification(int $tenantId): array
    {
        $out = [
            'verified'       => false,
            'has_id'         => false,
            'id_status'      => null,
            'personal_photo' => '',
        ];

        try {
            $idStatus = $this->db->fetchOne(
                "SELECT status FROM user_id_documents
                 WHERE user_id = :u AND status != 'rejected'
                 ORDER BY uploaded_at DESC
                 LIMIT 1",
                ['u' => $tenantId]
            );
            $out['id_status'] = $idStatus ? (string) $idStatus : null;
            $out['has_id']    = (bool) $out['id_status'];
        } catch (\Throwable $e) {
            /* guarded — legacy swallowed PDOException too */
        }

        try {
            $out['personal_photo'] = trim((string) $this->db->fetchOne(
                'SELECT personal_photo FROM users WHERE id = :u LIMIT 1',
                ['u' => $tenantId]
            ));
        } catch (\Throwable $e) {
            /* guarded */
        }

        try {
            $u = $this->db->fetchAssociative(
                'SELECT name, phone, age, location FROM users WHERE id = :u LIMIT 1',
                ['u' => $tenantId]
            );
            $complete = $u
                && trim((string) $u['name']) !== ''
                && trim((string) $u['phone']) !== ''
                && trim((string) $u['age']) !== ''
                && trim((string) $u['location']) !== '';
            $out['verified'] = $out['has_id'] && $complete;
        } catch (\Throwable $e) {
            /* guarded */
        }

        return $out;
    }

    /* =====================================================================
     *  DATE / PAYMENT LABELS — 1:1 ports of the legacy pt_* helpers.
     * ================================================================= */

    private function checkinLabel(string $checkin): string
    {
        return $checkin !== '' ? date('F j, Y', strtotime($checkin)) : 'Not specified';
    }

    private function checkoutLabel(string $checkin, string $checkout): string
    {
        if ($checkout !== '') {
            return date('F j, Y', strtotime($checkout));
        }
        return $checkin !== '' ? 'Long Term' : 'Not specified';
    }

    private function dateRangeLabel(string $checkin, string $checkout): string
    {
        if ($checkin === '') {
            return 'Not specified';
        }
        return date('F j, Y', strtotime($checkin)) . ' - ' . $this->checkoutLabel($checkin, $checkout);
    }

    private function paymentTimeLabel(string $paidAt): string
    {
        return $paidAt !== '' ? date('M j, Y, g:i A', strtotime($paidAt)) : 'Not paid yet';
    }

    /* pt_payment_breakdown() — verbatim logic (Fully Paid /
       Advance Paid · ₱X left / ₱X Pending). */
    private function paymentBreakdown(float $total, float $amountPaid): array
    {
        $totalAmount = $total;
        $amountPaid  = (float) $amountPaid;
        $balanceDue  = max(0, round($totalAmount - $amountPaid, 2));
        $hasPayment  = ($amountPaid > 0.005);
        $isFullyPaid = ($hasPayment && $balanceDue <= 0.005);

        if ($isFullyPaid) {
            $state        = 'full';
            $status_label = 'Fully Paid';
            $status_class = 'pt-payment-paid';
        } elseif ($hasPayment) {
            $state        = 'advance';
            $status_label = 'Advance Paid · ₱' . number_format($balanceDue, 2) . ' left';
            $status_class = 'pt-payment-pending';
        } else {
            $state        = 'none';
            $status_label = '₱' . number_format($balanceDue, 2) . ' Pending';
            $status_class = 'pt-payment-pending';
        }

        return [
            'balance_due'     => $balanceDue,
            'is_fully_paid'   => $isFullyPaid,
            'payment_state'   => $state,
            'amount_paid_fmt' => number_format($amountPaid, 2),
            'status_label'    => $status_label,
            'status_class'    => $status_class,
        ];
    }

    /* =====================================================================
     *  BADGE COUNT — same implementation as HostProfileController /
     *  HostListingsController.
     * ================================================================= */

    private function countUnreadNotifications(int $userId): int
    {
        try {
            return (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — verbatim same as HostListingsController /
     *  HostProfileController / AdminController / AccountController.
     * ================================================================= */

    private function resolvePhoto(string $path, string $fallback = '/images/ListingPlaceholder.png'): string
    {
        $path = trim($path);
        if ($path === '') {
            return $fallback;
        }
        if (preg_match('#^(https?://|/)#i', $path)) {
            return $path;
        }
        $path = str_replace('\\', '/', ltrim($path, '/'));
        if (stripos($path, 'webprogg/') === 0) {
            $path = substr($path, strlen('webprogg/'));
        }
        if (stripos($path, 'host/') === 0) {
            $path = substr($path, strlen('host/'));
            return '/uploads/host/' . $path;
        }
        if (stripos($path, 'uploads/') === 0) {
            return '/' . $path;
        }
        if (stripos($path, 'cover_') === 0) {
            return '/uploads/listing_photos/cover/' . basename($path);
        }
        return '/uploads/listing_photos/' . $path;
    }
}
