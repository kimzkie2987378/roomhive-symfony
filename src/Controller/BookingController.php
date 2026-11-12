<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — BOOKING DETAILS (guest side).
 *
 * 1:1 port of legacy booking/booking-details.php — the page that opens
 * when a booking row is clicked on My Bookings (/bookings), the page the
 * receipt's "View Booking" button and every notification link point to,
 * and the page /listings/{id}/payment?pay_balance=N redirects back to
 * when the balance is already settled.
 *
 * TWO ROUTES, ONE PAGE (same pattern as AcceptBookingController):
 *   GET /booking/{id}           name app_booking_details
 *                               (the URL the My Bookings rows link to —
 *                               /booking/92 in the browser bar)
 *   GET /booking/details/{id}   name app_booking_details_legacy
 *                               (exact legacy URL — notification links
 *                               from AcceptBookingController and the
 *                               balance-mode redirect in
 *                               PaymentController already point here)
 * {id} is digits-only on both, so /booking/accept-booking and
 * /booking/process-payment (POST) can never be shadowed.
 *
 * Feeds templates/booking/details.html.twig with:
 *   b                — booking display row: id, status, payment_label,
 *                      checkin_fmt, checkout_fmt, guests, booked_fmt,
 *                      pct, pay_state (full|advance|none), show_pay,
 *                      show_cancel, show_refund, total_fmt, paid_fmt,
 *                      left_fmt, refunded_fmt
 *   listing_title / listing_location / cover / cover_url / wished
 *   host_name / host_avatar
 *
 *   wished — boolean, true when the viewer already saved this listing
 *   in their wishlist (drives the cover heart's initial glow state —
 *   glow = saved, un-glow = not saved; clicks toggle through the same
 *   POST /wishlist/toggle endpoint the My Wishlist page uses).
 *   Queried inside try/catch: if the wishlist table is missing the
 *   heart just starts un-glowed, this page can never 500 over it.
 *   is_host / nav_avatar / notification_count — shell contract
 *
 * Access rule: only the booking's owner (the guest) can view this page —
 * anyone else (including the host) is bounced to /bookings, exactly like
 * the other guest-side account pages bounce their guards.
 *
 * Display logic (same thresholds as BookingsController):
 *   paid > 0 and nothing left  → pay_state "full"    (progress 100%, no
 *                               remaining row, no Pay Balance button)
 *   paid > 0, balance left     → pay_state "advance" (Remaining Balance +
 *                               PAY BALANCE → /listings/{listing}/payment
 *                               ?pay_balance={id} — the same balance mode
 *                               the payments history page uses)
 *   nothing paid               → pay_state "none"    (same buttons, balance
 *                               mode also accepts these — remaining > 0)
 *   pending                    → amber "Application pending — {host} has
 *                               24 hours…" banner + CANCEL BOOKING form
 *   cancelled/rejected + paid  → green "Refunded to Wallet" row instead of
 *                               the remaining-balance row, no pay button
 *
 * CANCEL BOOKING posts to BookingCancelController
 * (POST /booking/cancel-booking, name app_booking_cancel) — copy that
 * file together with this one.
 *
 * REVIEW BOX (this version — added):
 *   A screen overlay pops up when the stay is FINISHED (status
 *   'completed') or CANCELLED, and only while this guest has not
 *   reviewed the listing yet:
 *     - ?review=1 (the redirect BookingCancelController adds after a
 *       successful cancel) pops the box immediately;
 *     - a completed stay pops it on the first visit — unless the guest
 *       already dismissed it (the X remembers via the session,
 *       POST /reviews/dismiss);
 *     - the manual "RATE YOUR STAY" button stays on the page either
 *       way.
 *   The box itself (5 clickable stars + comment + X) lives on
 *   booking/details.html.twig; submission POSTs /reviews/submit
 *   (ReviewSubmitController — copy ALL FOUR files together). The
 *   saved review lands on BOTH profiles automatically — the guest's
 *   /reviews page and the host's /host/reviews page read the same
 *   legacy `reviews` table (user_id + listing_id).
 *
 *   Feeds the template with: review_open (auto-pop flag),
 *   show_review (box/button eligible), already_reviewed +
 *   my_review {rating, comment, date} (the green done-row).
 *
 * Port record — booking/booking-details.php (this file). The legacy
 * source was never pasted, so the layout is matched 1:1 against the
 * live legacy screenshot and every number/label on it.
 */
class BookingController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /* =====================================================================
     |  GET /booking/{id}   ·   GET /booking/details/{id}
     | ================================================================== */
    #[Route('/booking/{id}', name: 'app_booking_details', methods: ['GET'], requirements: ['id' => '\d+'], priority: 20)]
    #[Route('/booking/details/{id}', name: 'app_booking_details_legacy', methods: ['GET'], requirements: ['id' => '\d+'], priority: 20)]
    public function show(Request $request, int $id = 0): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as BookingsController) ---- */
        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        if ($id <= 0) {
            return $this->redirectToRoute('app_bookings');
        }

        /* ---- Load the viewer row (navbar avatar + bell badge) ---- */
        $viewer = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if (!$viewer) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }

        $notificationCount = 0;
        try {
            $notificationCount = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            );
        } catch (\Throwable $e) {
            $notificationCount = 0;
        }

        $navAvatar = !empty($viewer['avatar_path'])
            ? $this->resolvePhoto((string) $viewer['avatar_path'])
            : '/images/default-avatar.png';
        if ($navAvatar === '') {
            $navAvatar = '/images/default-avatar.png';
        }

        /* =====================================================
           BOOKING + LISTING + HOST — one join, owner-guarded.
        ====================================================== */
        $row = $this->db->fetchAssociative(
            "SELECT b.id,
                    b.listing_id,
                    b.user_id,
                    b.status,
                    b.total,
                    b.amount_paid,
                    b.payment_status,
                    b.refunded_amount,
                    b.checkin_date,
                    b.checkout_date,
                    b.guests,
                    b.booked_at,
                    l.title,
                    l.location,
                    (SELECT lp.photo_path
                     FROM listing_photos lp
                     WHERE lp.listing_id = l.id AND lp.photo_type = 'cover'
                     ORDER BY lp.created_at ASC
                     LIMIT 1) AS cover_photo,
                    u.name  AS host_name,
                    u.avatar_path AS host_avatar
               FROM bookings b
               INNER JOIN listings l ON l.id = b.listing_id
               INNER JOIN users u   ON u.id = l.user_id
              WHERE b.id = :id
              LIMIT 1",
            ['id' => $id]
        );

        /* Missing booking — or someone else's booking — → back to the list */
        if (!$row || (int) $row['user_id'] !== $userId) {
            return $this->redirectToRoute('app_bookings');
        }

        /* =====================================================
           PAYMENT MATH — same thresholds as BookingsController
           / AccountController.
        ====================================================== */
        $status = (string) $row['status'];
        $total  = (float) ($row['total'] ?? 0);
        $paid   = (float) ($row['amount_paid'] ?? 0);
        $left   = round(max(0, $total - $paid), 2);

        if ($paid > 0.005 && $left <= 0.005) {
            $payState = 'full';
        } elseif ($paid > 0.005) {
            $payState = 'advance';
        } else {
            $payState = 'none';
        }

        /* Progress — % of the booking paid, capped at 100. */
        $pct = $total > 0.005 ? (int) min(100, round($paid / $total * 100)) : 0;

        /* PAY BALANCE → the listing payment page's balance mode (same
           link the payments history page carries). Balance mode itself
           re-validates ownership + status + remaining. */
        $showPay = in_array($status, ['pending', 'confirmed'], true)
            && (string) $row['payment_status'] !== 'refunded'
            && $left > 0.005;

        /* CANCEL MY BOOKING — the guest can withdraw a PENDING application
           or back out of a CONFIRMED stay (this version, per request: the
           red header button next to BACK TO MY BOOKINGS shows for both).
           The refund rule is unchanged — whatever was paid comes back to
           the wallet at the legacy 98% rate (BookingCancelController). */
        $showCancel = in_array($status, ['pending', 'confirmed'], true);

        /* Refunded display — cancelled/rejected with money paid. The
           credited figure is refunded_amount when the row has one
           (legacy booking #41: 1500 paid → 1470 credited), falling
           back to the paid amount. */
        $showRefund = in_array($status, ['cancelled', 'rejected'], true) && $paid > 0.005;
        $refunded   = (float) ($row['refunded_amount'] ?? 0);
        if ($refunded <= 0.005) {
            $refunded = $paid;
        }

        /* Payment label next to the status pill — the raw
           payment_status column, capitalized ("Paid" in the screenshot).
           Hidden while still pending so a fresh booking doesn't read
           "Pending … Pending". */
        $paymentLabel = '';
        $rawPayStatus = strtolower(trim((string) ($row['payment_status'] ?? '')));
        if ($rawPayStatus !== '' && $rawPayStatus !== 'pending') {
            $paymentLabel = ucfirst($rawPayStatus);
        }

        $fmtDate = function ($value): string {
            $value = trim((string) ($value ?? ''));
            if ($value === '' || $value === '0000-00-00') {
                return "\u{2014}";
            }
            $ts = strtotime($value);
            return $ts ? date('M j, Y', $ts) : $value;
        };

        $coverPath = trim((string) ($row['cover_photo'] ?? ''));
        $coverUrl  = $coverPath !== '' ? $this->resolvePhoto($coverPath) : '';

        $hostAvatar = !empty($row['host_avatar'])
            ? $this->resolvePhoto((string) $row['host_avatar'])
            : '';
        if ($hostAvatar === '') {
            $hostAvatar = '/images/default-avatar.png';
        }

        /* Wishlist heart state — is this listing already saved by the
           viewer? (legacy `wishlist` table, unique key uq_user_listing).
           try/catch so a missing table can never break the page. */
        $wished = false;
        try {
            $wished = (bool) $this->db->fetchOne(
                'SELECT COUNT(*) FROM wishlist WHERE user_id = :u AND listing_id = :l',
                ['u' => $userId, 'l' => (int) $row['listing_id']]
            );
        } catch (\Throwable $e) {
            $wished = false;
        }

        /* =====================================================
           REVIEW STATE — the legacy `reviews` table (user_id +
           listing_id; no booking_id column, so ONE review per guest
           per listing). The overlay is offered once a stay is
           FINISHED ('completed') or CANCELLED and this guest has
           not reviewed the listing yet. try/catch so a missing
           reviews table can never break the page (same rule as the
           wishlist heart above).
        ====================================================== */
        $myReview = null;
        try {
            $myReview = $this->db->fetchAssociative(
                'SELECT rating, comment, created_at
                   FROM reviews
                  WHERE user_id = :u AND listing_id = :l
                  ORDER BY id DESC
                  LIMIT 1',
                ['u' => $userId, 'l' => (int) $row['listing_id']]
            );
        } catch (\Throwable $e) {
            $myReview = false;
        }

        $alreadyReviewed = !empty($myReview);

        $showReview = in_array($status, ['completed', 'cancelled'], true)
            && !$alreadyReviewed;

        /* Auto-open rules:
           - ?review=1 → the guest JUST cancelled (the redirect from
             BookingCancelController) — pop the box right away;
           - a COMPLETED stay pops on the first visit, unless the guest
             already dismissed it for this booking (X button →
             /reviews/dismiss → session key below). */
        $dismissed = array_map('intval', (array) $session->get('review_dismissed_bookings', []));
        $reviewOpen = $showReview && (
            $request->query->get('review') === '1'
            || ($status === 'completed' && !in_array((int) $row['id'], $dismissed, true))
        );

        $myReviewData = null;
        if ($myReview) {
            $reviewTs = strtotime((string) $myReview['created_at']);
            $myReviewData = [
                'rating'  => (int) round((float) $myReview['rating']),
                'comment' => (string) ($myReview['comment'] ?? ''),
                'date'    => $reviewTs ? date('M j, Y', $reviewTs) : '',
            ];
        }

        $guests = trim((string) ($row['guests'] ?? ''));
        if ($guests === '') {
            $guests = "\u{2014}";
        }

        return $this->render('booking/details.html.twig', [
            'is_host'            => !empty($viewer['is_host']),
            'nav_avatar'         => $navAvatar,
            'notification_count' => $notificationCount,

            'b' => [
                'id'           => (int) $row['id'],
                'listing_id'   => (int) $row['listing_id'],
                'status'       => $status,
                'payment_label' => $paymentLabel,
                'checkin_fmt'  => $fmtDate($row['checkin_date']),
                'checkout_fmt' => $fmtDate($row['checkout_date']),
                'guests'       => $guests,
                'booked_fmt'   => $fmtDate($row['booked_at']),
                'pct'          => $pct,
                'pay_state'    => $payState,
                'show_pay'     => $showPay,
                'show_cancel'  => $showCancel,
                'show_refund'  => $showRefund,
                'total_fmt'    => number_format($total, 2),
                'paid_fmt'     => number_format($paid, 2),
                'left_fmt'     => number_format($left, 2),
                'refunded_fmt' => number_format($refunded, 2),
            ],

            'listing_title'    => (string) $row['title'],
            'listing_location' => (string) $row['location'],
            'cover'            => $coverUrl !== '',
            'cover_url'        => $coverUrl,
            'wished'           => $wished,
            'host_name'        => (string) $row['host_name'],
            'host_avatar'      => $hostAvatar,

            'review_open'      => $reviewOpen,
            'show_review'      => $showReview,
            'already_reviewed' => $alreadyReviewed,
            'my_review'        => $myReviewData,
        ]);
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same mapping as BookingsController /
     *  AccountController / AcceptBookingController pages.
     * ================================================================= */
    private function resolvePhoto(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
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
