<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Notifications (full page, host dashboard shell).
 * 1:1 port of legacy host/hostnotifications.php.
 *
 *   GET /host/notifications  → paginated notification list (20/page)
 *     with All/Unread tabs, "n unread" pill, Mark-all-as-read, and
 *     the photo-chip / coloured-chip item rows.
 *
 *   (The mark-read ENDPOINT is shared with the renter page —
 *   POST /notifications/mark-read, route app_notifications_mark_read
 *   in NotificationsController. Legacy's
 *   notifications/mark-notifications-read.php was one endpoint for
 *   both shells; the port keeps it that way, so this page's JS
 *   posts there too.)
 *
 * LEGACY BEHAVIOUR KEPT INTACT:
 *   - Unread count computed ONCE; feeds the navbar bell badge, the
 *     "n unread" pill / Unread tab count, AND the sidebar badge
 *     (hostNotifUnread reuse contract — no extra query).
 *   - Activity type derived from the stored link/message
 *     (message/accepted/declined/application/payment/review/booking/
 *     listing/system) → lucide icon + colour chip, same mapping as
 *     the bell dropdown and the renter notifications page.
 *   - Emoji stripped from message text; empty label falls back to
 *     "New notification".
 *   - Batch-fetched cover photos + listing titles for
 *     booking-detail/listing-detail links (parse_str on the stored
 *     link's query string, IN(...) fetches keyed 'b{id}' / 'L{id}').
 *   - Item click marks read then navigates to the stored link
 *     (legacy /webprogg/... links stay as stored — they 404 until
 *     those pages are ported; same policy as the renter page port).
 *
 * HOST GUARD (legacy: non-hosts → user notifications page): the
 *   renter notifications page is already ported (route
 *   app_notifications), so non-hosts redirect THERE — a real page,
 *   not a TODO.
 *
 * ICON SUBSTITUTIONS — the macros file uses the older lucide.dev
 *   names the rest of the shell standardized on; two of the legacy
 *   np_icon names aren't in the macro table:
 *     legacy `circle-x`   → macro 'x-circle'  (same glyph, older name)
 *     legacy `user-clock` → macro 'clock'     (same substitution
 *                               host/bookings.html.twig uses for
 *                               hourglass — a clock reads the same
 *                               "pending application" beat)
 *   All other keys exist as-is: message-square, calendar-check,
 *   wallet, star, calendar-days, building-2, bell (+ inbox / bell
 *   for the empty states).
 *
 * Port notes:
 *   - np_time_ago / np_strip_emoji / np_type / np_icon moved into
 *     controller methods (same as the renter page port); Twig just
 *     renders.
 *   - resolvePhoto follows the renter page port's np_resolve_photo
 *     (http(s) as-is; bare filename → /uploads/listing_photos/cover/;
 *     stored webprogg/ paths stay as stored — those images 404 and
 *     the template's onerror fallback swaps in the lucide chip,
 *     exactly like the renter page).
 *
 * Auth: logged_in + user_id (else app_login); non-host →
 *   app_notifications (renter notifications).
 */
class HostNotificationsController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(private readonly Connection $db) {}

    #[Route('/host/notifications', name: 'app_host_notifications', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate ---- */
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

        /* ---- HOST GUARD — non-hosts go to the renter
           notifications page (legacy: user/notifications.php,
           already ported as app_notifications) ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirectToRoute('app_notifications');
        }

        /* =========================================================
           UNREAD COUNT — computed ONCE (legacy contract):
             notification_count → navbar bell badge
             unread_count       → page pill / tabs
             (the sidebar badge reuses notification_count — the
             shell's hostNotifUnread reuse, no extra query)
        ========================================================= */
        $unreadCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
            ['u' => $userId]
        );

        /* =========================================================
           BADGE COUNTS — pending tenants for the sidebar badge.
        ========================================================= */
        $pendingTenantsCount = $this->countPendingTenants($userId);

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
           FILTER (all | unread) + PAGINATION — same math as legacy:
           perPage 20, page clamped to totalPages, OFFSET derived.
           The unread filter appends "AND is_read = 0" to BOTH the
           COUNT and the page query (legacy $countWhere).
        ========================================================= */
        $filter = $request->query->get('filter') === 'unread' ? 'unread' : 'all';
        $countWhere = ($filter === 'unread') ? ' AND is_read = 0' : '';

        $page = max(1, $request->query->getInt('page', 1));

        $totalItems = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :u' . $countWhere,
            ['u' => $userId]
        );
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * self::PER_PAGE;
        $perPage    = self::PER_PAGE;

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, message, link, is_read, created_at
               FROM notifications
              WHERE user_id = :u" . $countWhere . "
              ORDER BY created_at DESC
              LIMIT $perPage OFFSET $offset",
            ['u' => $userId]
        );

        /* =========================================================
           BATCH-FETCH cover photos + titles for booking/listing
           links (verbatim logic from legacy / the renter port):
           parse the stored link, collect booking-detail / listing
           -detail ids, IN(...) fetch keyed 'b{id}' / 'L{id}'.
        ========================================================= */
        $bookingIds = [];
        $listingIds = [];
        $parsed     = [];

        foreach ($rows as $row) {
            $link = (string) ($row['link'] ?? '');
            parse_str((string) parse_url($link, PHP_URL_QUERY), $qs);
            $path = strtolower((string) parse_url($link, PHP_URL_PATH));
            $id   = isset($qs['id']) && is_numeric($qs['id']) ? (int) $qs['id'] : 0;

            $parsed[$row['id']] = ['path' => $path, 'id' => $id];

            if ($id > 0 && strpos($path, 'booking-detail') !== false) {
                $bookingIds[] = $id;
            }
            if ($id > 0 && strpos($path, 'listing-detail') !== false) {
                $listingIds[] = $id;
            }
        }

        $photos = [];
        $titles = [];

        if (!empty($bookingIds)) {
            $in = implode(',', array_map('intval', array_unique($bookingIds)));
            foreach (
                $this->db->fetchAllAssociative(
                    "SELECT b.id, l.title, p.photo_path
                       FROM bookings b
                       JOIN listings l ON l.id = b.listing_id
                       LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.photo_type = 'cover'
                      WHERE b.id IN ($in)"
                ) as $r
            ) {
                $photos['b' . (int) $r['id']] = $this->resolvePhoto($r['photo_path'] ?? null);
                $titles['b' . (int) $r['id']] = (string) $r['title'];
            }
        }

        if (!empty($listingIds)) {
            $in = implode(',', array_map('intval', array_unique($listingIds)));
            foreach (
                $this->db->fetchAllAssociative(
                    "SELECT l.id, l.title, p.photo_path
                       FROM listings l
                       LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.photo_type = 'cover'
                      WHERE l.id IN ($in)"
                ) as $r
            ) {
                $photos['L' . (int) $r['id']] = $this->resolvePhoto($r['photo_path'] ?? null);
                $titles['L' . (int) $r['id']] = (string) $r['title'];
            }
        }

        /* =========================================================
           BUILD ITEMS — np_type → np_icon (macro keys) → row
           array. Emoji stripped; empty label falls back to
           "New notification". Links stay as stored (fallback
           '/host/notifications' when blank).
        ========================================================= */
        $items = [];

        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $link    = (string) ($row['link'] ?? '');
            $message = trim((string) ($row['message'] ?? ''));
            $isRead  = (int) $row['is_read'];

            $type = $this->deriveType($link, $message);
            [$iconName, $icls] = $this->typeIcon($type);

            $label = $message !== '' ? $this->stripEmoji($message) : '';
            if ($label === '') {
                $label = 'New notification';
            }

            $ctxKey = '';
            $imgKey = '';
            if (isset($parsed[$id])) {
                $path = $parsed[$id]['path'];
                $bid  = $parsed[$id]['id'];
                if ($bid > 0 && strpos($path, 'booking-detail') !== false) {
                    $ctxKey = $imgKey = 'b' . $bid;
                } elseif ($bid > 0 && strpos($path, 'listing-detail') !== false) {
                    $ctxKey = $imgKey = 'L' . $bid;
                }
            }

            $items[] = [
                'id'     => $id,
                'link'   => $link !== '' ? $link : '/host/notifications',
                'icon'   => $iconName,
                'icls'   => $icls,
                'img'    => ($imgKey !== '' && isset($photos[$imgKey])) ? $photos[$imgKey] : '',
                'ctx'    => ($ctxKey !== '' && isset($titles[$ctxKey])) ? $titles[$ctxKey] : '',
                'label'  => $label,
                'time'   => $this->timeAgo((string) $row['created_at']),
                'unread' => !$isRead,
            ];
        }

        return $this->render('host/notifications.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $unreadCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'items'         => $items,
            'unread_count'  => $unreadCount,
            'filter'        => $filter,
            'page'          => $page,
            'total_pages'   => $totalPages,
        ]);
    }

    /* =====================================================================
     *  HELPERS — np_* from legacy, now controller methods
     *  (same as the renter NotificationsController port).
     * ================================================================= */

    private function timeAgo(string $ts): string
    {
        $diff = time() - strtotime($ts);
        if ($diff < 60)     return 'Just now';
        if ($diff < 3600)   return floor($diff / 60) . 'm ago';
        if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M j, Y', strtotime($ts));
    }

    /** Strips emoji so old rows with baked-in emoji render clean. */
    private function stripEmoji(string $text): string
    {
        $clean = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}'
                . '\x{2190}-\x{21FF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}'
                . '\x{2728}\x{10000}-\x{E01EF}]/u',
            '',
            $text
        );
        return trim((string) preg_replace('/\s{2,}/', ' ', (string) $clean));
    }

    private function deriveType(string $link, string $message): string
    {
        $l = strtolower($link);
        $m = strtolower($message);

        if (strpos($l, 'message') !== false || strpos($l, 'chat') !== false) return 'message';
        if (
            strpos($l, 'accept') !== false || strpos($m, 'accepted') !== false
            || strpos($m, 'approved') !== false
        ) return 'accepted';
        if (
            strpos($l, 'reject') !== false || strpos($m, 'declined') !== false
            || strpos($m, 'rejected') !== false
        ) return 'declined';
        if (
            strpos($l, 'pendingtenants') !== false || strpos($m, 'application') !== false
            || strpos($m, 'applied') !== false
        ) return 'application';
        if (
            strpos($l, 'payment') !== false || strpos($m, 'payment') !== false
            || strpos($m, 'paid') !== false || strpos($m, 'refunded') !== false
        ) return 'payment';
        if (
            strpos($l, 'review') !== false || strpos($l, 'rating') !== false
            || strpos($m, 'review') !== false
        ) return 'review';
        if (strpos($l, 'booking') !== false) return 'booking';
        if (strpos($l, 'listing') !== false || strpos($m, 'listing') !== false) return 'listing';

        return 'system';
    }

    /**
     * Lucide icon + colour class per activity type — same mapping as
     * the bell dropdown (ndd_icon) and the renter notifications page,
     * so all three views match. Returns MACRO keys (admin/_macros.html.twig):
     *   legacy `circle-x`   → 'x-circle'
     *   legacy `user-clock` → 'clock'
     * (both documented substitutions — the macro table uses the older
     * lucide.dev names the rest of the shell standardized on).
     * [macro-icon-name, color-class]
     */
    private function typeIcon(string $type): array
    {
        switch ($type) {
            case 'message':
                return ['message-square', 'np-ic-blue'];
            case 'accepted':
                return ['calendar-check', 'np-ic-green'];
            case 'declined':
                return ['x-circle',       'np-ic-red'];
            case 'application':
                return ['clock',          'np-ic-yellow'];
            case 'payment':
                return ['wallet',         'np-ic-green'];
            case 'review':
                return ['star',           'np-ic-yellow'];
            case 'booking':
                return ['calendar-days',  'np-ic-blue'];
            case 'listing':
                return ['building-2',     'np-ic-honey'];
            default:
                return ['bell',           'np-ic-gray'];
        }
    }

    /**
     * Notification cover-photo resolver — same as the renter page
     * port's np_resolve_photo(): http(s) stays as-is; a bare filename
     * is a cover photo inside uploads/listing_photos/cover/; stored
     * webprogg/ paths stay as stored (those images 404 and the
     * template's onerror fallback swaps in the lucide chip).
     */
    private function resolvePhoto(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $p = ltrim($path, '/');
        if (stripos($p, 'webprogg/') === 0) {
            return '/' . $p;
        }
        if (strpos($p, '/') === false) {
            return '/uploads/listing_photos/cover/' . $p;
        }
        return '/' . $p;
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostBookingsController.
     * ================================================================= */

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
}
