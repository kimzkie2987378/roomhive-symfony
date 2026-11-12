<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Reviews.
 * 1:1 port of legacy admin/adminreviews.php.
 *
 *   GET|POST /admin/reviews   → moderate guest reviews.
 *     POST with delete_review + csrf_token → DELETE the row
 *     permanently (no undo). Redirect back to the same rating
 *     filter the admin was viewing.
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * Top summary panel mirrors the legacy:
 *   - Average rating + ★ + "N reviews platform-wide" caption.
 *   - 5-item summary grid: count of reviews at each star level
 *     (ROUND(rating) GROUP BY star). Always over ALL reviews so
 *     the panel stays stable while the admin drills in.
 *
 * Filter tabs: All, 5★, 4★, 3★, 2★, 1★ — the WHERE clause uses
 *   ROUND(r.rating) = :rt so a 4.7 rating shows under the "4★"
 *   tab (same as legacy).
 *
 * List: 100 most recent (ORDER BY created_at DESC LIMIT 100, same
 *   as legacy). No pagination.
 *
 * Comment cell: legacy truncates to 140 chars with mb_strimwidth +
 *   '…' suffix. The full comment is not shown in the table.
 *
 * Star string: pre-computed in PHP (str_repeat ★ ×N + ☆ ×(5-N))
 *   because Twig has no built-in `repeat` filter.
 */
class AdminReviewsController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    /** Rating filters the tabs can show. 'all' is special. */
    private const VALID_FILTERS = ['all', '5', '4', '3', '2', '1'];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/reviews', name: 'app_admin_reviews', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->get('admin_logged_in')) {
            return $this->redirect('/admin/login');
        }

        $adminName  = (string) ($session->get('admin_name')  ?? 'Admin User');
        $adminEmail = (string) ($session->get('admin_email') ?? '');

        /* ---- CSRF token (per-session, separate from user-flow) ---- */
        $adminCsrf = (string) $session->get(self::CSRF_KEY);
        if ($adminCsrf === '') {
            $adminCsrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $adminCsrf);
        }

        /* =========================================================
           HANDLE ACTION (POST): delete review
           CSRF-verified; the legacy code reads `rating` from the
           query string for the redirect-back (the form action URL
           carries the current filter). Mirrored exactly.
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('delete_review')) {
            $postedToken = (string) $request->request->get('csrf_token', '');
            $reviewId    = (int) $request->request->get('delete_review', 0);
            $retRating   = (string) $request->query->get('rating', 'all');
            if (!in_array($retRating, self::VALID_FILTERS, true)) {
                $retRating = 'all';
            }

            if (!hash_equals($adminCsrf, $postedToken) || $reviewId <= 0) {
                $this->addFlash('admin_reviews_flash', [
                    'type' => 'error',
                    'text' => 'That request could not be verified.',
                ]);
            } else {
                try {
                    $this->db->executeStatement(
                        'DELETE FROM reviews WHERE id = :id',
                        ['id' => $reviewId]
                    );
                    $this->addFlash('admin_reviews_flash', [
                        'type' => 'success',
                        'text' => 'Review deleted.',
                    ]);
                } catch (\Throwable $e) {
                    error_log('adminreviews: delete failed: ' . $e->getMessage());
                    $this->addFlash('admin_reviews_flash', [
                        'type' => 'error',
                        'text' => 'Could not delete that review.',
                    ]);
                }
            }

            return $this->redirectToRoute('app_admin_reviews', ['rating' => $retRating]);
        }

        /* =========================================================
           RATING FILTER — 'all' or '1'..'5'
           WHERE clause uses ROUND(r.rating) = :rt so a 4.7 review
           shows under the "4★" tab (same as legacy).
        ========================================================= */
        $rating = (string) $request->query->get('rating', 'all');
        if (!in_array($rating, self::VALID_FILTERS, true)) {
            $rating = 'all';
        }

        $whereSql = '';
        $params   = [];
        if ($rating !== 'all') {
            $whereSql = 'WHERE ROUND(r.rating) = :rt';
            $params['rt'] = (int) $rating;
        }

        /* =========================================================
           REVIEWS LIST — 100 most recent
        ========================================================= */
        $reviews = [];
        try {
            $reviews = $this->db->fetchAllAssociative(
                "SELECT r.id, r.rating, r.comment, r.created_at,
                        u.name AS reviewer_name, u.avatar_path,
                        l.title AS listing_title, l.id AS listing_id
                 FROM reviews r
                 JOIN users u ON u.id = r.user_id
                 JOIN listings l ON l.id = r.listing_id
                 $whereSql
                 ORDER BY r.created_at DESC
                 LIMIT 100",
                $params
            );
        } catch (\Throwable $e) {
            error_log('adminreviews: list query failed: ' . $e->getMessage());
            $reviews = [];
        }

        /* ---- Normalise per-row fields for the template ----
           Star string pre-computed (Twig has no `repeat` filter). */
        $reviews = array_map(function (array $r): array {
            $rounded = (int) round((float) $r['rating']);
            $rounded = max(0, min(5, $rounded));
            return [
                'id'             => (int) $r['id'],
                'rating'         => (float) $r['rating'],
                'rating_rounded' => $rounded,
                'rating_label'   => number_format((float) $r['rating'], 1),
                'stars'          => str_repeat('★', $rounded) . str_repeat('☆', max(0, 5 - $rounded)),
                'comment'        => (string) ($r['comment'] ?? ''),
                'comment_short'  => mb_strimwidth((string) ($r['comment'] ?? ''), 0, 140, '…'),
                'created_at'     => (string) $r['created_at'],
                'reviewer_name'  => (string) $r['reviewer_name'],
                'listing_title'  => (string) $r['listing_title'],
                'listing_id'     => (int) $r['listing_id'],
            ];
        }, $reviews);

        /* =========================================================
           DISTRIBUTION + AVERAGE
           Always over ALL reviews (ignores the rating filter) so the
           summary panel stays stable while the admin drills in.
           Same behavior as the legacy page (dist query has no WHERE).
        ========================================================= */
        $dist          = [];
        $avgRating     = null;
        $totalReviews  = 0;
        try {
            $distRows = $this->db->fetchAllKeyValue(
                'SELECT ROUND(rating) AS star, COUNT(*) AS c FROM reviews GROUP BY star'
            );
            foreach ($distRows as $k => $v) {
                $dist[(int) $k] = (int) $v;
            }

            $avgRow = $this->db->fetchNumeric('SELECT AVG(rating), COUNT(*) FROM reviews');
            if ($avgRow !== false) {
                if ($avgRow[0] !== null) {
                    $avgRating = round((float) $avgRow[0], 1);
                }
                $totalReviews = (int) $avgRow[1];
            }
        } catch (\Throwable $e) {
            error_log('adminreviews: stats query failed: ' . $e->getMessage());
        }

        /* Build the [5,4,3,2,1] array for the summary grid + filter counts */
        $starCounts = [];
        for ($i = 5; $i >= 1; $i--) {
            $starCounts[$i] = $dist[$i] ?? 0;
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* ---- Flash from the session (after redirect) ---- */
        $flash = null;
        $flashBag = $request->getSession()->getFlashBag()->get('admin_reviews_flash');
        if (!empty($flashBag)) {
            $flash = $flashBag[0];
        }

        return $this->render('admin/reviews.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'reviews'          => $reviews,
            'rating'           => $rating,
            'valid_filters'    => self::VALID_FILTERS,
            'star_counts'      => $starCounts,

            'avg_rating'       => $avgRating,
            'avg_rating_label' => $avgRating !== null ? number_format($avgRating, 1) : '—',
            'total_reviews'    => $totalReviews,

            'flash'            => $flash,
        ]);
    }

    /* =====================================================================
     *  NOTIFICATION COUNT — same helper used by every admin page so the
     *  bell badge stays consistent. Pending host applications + pending
     *  listings.
     * ================================================================= */

    private function countPending(): int
    {
        try {
            $hostApps = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM host_applications WHERE status = 'pending'"
            );
            $listings = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM listings WHERE status = 'pending'"
            );
            return $hostApps + $listings;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
