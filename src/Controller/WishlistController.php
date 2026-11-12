<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

class WishlistController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /* =========================================================
       GET /wishlist — My Wishlist page
    ========================================================= */
    #[Route('/wishlist', name: 'app_wishlist', priority: 2)]
    public function index(SessionInterface $session): Response
    {
        if (!$session->get('logged_in')) {
            return $this->redirect('/login');
        }

        $userId = (int) $session->get('user_id');

        try {
            $u = $this->db->fetchAssociative(
                'SELECT id, name, email, avatar_path, is_host, created_at
                 FROM users WHERE id = ? LIMIT 1',
                [$userId]
            );
        } catch (\Throwable) {
            $u = false;
        }

        if (!$u) {
            $session->invalidate();
            return $this->redirect('/login');
        }

        /* avatar — session first, DB fallback (same rule as navbar) */
        $avatar = (string) ($session->get('avatar_path') ?? '');
        if ($avatar === '' || str_contains($avatar, 'default-avatar')) {
            $dbAvatar = trim((string) ($u['avatar_path'] ?? ''));
            $avatar = ($dbAvatar !== '' && !str_contains($dbAvatar, 'default-avatar')) ? $dbAvatar : '';
        }

        /* real unread bell count */
        $notificationCount = 0;
        try {
            $notificationCount = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
                [$userId]
            );
        } catch (\Throwable) {
            $notificationCount = 0;
        }

        /* wishlist items (up to 100, newest first) */
        $wishlist = [];
        $wlTotal  = 0;
        try {
            $wlTotal = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM wishlist WHERE user_id = ?',
                [$userId]
            );

            try {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT w.listing_id, l.* FROM wishlist w
                     JOIN listings l ON l.id = w.listing_id
                     WHERE w.user_id = ?
                     ORDER BY w.id DESC
                     LIMIT 100',
                    [$userId]
                );
            } catch (\Throwable) {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT w.listing_id, l.* FROM wishlist w
                     JOIN listings l ON l.id = w.listing_id
                     WHERE w.user_id = ?',
                    [$userId]
                );
            }

            $covers = $this->coverMap(array_map(fn($r) => (int) $r['listing_id'], $rows));

            foreach ($rows as $r) {
                $lid   = (int) $r['listing_id'];
                $price = (float) $this->pick($r, ['price_per_night', 'price', 'nightly_price', 'monthly_price'], 0);
                $cover = (string) ($covers[$lid] ?? '');

                $wishlist[] = [
                    'id'        => $lid,
                    'title'     => trim((string) $this->pick($r, ['title', 'name'], 'Listing #' . $lid)),
                    'location'  => trim((string) $this->pick($r, ['location', 'city', 'address', 'barangay'], '')),
                    'cover'     => $cover,
                    'cover_url' => $this->legacyPhotoUrl($cover),
                    'price_str' => number_format($price, 0),
                ];
            }
        } catch (\Throwable) {
            $wishlist = [];
            $wlTotal  = 0;
        }

        $resp = $this->render('user/wishlist.html.twig', [
            'is_host'            => (bool) ($u['is_host'] ?? false),
            'nav_avatar'         => $avatar,
            'notification_count' => $notificationCount,
            'current_page'       => 'wishlist',
            'wishlist'           => $wishlist,
            'wl_total'           => $wlTotal,
        ]);

        $resp->headers->set('Cache-Control', 'no-store, max-age=0, must-revalidate');
        return $resp;
    }

    /* =========================================================
       POST /wishlist/toggle — the SINGLE shared add/remove
       endpoint (home page hearts, this page's remove buttons).

       JSON contract:
         { ok: true,  saved: true|false, message? }
         { ok: false, login: true, error }
         { ok: false, error }
    ========================================================= */
    #[Route('/wishlist/toggle', name: 'app_wishlist_toggle', methods: ['POST'], priority: 2)]
    public function toggle(Request $request, SessionInterface $session): JsonResponse
    {
        if (!$session->get('logged_in')) {
            return $this->json([
                'ok'    => false,
                'login' => true,
                'error' => 'Please log in to save listings.',
            ], 401);
        }

        /* ---------- SELF-HEAL — wishlist table if missing ---------- */
        try {
            $this->db->executeStatement("
                CREATE TABLE IF NOT EXISTS wishlist (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    listing_id INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_user_listing (user_id, listing_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable) {
            return $this->json([
                'ok'    => false,
                'error' => 'Database error while preparing wishlist.',
            ], 500);
        }

        $listingId = (int) $request->request->get('listing_id', 0);
        if ($listingId <= 0) {
            return $this->json(['ok' => false, 'error' => 'Invalid listing.'], 400);
        }

        /* listing must exist */
        try {
            $exists = $this->db->fetchOne(
                'SELECT id FROM listings WHERE id = ? LIMIT 1',
                [$listingId]
            );
        } catch (\Throwable) {
            $exists = false;
        }
        if (!$exists) {
            return $this->json(['ok' => false, 'error' => 'Listing not found.'], 404);
        }

        $userId = (int) $session->get('user_id');

        /* try remove first — if a row was deleted, it was saved */
        try {
            $deleted = $this->db->delete('wishlist', [
                'user_id'    => $userId,
                'listing_id' => $listingId,
            ]);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'error' => 'Database error.'], 500);
        }

        if ($deleted > 0) {
            return $this->json([
                'ok'      => true,
                'saved'   => false,
                'message' => 'Removed from your wishlist.',
            ]);
        }

        /* otherwise insert */
        try {
            $this->db->insert('wishlist', [
                'user_id'    => $userId,
                'listing_id' => $listingId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            /* race condition: row inserted between DELETE and INSERT */
            return $this->json(['ok' => true, 'saved' => true]);
        }

        return $this->json([
            'ok'      => true,
            'saved'   => true,
            'message' => 'Saved to your wishlist.',
        ]);
    }

    /* =========================================================
       helpers
    ========================================================= */

    private function pick(array $row, array $keys, $default = '')
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
                return $row[$k];
            }
        }
        return $default;
    }

    /** Probes listing_photos for cover images across likely column names. */
    private function coverMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        foreach (['photo_path', 'path', 'url', 'image', 'filename', 'file'] as $col) {
            try {
                $rows = $this->db->fetchAllAssociative(
                    "SELECT listing_id, {$col} AS c FROM listing_photos
                     WHERE photo_type = 'cover' AND listing_id IN ($in)",
                    $ids
                );
                $map = [];
                foreach ($rows as $r) {
                    $map[(int) $r['listing_id']] = (string) $r['c'];
                }
                if ($map) {
                    return $map;
                }
            } catch (\Throwable) {
                // try the next column name
            }
        }

        // fallback: no cover flagged — take the first photo per listing
        foreach (['photo_path', 'path', 'url', 'image'] as $col) {
            try {
                $rows = $this->db->fetchAllAssociative(
                    "SELECT listing_id, {$col} AS c FROM listing_photos
                     WHERE listing_id IN ($in) ORDER BY id ASC",
                    $ids
                );
                $map = [];
                foreach ($rows as $r) {
                    $lid = (int) $r['listing_id'];
                    if (!isset($map[$lid])) {
                        $map[$lid] = (string) $r['c'];
                    }
                }
                if ($map) {
                    return $map;
                }
            } catch (\Throwable) {
                // try the next column name
            }
        }
        return [];
    }

    private function coverUrl(string $path): string
    {
        /* kept for backward compat — delegates to the real resolver */
        return $this->legacyPhotoUrl($path);
    }

    /**
     * Legacy photo-path resolver — Symfony edition.
     * Ported verbatim from ListingsController::legacyPhotoUrl() so the
     * wishlist cards resolve cover photos exactly the same way the
     * listing detail page and listings grid do.
     *
     * The DB stores paths written by the legacy app using several
     * historical conventions:
     *   webprogg/uploads/listing_photos/cover/x.jpg
     *   host/uploads/avatars/x.jpg
     *   uploads/listing_photos/x.jpg
     *   cover_xxx.jpg            (bare cover filename)
     *   xxx.jpg                  (bare filename)
     *   https://...              (absolute external URL)
     *
     * ALL of them map onto Symfony's public/ tree, which is why
     * legacy webprogg/uploads/ was copied to public/uploads/.
     */
    private function legacyPhotoUrl(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/images/ListingPlaceholder.png';
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
