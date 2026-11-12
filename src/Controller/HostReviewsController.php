<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * RoomHive — Host › Reviews.
 * 1:1 port of legacy host/hostreviews.php.
 *
 *   GET /host/reviews  → reviews left by tenants on every listing the
 *     host owns, newest first. Two cards: rating summary (big avg
 *     + stars + "N reviews" + 5★→1★ distribution bars) and the
 *     review list (avatar, name, optional "Verified stay" badge,
 *     inline stars, M j, Y date, listing title, comment text or
 *     the "(No written comment)" placeholder).
 *
 * LEGACY FIXES carried over:
 *   1. HOST GUARD — non-hosts are redirected (the page previously
 *      had no is_host check). Same guard as every other host page:
 *      redirect to /host/become-a-host (the legacy file bounced to
 *      hostprofile.php; the rest of the shell standardizes on
 *      Become-a-Host for non-hosts — see HostBookingsController).
 *   2. hp_stars() wrapped in function_exists in legacy — irrelevant
 *      here (private method, never redeclared), but the math is
 *      identical: 5 stars, full = floor(rating) capped 0..5.
 *   3. Empty comments render "(No written comment)" italic
 *      placeholder instead of a blank paragraph.
 *   4. "Verified stay" badge — SQL EXISTS checks the reviewer
 *      actually had a booking on that listing (always true for
 *      reviews submitted via submit-review.php, but the badge is
 *      shown only when EXISTS returns 1).
 *
 * REVIEWS QUERY (verbatim from legacy):
 *   SELECT r.rating, r.comment, r.created_at,
 *          u.name AS reviewer_name, u.avatar_path AS reviewer_avatar,
 *          l.title AS listing_title,
 *          EXISTS (SELECT 1 FROM bookings b
 *                  WHERE b.listing_id = r.listing_id
 *                    AND b.user_id = r.user_id) AS verified_stay
 *     FROM reviews r
 *     JOIN listings l ON l.id = r.listing_id
 *     JOIN users u ON u.id = r.user_id
 *    WHERE l.user_id = :id
 *    ORDER BY r.created_at DESC
 *
 *   `rating` is decimal(2,1) → 1.0..5.0. Distribution buckets
 *   with round() (so 4.5 → 5, 3.4 → 3). Average is the raw mean
 *   (no rounding for the hero number — number_format(1) handles
 *   the display). 0 reviews → 0.0 avg, every bar at 0%.
 *
 *   `verified_stay` comes back as a string '1' / '0' from MySQL
 *   via DBAL — converted to bool before passing to the template
 *   so the Twig `if r.verified_stay` reads naturally.
 *
 *   resolvePhoto() handles reviewer_avatar the same way every
 *   other host page resolves an avatar — webprogg/ prefix stripped,
 *   absolute URLs/paths returned as-is, blank → fallback.
 *
 * ICON SUBSTITUTIONS — the macros file uses the older lucide.dev
 *   names the rest of the shell standardized on:
 *     legacy `star`        → macro 'star'        (exists)
 *     legacy `badge-check` → macro 'check-circle' (same substitution
 *                              host/bookings.html.twig documents for
 *                              the Confirmed Stays pill — a check
 *                              inside a circle reads the same as
 *                              badge-check)
 *
 * Auth: same gate as HostBookingsController / HostListingsController
 *   — logged_in + user_id (else app_login), then is_host = 1
 *   (else /host/become-a-host).
 */
class HostReviewsController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Environment $twig,
    ) {}

    #[Route('/host/reviews', name: 'app_host_reviews', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostBookingsController) ---- */
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
           BADGE COUNTS — navbar bell + sidebar pending tenants.
        ========================================================= */
        $notificationCount    = $this->countUnreadNotifications($userId);
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
           REVIEWS — every review on this host's listings, newest
           first. Verified-stay EXISTS subquery is verbatim from
           legacy (proves the reviewer actually booked that listing).
        ========================================================= */
        $reviews = [];
        try {
            $reviews = $this->db->fetchAllAssociative(
                "SELECT r.rating, r.comment, r.created_at,
                        u.name AS reviewer_name,
                        u.avatar_path AS reviewer_avatar,
                        l.title AS listing_title,
                        EXISTS (
                            SELECT 1 FROM bookings b
                            WHERE b.listing_id = r.listing_id
                              AND b.user_id = r.user_id
                        ) AS verified_stay
                   FROM reviews r
                   JOIN listings l ON l.id = r.listing_id
                   JOIN users u ON u.id = r.user_id
                  WHERE l.user_id = :id
                  ORDER BY r.created_at DESC",
                ['id' => $userId]
            );
        } catch (\Throwable $e) {
            /* Best-effort: render the empty state rather than 500 */
            $reviews = [];
        }

        /* =========================================================
           DISTRIBUTION + AVERAGE — same loop as legacy.
           `rating` is decimal(2,1) → 1.0..5.0. Buckets use round()
           (4.5→5, 3.4→3), capped 1..5 (the floor 1 is a guard so
           a stray 0.4 rating still buckets to 1★, never 0).
        ========================================================= */
        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $sum  = 0.0;
        foreach ($reviews as $r) {
            $star = (int) round((float) $r['rating']);
            $star = max(1, min(5, $star));
            $dist[$star]++;
            $sum += (float) $r['rating'];
        }
        $totalReviews = count($reviews);
        $avgRating    = $totalReviews > 0 ? ($sum / $totalReviews) : 0.0;

        /* =========================================================
           NORMALISE per-review fields for the template — same
           formatting legacy did inline (date M j, Y, rating
           number_format 1). verified_stay is the string '1'/'0'
           from MySQL via DBAL → cast to bool. Empty comments stay
           empty so the template can swap in the italic placeholder.
        ========================================================= */
        $rows = array_map(function (array $r): array {
            $rating = (float) $r['rating'];
            $comment = trim((string) ($r['comment'] ?? ''));
            return [
                'rating'         => $rating,
                'rating_display' => number_format($rating, 1),
                'stars_full'      => max(0, min(5, (int) floor($rating))),
                'comment'         => $comment,
                'has_comment'     => $comment !== '',
                'created_display' => !empty($r['created_at'])
                    ? date('M j, Y', strtotime((string) $r['created_at']))
                    : '',
                'reviewer_name'   => (string) $r['reviewer_name'],
                'reviewer_avatar' => $this->resolvePhoto(
                    (string) ($r['reviewer_avatar'] ?? ''),
                    '/images/default-avatar.png'
                ),
                'listing_title'   => (string) $r['listing_title'],
                'verified_stay'   => !empty($r['verified_stay']),
            ];
        }, $reviews);

        /* =========================================================
           DISTRIBUTION BARS — pre-compute percentages (round, 0..100)
           so the template only lays out divs. 0 reviews → 0% every row.
        ========================================================= */
        $distRows = [];
        for ($i = 5; $i >= 1; $i--) {
            $count = $dist[$i] ?? 0;
            $pct   = $totalReviews > 0 ? (int) round(($count / $totalReviews) * 100) : 0;
            $distRows[] = [
                'star'      => $i,
                'count'     => $count,
                'pct'       => $pct,
                'star_word' => $i === 1 ? 'star' : 'stars',
            ];
        }

        return $this->render('host/reviews.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'avg_rating'    => $avgRating,
            'avg_display'   => number_format($avgRating, 1),
            'stars_full'     => max(0, min(5, (int) floor($avgRating))),
            'total_reviews' => $totalReviews,
            'review_word'   => $totalReviews === 1 ? 'review' : 'reviews',
            'dist_rows'     => $distRows,
            'reviews'       => $rows,
        ]);
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostBookingsController.
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
     *  HostProfileController / HostBookingsController / HostPayoutsController
     *  / AdminController / AccountController.
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
