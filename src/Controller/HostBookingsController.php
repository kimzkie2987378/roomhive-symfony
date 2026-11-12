<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › My Bookings (VIEW-ONLY).
 * 1:1 port of legacy host/hostbookings.php.
 *
 *   GET /host/bookings  → every booking on the host's own listings
 *     (legacy query, verbatim): cover photo + listing title/location,
 *     guest name, dates strip, guests count, "Booked {date}" line,
 *     status badge, payment pill (Fully Paid / Advance Paid · ₱N
 *     left / No Payment Yet), signature status (Signed / Not signed
 *     yet / Auto-completes after checkout / Long Term · complete
 *     manually), host payout amount, and the actions row
 *     (View Receipt when paid, Mark Completed on confirmed rows,
 *     View Details, kebab). Tabs: all/pending/confirmed/completed/
 *     cancelled/rejected. Live search hits guest name, listing
 *     title, location. Stat pills: Total Bookings, Confirmed
 *     Stays, Pending Requests.
 *
 *   VIEW-ONLY (matches the legacy file as it stands today): there
 *     is NO accept/decline on this page — pending applications are
 *     accepted (with signature) from the Pending Tenants page.
 *     Confirmed stays can be marked completed manually here (the
 *     "Mark Completed" floating confirm card), and confirmed stays
 *     past their checkout date also auto-complete on page load
 *     (the stay-completion sweep below).
 *
 * HIVE CLUB — STAY-COMPLETION SWEEP (1:1 of legacy
 *   config/booking_autocomplete.php, lazy/best-effort):
 *     on every page load, confirmed bookings past their checkout
 *     date are flipped to 'completed'. The legacy file also awards
 *     Hive Club points to the guest (1 pt per ₱10, ledger-guarded,
 *     idempotent) — that ledger logic lives in a separate legacy
 *     file that hasn't been pasted yet, so the point-award is a
 *     TODO here. The status flip itself runs as-is; on failure the
 *     sweep silently no-ops (it must never break the page render).
 *
 * MARK COMPLETED ENDPOINT:
 *   POST /host/bookings/{id}/complete  (legacy booking/complete-booking.php
 *                                       — body booking_id; JSON {success} |
 *                                       {message}). Confirms ownership +
 *     status before flipping; the points award is the same TODO
 *     as the sweep. Same URL/JSON shape as the accept/reject
 *     endpoints HostListingsController already documents.
 *
 * RECEIPT URL: /payment/confirmation/{id}  (already ported in
 *   PaymentController, route app_payment_confirmation — same URL
 *   Pending Tenants uses).
 *
 * VIEW DETAILS URL: /booking/details/{id}  (TODO route — legacy
 *   booking/booking-details.php has not been pasted; the link
 *   404s until that file is ported, same situation as the Edit
 *   Listing link on My Listings).
 *
 * FLASHES:
 *   • ?msg=accepted|declined|error   — kept verbatim from legacy
 *     (the accept/decline flows live on Pending Tenants now, but
 *     anything that still redirects back with one of these
 *     messages renders here, harmless).
 *   • Session flash: $session->get('rh_flash') = ['type','message']
 *     is consumed once and cleared — establishes the same
 *     hp_flash_set/take pattern legacy used everywhere; future
 *     host pages can set 'rh_flash' before redirecting here.
 *
 * Auth: same guard as HostListingsController / HostPendingTenantsController
 *   — logged_in + user_id (else app_login), then is_host = 1
 *   (else /host/become-a-host).
 */
class HostBookingsController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/host/bookings', name: 'app_host_bookings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostListingsController) ---- */
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
           HIVE CLUB — stay-completion sweep (lazy, best-effort).
           Flips stale confirmed stays past checkout to 'completed'.
           Points award is a TODO pending port of the legacy ledger.
        ========================================================= */
        $this->runStayCompletionSweep($userId);

        /* =========================================================
           BADGE COUNTS — navbar bell + sidebar pending tenants.
        ========================================================= */
        $notificationCount   = $this->countUnreadNotifications($userId);
        $pendingTenantsCount  = $this->countPendingTenants($userId);

        /* ---- Host array (mirrors legacy $host from host_init) ---- */
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
           BOOKINGS — legacy query, verbatim.
        ========================================================= */
        $bookings = $this->db->fetchAllAssociative(
            "SELECT
                b.id,
                b.status,
                b.total,
                b.amount_paid,
                b.host_payout_amount,
                b.checkin_date,
                b.checkout_date,
                b.guests,
                b.created_at,
                l.title    AS listing_title,
                l.location AS listing_location,
                u.name     AS guest_name,
                (SELECT lp.photo_path
                 FROM listing_photos lp
                 WHERE lp.listing_id = l.id AND lp.photo_type = 'cover'
                 ORDER BY lp.created_at ASC
                 LIMIT 1) AS cover_photo
             FROM bookings b
             INNER JOIN listings l ON l.id = b.listing_id
             INNER JOIN users u    ON u.id = b.user_id
             WHERE l.user_id = :host_id
             ORDER BY b.created_at DESC",
            ['host_id' => $userId]
        );

        /* ---- Status meta map (1:1 with legacy $statusMeta) ---- */
        $statusMeta = [
            'pending'   => ['label' => 'Pending Approval', 'class' => 'hp-badge-yellow'],
            'confirmed' => ['label' => 'Confirmed',        'class' => 'hp-badge-green'],
            'completed' => ['label' => 'Completed',        'class' => 'hp-badge-blue'],
            'cancelled' => ['label' => 'Cancelled',        'class' => 'hp-badge-red'],
            'rejected'  => ['label' => 'Declined',         'class' => 'hp-badge-red'],
        ];

        /* ---- Counts by status (1:1 with legacy counters) ---- */
        $total     = count($bookings);
        $pending   = 0;
        $confirmed = 0;
        $completed = 0;
        $cancelled = 0;
        $rejected  = 0;
        foreach ($bookings as $b) {
            switch ((string) $b['status']) {
                case 'pending':   $pending++;   break;
                case 'confirmed': $confirmed++; break;
                case 'completed': $completed++; break;
                case 'cancelled': $cancelled++; break;
                case 'rejected':  $rejected++;  break;
            }
        }

        /* ---- Tab filter (?filter=all|pending|confirmed|completed|cancelled|rejected) ---- */
        $filter = (string) ($request->query->get('filter', 'all'));
        if (!in_array($filter, ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'rejected'], true)) {
            $filter = 'all';
        }

        /* ---- Live search (?q= matches guest name, listing title, location) ---- */
        $q = trim((string) ($request->query->get('q', '')));

        /* ---- Build display rows + apply tab filter + search inline ---- */
        $rows = [];
        foreach ($bookings as $b) {
            $status = (string) $b['status'];

            /* Tab filter */
            if ($filter !== 'all' && $status !== $filter) {
                continue;
            }

            /* Live search */
            if ($q !== '') {
                $haystack = mb_strtolower(
                    (string) $b['guest_name'] . ' ' .
                    (string) $b['listing_title'] . ' ' .
                    (string) $b['listing_location']
                );
                if (mb_strpos($haystack, mb_strtolower($q)) === false) {
                    continue;
                }
            }

            $meta = $statusMeta[$status] ?? ['label' => ucfirst($status), 'class' => 'hp-badge-yellow'];

            /* Host net payout, falling back to what the guest paid */
            $amount = ($b['host_payout_amount'] !== null && $b['host_payout_amount'] !== '')
                ? (float) $b['host_payout_amount']
                : ($b['amount_paid'] !== null && $b['amount_paid'] !== ''
                    ? (float) $b['amount_paid']
                    : null);

            $imageSrc = $this->resolvePhoto(
                (string) ($b['cover_photo'] ?? ''),
                '/images/ListingPlaceholder.png'
            );

            $guestsNum = (int) ($b['guests'] ?? 0);

            /* ---- Payment state (same logic as the receipt) ---- */
            $bookingTotal = (float) ($b['total'] ?? 0);
            $paid         = (float) ($b['amount_paid'] ?? 0);
            $left         = max(0, round($bookingTotal - $paid, 2));

            if ($paid > 0.005 && $left <= 0.005) {
                $payState = 'full';
                $payLabel = 'Fully Paid';
                $payClass = 'hb-pay-full';
            } elseif ($paid > 0.005) {
                $payState = 'advance';
                $payLabel = 'Advance Paid · ₱' . number_format($left, 2) . ' left';
                $payClass = 'hb-pay-advance';
            } else {
                $payState = 'none';
                $payLabel = 'No Payment Yet';
                $payClass = 'hb-pay-none';
            }

            /* ---- Receipt URL — only when the guest actually paid ---- */
            $receiptUrl = $paid > 0.005
                ? '/payment/confirmation/' . (int) $b['id']
                : '';

            /* ---- Signature status (saved by sign-receipt.php) ----
               Legacy checked /webprogg/uploads/signatures/booking-N.png.
               The Symfony port drops the webprogg/ prefix everywhere —
               signatures live in public/uploads/signatures/. */
            $sigPath = rtrim($this->getParameter('kernel.project_dir'), '/')
                     . '/public/uploads/signatures/booking-' . (int) $b['id'] . '.png';
            $signed = is_file($sigPath);

            /* ---- Confirmed-stay hint (auto-complete vs long-term) ---- */
            $confirmedHint = '';
            if ($status === 'confirmed') {
                $confirmedHint = empty($b['checkout_date'])
                    ? 'Long Term · complete manually'
                    : 'Auto-completes after checkout';
            }

            /* ---- Date display strings ---- */
            $checkinDisplay  = !empty($b['checkin_date'])
                ? date('M j, Y', strtotime((string) $b['checkin_date']))
                : '';
            $checkoutDisplay  = !empty($b['checkout_date'])
                ? date('M j, Y', strtotime((string) $b['checkout_date']))
                : '';
            $createdDisplay   = !empty($b['created_at'])
                ? date('M j, Y', strtotime((string) $b['created_at']))
                : '';

            $rows[] = [
                'id'               => (int) $b['id'],
                'status'           => $status,
                'status_label'     => $meta['label'],
                'status_class'     => $meta['class'],
                'listing_title'    => (string) $b['listing_title'],
                'listing_location' => (string) $b['listing_location'],
                'guest_name'       => (string) $b['guest_name'],
                'guests'           => $guestsNum,
                'checkin_display'  => $checkinDisplay,
                'checkout_display' => $checkoutDisplay,
                'created_display'  => $createdDisplay,
                'amount'           => $amount,
                'image_src'        => $imageSrc,
                'pay_state'        => $payState,
                'pay_label'        => $payLabel,
                'pay_class'        => $payClass,
                'receipt_url'      => $receiptUrl,
                'signed'           => $signed,
                'confirmed_hint'   => $confirmedHint,
                'details_url'      => '/booking/details/' . (int) $b['id'],
            ];
        }

        /* =========================================================
           FLASHES
           ?msg=accepted|declined|error  — kept verbatim from legacy
           (the accept/decline flows now live on Pending Tenants;
           these only render if something redirects back with one).
           Session flash (rh_flash) — consumed once, then cleared.
        ========================================================= */
        $msgKey   = (string) ($request->query->get('msg', ''));
        $flashText = [
            'accepted' => ['type' => 'success', 'text' => 'Booking accepted.'],
            'declined' => ['type' => 'success', 'text' => 'Booking declined.'],
            'error'    => ['type' => 'error',   'text' => 'That booking could not be updated. It may have already been handled.'],
        ][$msgKey] ?? null;

        $sessionFlash = null;
        $sf = $session->get('rh_flash');
        if (is_array($sf) && !empty($sf['message'])) {
            $sessionFlash = [
                'type'    => (string) ($sf['type'] ?? 'success'),
                'message' => (string) $sf['message'],
            ];
            $session->remove('rh_flash');
        }

        return $this->render('host/bookings.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'bookings'              => $rows,
            'total'                 => $total,
            'pending'               => $pending,
            'confirmed'             => $confirmed,
            'completed'             => $completed,
            'cancelled'             => $cancelled,
            'rejected'              => $rejected,
            'filter'                => $filter,
            'q'                     => $q,
            'flash_text'            => $flashText,
            'session_flash'         => $sessionFlash,
        ]);
    }

    /* =====================================================================
     *  POST /host/bookings/{id}/complete  — Mark Completed endpoint
     *  (1:1 of legacy booking/complete-booking.php).
     *
     *  Confirms the booking belongs to a listing owned by the logged-in
     *  host AND is currently 'confirmed' before flipping to 'completed'.
     *  The legacy Hive Club point award is a TODO pending port of the
     *  ledger file — it ran idempotently here in legacy.
     *
     *  Body: booking_id (form-encoded, kept for parity with the accept/
     *  reject endpoints; the route param is the source of truth).
     *  JSON: {success:true} | {success:false,message:string}.
     * ================================================================= */

    #[Route('/host/bookings/{id}/complete', name: 'app_host_booking_complete', methods: ['POST'])]
    public function complete(int $id, Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int) $session->get('user_id');

        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(
                ['success' => false, 'message' => 'Not authenticated.'],
                401
            );
        }

        /* Ownership + status check (single row; covers both gates) */
        $booking = $this->db->fetchAssociative(
            "SELECT b.id, b.status, b.user_id AS guest_id,
                    b.total, b.amount_paid, b.checkout_date,
                    l.user_id AS host_id
               FROM bookings b
               JOIN listings l ON l.id = b.listing_id
              WHERE b.id = :id
              LIMIT 1",
            ['id' => $id]
        );

        if (!$booking) {
            return $this->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if ((int) $booking['host_id'] !== $userId) {
            return $this->json(['success' => false, 'message' => 'Not authorized.'], 403);
        }

        if ((string) $booking['status'] !== 'confirmed') {
            return $this->json(
                ['success' => false, 'message' => 'Only confirmed stays can be marked completed.'],
                400
            );
        }

        try {
            $this->db->update('bookings', ['status' => 'completed'], ['id' => $id]);

            /* TODO: Hive Club — award the guest 1 point per ₱10 of the
               booking total (ledger-guarded, idempotent). Pending port
               of legacy config/booking_autocomplete.php's point ledger. */

            return $this->json(['success' => true]);
        } catch (\Throwable $e) {
            return $this->json(
                ['success' => false, 'message' => 'Could not complete this booking.'],
                500
            );
        }
    }

    /* =====================================================================
     *  STAY-COMPLETION SWEEP — best-effort port of legacy
     *  config/booking_autocomplete.php.
     *
     *  Flips stale confirmed stays past their checkout date to
     *  'completed'. The point award is a TODO pending port of the
     *  legacy ledger; the status flip runs as-is. Silently no-ops
     *  on any DB error so the page render never breaks.
     * ================================================================= */

    private function runStayCompletionSweep(int $hostUserId): void
    {
        try {
            $stale = $this->db->fetchAllAssociative(
                "SELECT b.id
                   FROM bookings b
                   JOIN listings l ON l.id = b.listing_id
                  WHERE l.user_id = :h
                    AND b.status = 'confirmed'
                    AND b.checkout_date IS NOT NULL
                    AND b.checkout_date < CURDATE()",
                ['h' => $hostUserId]
            );

            if (empty($stale)) {
                return;
            }

            foreach ($stale as $row) {
                $this->db->update(
                    'bookings',
                    ['status' => 'completed'],
                    ['id' => (int) $row['id']]
                );
                /* TODO: Hive Club point award (ledger-guarded, idempotent). */
            }
        } catch (\Throwable $e) {
            /* Sweep is best-effort — never break the page render. */
        }
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostListingsController.
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

    private function countPendingTenants(int $hostUserId): int
    {
        try {
            return (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM bookings b
                 JOIN listings l ON l.id = b.listing_id
                 WHERE l.user_id = :h AND b.status = 'pending'",
                ['h' => $hostUserId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same as HostListingsController /
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
