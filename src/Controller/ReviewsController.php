<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — My Account › Reviews (1:1 port of legacy user/userreviews.php).
 *
 *   GET  /reviews         → stat cards (count / average) + every review the
 *                           renter has left, newest first, linked to the listing.
 *   POST /reviews/remove  → delete ONE of the caller's OWN reviews.
 *
 * Legacy behaviour kept intact:
 *   - Hosts are bounced to /host/profile (host pages not ported yet).
 *   - Rows resolve the listing cover photo (photo_type = 'cover').
 *   - Average rating rounded to 1 decimal; 0 when no reviews yet.
 *
 * Port notes:
 *   - resolve_photo() becomes resolvePhoto(): 'webprogg/' prefixes are
 *     stripped, bare filenames land in /uploads/listing_photos/cover/.
 *   - Review rows open /listings/{id}.
 *
 * REMOVE (user-requested extension, mirrors AdminReviewsController):
 *   - The `reviews` table is the single source of truth for every
 *     surface (this list, the listing card stars, the detail chip,
 *     /host/reviews, the admin panel, the RATE YOUR STAY box) — so a
 *     delete here disappears everywhere on the next page load, and
 *     every average recalculates live (AVG on read — nothing cached).
 *     Bonus: deleting the row makes the review box eligible again for
 *     that stay (already_reviewed is keyed off the same row).
 *   - CSRF: per-session token (reviews_csrf, bin2hex(random_bytes(32))),
 *     rendered into each row's form and verified with hash_equals —
 *     the exact AdminReviewsController delete pattern.
 *   - Ownership: DELETE ... WHERE id = :id AND user_id = :uid — a
 *     forged or guessed id can never remove somebody else's review.
 *   - PRG: redirect back to /reviews with a reviews_flash message
 *     ([type, text] — the admin flash shape), rendered by the
 *     template as a green/red pill above the stats.
 */
class ReviewsController extends AbstractController
{
    /** Session key of the per-page CSRF token for the REMOVE forms. */
    private const CSRF_KEY = 'reviews_csrf';

    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/reviews', name: 'app_reviews', methods: ['GET'], priority: 2)]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if (!$user) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        $user['id'] = (int)$user['id'];

        if ((int)$user['is_host'] === 1) {
            return new RedirectResponse('/host/profile');
        }

        /* ---- CSRF token for the per-row REMOVE forms (same
           per-session pattern as AdminReviewsController) ---- */
        $csrf = (string)$session->get(self::CSRF_KEY);
        if ($csrf === '') {
            $csrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $csrf);
        }

        /* ---- reviews left by this user (newest first) ---- */
        $rows = $this->db->fetchAllAssociative(
            "SELECT r.id, r.rating, r.comment, r.created_at,
                    l.id AS listing_id, l.title, l.location,
                    p.photo_path AS cover_photo
               FROM reviews r
               JOIN listings l ON l.id = r.listing_id
               LEFT JOIN listing_photos p
                      ON p.listing_id = l.id AND p.photo_type = 'cover'
              WHERE r.user_id = :id
              ORDER BY r.created_at DESC",
            ['id' => $userId]
        );

        $reviews = [];
        foreach ($rows as $row) {
            $reviews[] = [
                'id'         => (int)$row['id'],
                'listing_id' => (int)$row['listing_id'],
                'title'      => (string)$row['title'],
                'location'   => (string)$row['location'],
                'thumb'      => $this->resolvePhoto($row['cover_photo'] ?? null),
                'rating'     => (float)$row['rating'],
                'comment'    => (string)($row['comment'] ?? ''),
                'date'       => date('M j, Y', strtotime((string)$row['created_at'])),
            ];
        }

        $reviewCount   = count($reviews);
        $averageRating = $reviewCount > 0
            ? round(array_sum(array_column($reviews, 'rating')) / $reviewCount, 1)
            : 0;

        return $this->render('user/reviews.html.twig', [
            'user'           => $user,
            'reviews'        => $reviews,
            'review_count'   => $reviewCount,
            'average_rating' => $averageRating,
            'reviews_csrf'   => $csrf,
            'notif_count'    => $this->notifCount($userId),
        ]);
    }

    /* =====================================================================
     |  POST /reviews/remove — the row's REMOVE button.
     |  Session gate + host bounce exactly like index(); CSRF via
     |  hash_equals (AdminReviewsController delete pattern); the DELETE
     |  is ownership-checked (WHERE id AND user_id) and reports the
     |  affected-row count so a stale double-click is a friendly flash,
     |  never an error page. Everything is PRG — the browser lands back
     |  on /reviews and the row (plus its stars everywhere) is gone.
     | ================================================================== */
    #[Route('/reviews/remove', name: 'app_reviews_remove', methods: ['POST'], priority: 20)]
    public function remove(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, is_host FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if (!$user) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        if ((int)$user['is_host'] === 1) {
            return new RedirectResponse('/host/profile');
        }

        /* ---- CSRF — the same token the page rendered with ---- */
        $csrf        = (string)$session->get(self::CSRF_KEY);
        $postedToken = (string)$request->request->get('csrf_token', '');
        if ($csrf === '' || $postedToken === '' || !hash_equals($csrf, $postedToken)) {
            $this->addFlash('reviews_flash', [
                'type' => 'error',
                'text' => 'Your session expired — refresh the page and try again.',
            ]);
            return $this->redirectToRoute('app_reviews');
        }

        /* ---- Input — is_numeric + int cast exactly like the other
           POST endpoints (ReviewSubmitController / BookingCancel). ---- */
        $raw      = (string)$request->request->get('review_id', '');
        $reviewId = is_numeric($raw) ? (int)$raw : 0;
        if ($reviewId <= 0) {
            $this->addFlash('reviews_flash', [
                'type' => 'error',
                'text' => 'That review could not be identified.',
            ]);
            return $this->redirectToRoute('app_reviews');
        }

        /* ---- Ownership-checked delete — WHERE id AND user_id, so a
           forged/guessed id can never delete somebody else's review.
           executeStatement() returns the affected row count. ---- */
        try {
            $deleted = $this->db->executeStatement(
                'DELETE FROM reviews WHERE id = :id AND user_id = :uid',
                ['id' => $reviewId, 'uid' => $userId]
            );
        } catch (\Throwable $e) {
            error_log('reviews: remove failed: ' . $e->getMessage());
            $deleted = -1;
        }

        if ($deleted === 1) {
            $this->addFlash('reviews_flash', [
                'type' => 'success',
                'text' => 'Your review was removed. Every rating it fed was recalculated automatically.',
            ]);
        } elseif ($deleted === 0) {
            $this->addFlash('reviews_flash', [
                'type' => 'error',
                'text' => 'That review could not be found — it may already be gone.',
            ]);
        } else {
            $this->addFlash('reviews_flash', [
                'type' => 'error',
                'text' => 'Could not remove the review. Please try again.',
            ]);
        }

        return $this->redirectToRoute('app_reviews');
    }

    /* =====================================================================
     |  HELPERS — same pattern as WalletController / PaymentController
     | ================================================================== */

    /**
     * Legacy resolve_photo(): http(s) stays as-is; a stored 'webprogg/'
     * prefix is stripped to the site root; a bare filename is a cover
     * photo inside uploads/listing_photos/cover/.
     */
    private function resolvePhoto(?string $path, string $fallback = ''): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return $fallback;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $p = ltrim($path, '/');
        if (stripos($p, 'webprogg/') === 0) {
            $p = substr($p, strlen('webprogg/'));
        }
        if (strpos($p, '/') === false) {
            return '/uploads/listing_photos/cover/' . $p;
        }
        return '/' . $p;
    }

    private function notifCount(int $userId): int
    {
        try {
            return (int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0',
                ['id' => $userId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}
