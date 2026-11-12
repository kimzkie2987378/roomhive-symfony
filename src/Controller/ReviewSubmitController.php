<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — SUBMIT A REVIEW (guest side, JSON endpoints).
 *
 * 1:1 rebuild of legacy booking/submit-review.php — that file itself
 * was never pasted, so this port is reconstructed around the hard
 * evidence in the RoomHive DB dump:
 *
 *   `reviews` table — id · user_id · listing_id · rating
 *   decimal(2,1) · comment text · created_at timestamp. There is NO
 *   booking_id column, so the legacy identity rule is kept 1:1:
 *   ONE review per guest per listing (the guest's /reviews page and
 *   the host's /host/reviews page both render exactly that shape,
 *   and the listing page's average stars read the same rows).
 *
 * WHEN THE BOX APPEARS (booking_details.html.twig overlay):
 *   - the guest CANCELS a still-pending application —
 *     BookingCancelController redirects back with ?review=1;
 *   - the guest opens a COMPLETED stay (the host pressed Mark
 *     Completed, or the after-checkout sweep auto-completed it)
 *     and has not reviewed the listing yet.
 *   The X button (or "Not now") POSTs /reviews/dismiss which
 *   remembers the closed box in the session so a completed stay is
 *   never auto-nagged twice — the page keeps a manual
 *   "RATE YOUR STAY" button that re-opens the box any time.
 *
 *   POST /reviews/submit    name app_review_submit
 *        body: booking_id · rating (1-5) · comment (optional)
 *        → { success:true, message, review:{rating,comment,date} }
 *   POST /reviews/dismiss   name app_review_dismiss
 *        body: booking_id
 *        → { success:true }
 *
 * GUARDS (submit runs inside one transaction):
 *   - logged in (401 JSON);
 *   - booking exists AND belongs to the caller (404 — no leakage);
 *   - status IN ('completed','cancelled') only (409) — a live
 *     pending/confirmed stay cannot be rated;
 *   - rating must be 1..5 (400);
 *   - one review per (user_id, listing_id) (409).
 *
 * Comment: trimmed, hard-capped at 1000 chars (mb-safe), optional —
 * the legacy comment column is nullable.
 *
 * Wallet-style convention: the INSERT commits FIRST, the host
 * notification runs AFTER the commit inside its own try/catch — a
 * failure there can never affect the saved review (same rule as
 * BookingCancelController / AcceptBookingController /
 * ProcessPaymentController::notifyHost).
 */
final class ReviewSubmitController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /* =====================================================================
     |  POST /reviews/submit — save stars + comment for a finished
     |  (completed) or cancelled stay.
     | ================================================================== */
    #[Route('/reviews/submit', name: 'app_review_submit', methods: ['POST'], priority: 20)]
    public function submit(Request $request): Response
    {
        $session = $request->getSession();

        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        /* ---- Input — is_numeric + int cast exactly like the other
           POST endpoints (AcceptBookingController / BookingCancel). ---- */
        $rawBooking = (string) $request->request->get('booking_id', '');
        $bookingId  = is_numeric($rawBooking) ? (int) $rawBooking : 0;

        $rawRating = (string) $request->request->get('rating', '');
        $rating    = is_numeric($rawRating) ? (int) $rawRating : 0;

        $comment = trim((string) $request->request->get('comment', ''));
        if (mb_strlen($comment) > 1000) {
            $comment = mb_substr($comment, 0, 1000);
        }

        if ($bookingId <= 0) {
            return $this->json(['success' => false, 'message' => 'Missing booking.'], 400);
        }
        if ($rating < 1 || $rating > 5) {
            return $this->json(['success' => false, 'message' => 'Tap a star to rate first.'], 400);
        }

        /* ---- Booking + listing gate ---- */
        $booking = $this->db->fetchAssociative(
            "SELECT b.id,
                    b.user_id,
                    b.status,
                    l.id   AS listing_id,
                    l.title,
                    l.user_id AS host_id
               FROM bookings b
               JOIN listings l ON l.id = b.listing_id
              WHERE b.id = :id
              LIMIT 1",
            ['id' => $bookingId]
        );

        if ($booking === false || (int) $booking['user_id'] !== $userId) {
            return $this->json(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        if (!in_array((string) $booking['status'], ['completed', 'cancelled'], true)) {
            return $this->json([
                'success' => false,
                'message' => 'You can rate a stay once it is completed or cancelled.',
            ], 409);
        }

        $listingId = (int) $booking['listing_id'];
        $listingTitle = (string) $booking['title'];
        $hostId = (int) $booking['host_id'];

        /* -----------------------------------------------------
           TRANSACTION — duplicate guard + INSERT + commit
        ----------------------------------------------------- */
        $this->db->beginTransaction();

        try {
            /* ONE review per guest per listing (legacy identity rule —
               the reviews table has no booking_id column). A double-click
               or a stale box lands here as a friendly 409. */
            $exists = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM reviews WHERE user_id = :u AND listing_id = :l',
                ['u' => $userId, 'l' => $listingId]
            );

            if ($exists > 0) {
                $this->db->rollBack();
                return $this->json([
                    'success' => false,
                    'message' => 'You already reviewed this stay.',
                ], 409);
            }

            $this->db->insert('reviews', [
                'user_id'    => $userId,
                'listing_id' => $listingId,
                'rating'     => (float) $rating,
                'comment'    => $comment !== '' ? $comment : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log('review-submit: ' . $e->getMessage());
            return $this->json([
                'success' => false,
                'message' => 'Could not save your review. Please try again.',
            ], 500);
        }

        /* -----------------------------------------------------
           NOTIFY THE HOST (after the COMMIT; failure here can
           never affect the saved review).
        ----------------------------------------------------- */
        try {
            if ($hostId > 0) {
                $guestName = $this->fetchUserName($userId);
                $message   = $guestName . ' left a ' . $rating . '-star review on "' . $listingTitle . '".';
                $this->db->insert('notifications', [
                    'user_id'    => $hostId,
                    'message'    => mb_substr($message, 0, 240),
                    'link'       => '/host/reviews',
                    'is_read'    => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('review-submit host notification failed: ' . $e->getMessage());
        }

        /* ---- Success — the JS swaps in the thanks panel and turns
           the page strip into the green "You rated this stay" row. ---- */
        return $this->json([
            'success' => true,
            'message' => 'Thanks! Your review is now on the host\'s profile and your Reviews page.',
            'review'  => [
                'rating'  => $rating,
                'comment' => $comment,
                'date'    => date('M j, Y'),
            ],
        ]);
    }

    /* =====================================================================
     |  POST /reviews/dismiss — the X button ("don't want to rate").
     |  Remembers the booking in the session so the popup never
     |  auto-opens twice for the same completed stay. Nothing is
     |  written to the DB — the guest can still open the box with the
     |  page's RATE YOUR STAY button, and a dismissed booking simply
     |  loses the auto-open.
     | ================================================================== */
    #[Route('/reviews/dismiss', name: 'app_review_dismiss', methods: ['POST'], priority: 20)]
    public function dismiss(Request $request): Response
    {
        $session = $request->getSession();

        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $raw       = (string) $request->request->get('booking_id', '');
        $bookingId = is_numeric($raw) ? (int) $raw : 0;

        if ($bookingId <= 0) {
            return $this->json(['success' => false, 'message' => 'Missing booking.'], 400);
        }

        $dismissed = array_map('intval', (array) $session->get('review_dismissed_bookings', []));

        if (!in_array($bookingId, $dismissed, true)) {
            $dismissed[] = $bookingId;
        }

        /* Session hygiene — keep only the most recent skips so the
           list can never grow unbounded across a long session. */
        if (count($dismissed) > 50) {
            $dismissed = array_slice($dismissed, -50);
        }

        $session->set('review_dismissed_bookings', $dismissed);

        return $this->json(['success' => true]);
    }

    /**
     * Guest display name for the host notification — the DB is the
     * source of truth (same fallback rule as BookingCancelController
     * / AcceptBookingController).
     */
    private function fetchUserName(int $userId): string
    {
        try {
            $name = $this->db->fetchOne('SELECT name FROM users WHERE id = :id', ['id' => $userId]);
            return ($name === false || (string) $name === '') ? 'The guest' : (string) $name;
        } catch (\Throwable) {
            return 'The guest';
        }
    }
}
