<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host Public Profile ("meet the host" page).
 * 1:1 port of legacy host/hostpublicprofile.php.
 *
 *   GET /listings/host/{id}   → public profile of ANY host:
 *     hero card (gold-ring avatar + shimmer name + "Hosting since"
 *     + verified/superhost badges + rating), About section, the
 *     host's APPROVED listings grid (reuse of the listings-page
 *     .listing-box card), and a sticky sidebar with response time /
 *     active listings / member since + a CTA button.
 *
 * Linked from listing-detail.html.twig's "View Host Profile"
 * button in the Meet-your-host card (?id= is the host's users.id,
 * same contract as the legacy hostpublicprofile.php?id=).
 *
 * Auth: PUBLIC — guests can view. The login state only changes
 * the sidebar CTA (Edit your profile / Message {host} / Log in
 * to message host), exactly like legacy.
 *
 * CSS CONTRACT (same two-layer approach as the legacy page):
 *   Layer 1 (structure) + Layer 2 (honey polish) live INLINE in
 *   listings/host_public_profile.html.twig. The legacy linked
 *   host-profile.css (the DASHBOARD css) but its structural
 *   classes (.hp-hero-inner, .hp-layout, …) were never defined
 *   there — the inline layers are self-contained, so the Symfony
 *   port skips host-profile.css entirely and loads only
 *   assets/style.css + assets/listings-style.css (the latter
 *   styles the reused .listing-box grid cards).
 *
 * KNOWN LIMITS (by schema, same as legacy):
 *   - $host['bio'] is always empty (users has no bio column) —
 *     the About section shows the "hasn't added an introduction
 *     yet" placeholder.
 *   - verified / superhost are hardcoded false — flagged for
 *     future wiring, same as legacy.
 *   - "Message {host}" CTA points at /messages (the ported
 *     inbox). Legacy had a dedicated start-conversation.php with
 *     ?host_id= prefilling a thread — TODO when a
 *     /messages/start/{hostId} route is ported.
 *
 * FIX LOG — "apply to see the image": resolvePhoto() used to
 *   early-return ANY path starting with "/" as-is. Real
 *   avatar_path values are /webprogg/uploads/avatars/… so the
 *   hero + navbar avatar src pointed at /webprogg/… → 404 →
 *   broken image. The resolver is now an EXACT clone of
 *   ListingsController::legacyPhotoUrl() (ltrim('/'), strip
 *   webprogg/ + host/, uploads/ → /uploads/…, cover_ → cover
 *   dir, bare filename → /uploads/listing_photos/…).
 */
class HostPublicProfileController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/listings/host/{id}', name: 'app_host_public_profile', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function index(Request $request, int $id): Response
    {
        $session    = $request->getSession();
        $isLoggedIn = $session->get('logged_in') === true;
        $userId     = (int) $session->get('user_id', 0);

        /* =========================================================
           NAVBAR CONTRACT — avatar staleness + viewer's is_host +
           REAL unread bell count (same queries as legacy)
        ========================================================== */
        $navAvatar    = '/images/default-avatar.png';
        $viewerIsHost = false;

        if ($isLoggedIn && $userId > 0) {
            $row = $this->db->fetchAssociative(
                'SELECT avatar_path, is_host FROM users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            );
            if ($row) {
                if (!empty($row['avatar_path'])) {
                    $navAvatar = $this->resolvePhoto(
                        (string) $row['avatar_path'],
                        '/images/default-avatar.png'
                    );
                }
                $viewerIsHost = !empty($row['is_host']);
            }
        }

        $notificationCount = 0;
        try {
            $notificationCount = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            );
        } catch (\Throwable) {
            $notificationCount = 0;
        }

        /* =========================================================
           RESOLVE HOST FROM {id} — must be an actual host
        ========================================================== */
        $hostRow = $this->db->fetchAssociative(
            "SELECT id, name, email, avatar_path, is_host, created_at
             FROM users
             WHERE id = :id AND is_host = 1
             LIMIT 1",
            ['id' => $id]
        );

        if ($hostRow === false) {
            /* Same redirect as legacy: not a host (or bad id) → back
               to the listings grid */
            return $this->redirectToRoute('app_listings');
        }

        $isOwnProfile = $isLoggedIn && $userId === (int) $hostRow['id'];

        /* =========================================================
           HOST'S APPROVED LISTINGS — cover photo join, newest first
        ========================================================== */
        $listingRows = $this->db->fetchAllAssociative(
            "SELECT l.id, l.title, l.category, l.location, l.price, l.bedrooms, l.created_at,
                    p.photo_path AS cover_photo
             FROM listings l
             LEFT JOIN listing_photos p
                    ON p.listing_id = l.id AND p.photo_type = 'cover'
             WHERE l.user_id = :host_id AND l.status = 'approved'
             ORDER BY l.created_at DESC",
            ['host_id' => (int) $hostRow['id']]
        );

        $hostListings = array_map(fn(array $l): array => [
            'id'       => (int) $l['id'],
            'title'    => (string) $l['title'],
            'image'    => !empty($l['cover_photo'])
                ? $this->resolvePhoto((string) $l['cover_photo'], '/images/ListingPlaceholder.png')
                : '/images/ListingPlaceholder.png',
            'location' => (string) $l['location'],
            'category' => (string) ($l['category'] ?? ''),
            'price'    => (float) $l['price'],
            'bedrooms' => (int) $l['bedrooms'],
        ], $listingRows);

        $listingCount = count($hostListings);

        /* =========================================================
           HOST'S REVIEW STATS — average across ALL the host's
           listings (same query as legacy)
        ========================================================== */
        $hostReviewRatings = array_map('floatval', array_column(
            $this->db->fetchAllAssociative(
                "SELECT r.rating
                 FROM reviews r
                 JOIN listings l2 ON l2.id = r.listing_id
                 WHERE l2.user_id = :host_id",
                ['host_id' => (int) $hostRow['id']]
            ),
            'rating'
        ));

        $hostRating  = count($hostReviewRatings) > 0
            ? round(array_sum($hostReviewRatings) / count($hostReviewRatings), 1)
            : 0;
        $hostReviews = count($hostReviewRatings);

        /* =========================================================
           ASSEMBLE HOST — same shape as legacy $host
        ========================================================== */
        $memberSince = '—';
        if (!empty($hostRow['created_at'])) {
            $ts = strtotime((string) $hostRow['created_at']);
            $memberSince = $ts ? date('F Y', $ts) : '—';
        }

        $host = [
            'id'            => (int) $hostRow['id'],
            'name'          => (string) $hostRow['name'],
            'avatar'        => !empty($hostRow['avatar_path'])
                ? $this->resolvePhoto((string) $hostRow['avatar_path'], '/images/default-avatar.png')
                : '/images/default-avatar.png',
            'bio'           => trim((string) ($hostRow['bio'] ?? '')), /* users has no bio column — always '' */
            'member_since'  => $memberSince,
            'rating'        => $hostRating,
            'reviews'       => $hostReviews,
            'verified'      => false,  /* hardcoded like legacy — wiring TODO */
            'superhost'     => false,  /* hardcoded like legacy — wiring TODO */
            'response_time' => 'within a day',
        ];

        $firstName = explode(' ', trim($host['name']))[0];

        return $this->render('listings/host_public_profile.html.twig', [
            /* shared navbar contract */
            'is_logged_in'       => $isLoggedIn,
            'nav_avatar'         => $navAvatar,
            'notification_count' => $notificationCount,
            'current_page'       => '/listings',

            /* page data */
            'host'           => $host,
            'host_listings'  => $hostListings,
            'listing_count'  => $listingCount,
            'is_own_profile' => $isOwnProfile,
            'viewer_is_host' => $viewerIsHost,
            'first_name'     => $firstName,
        ]);
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — EXACT clone of ListingsController's
     *  legacyPhotoUrl() (same mapping HostProfileController uses).
     *  No leading-slash early-return: DB values like
     *  /webprogg/uploads/avatars/avatar_20_….jpg must be stripped
     *  and mapped onto public/uploads/, not echoed verbatim.
     * ================================================================= */

    private function resolvePhoto(string $path, string $fallback = '/images/ListingPlaceholder.png'): string
    {
        $path = trim($path);

        if ($path === '') {
            return $fallback;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path; /* external URL — works as-is */
        }

        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        /* Strip legacy project prefixes — the files now live
           directly under public/uploads/ */
        if (stripos($path, 'webprogg/') === 0) {
            $path = substr($path, strlen('webprogg/'));
        }
        if (stripos($path, 'host/') === 0) {
            $path = substr($path, strlen('host/'));
        }

        /* Whatever remains is either uploads/... or a bare filename */
        if (stripos($path, 'uploads/') === 0) {
            return '/' . $path;
        }
        if (stripos($path, 'cover_') === 0) {
            return '/uploads/listing_photos/cover/' . basename($path);
        }

        return '/uploads/listing_photos/' . $path;
    }
}
