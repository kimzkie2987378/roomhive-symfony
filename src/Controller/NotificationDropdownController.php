<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RoomHive — NOTIFICATION BELL DROPDOWN (fragment controller).
 * 1:1 port of legacy includes/notification_dropdown.php.
 *
 * Legacy was a SELF-CONTAINED include: it ran its OWN queries
 * against the notifications table and rendered CSS + panel + JS
 * wherever the page included it ("Include ONCE per page, right
 * AFTER the navbar"). The port keeps that self-containment with a
 * fragment SUB-REQUEST — no page controller passes anything (the
 * sub-request reads the same session and queries the same tables,
 * exactly like the legacy include did):
 *
 *     {{ render(controller('App\\Controller\\NotificationDropdownController::panel')) }}
 *       → rendered by partials/navbar.html.twig right after
 *         </header> → automatically on EVERY user + host page
 *
 *     {{ render(controller('App\\Controller\\NotificationDropdownController::adminPanel')) }}
 *       → rendered by admin/base.html.twig right after the topbar
 *         </header> → automatically on EVERY admin page
 *
 * ACTIONS (NO #[Route] on purpose — fragments, never reachable by
 * URL; guests get an empty response, mirroring legacy's `return;`):
 *
 *   panel()      — the USER + HOST bell. Notifications are rows in
 *                  `notifications` keyed by user_id — whoever is
 *                  logged in sees their own. ROLE-AWARE "View all":
 *                  hosts → /host/notifications (hostnotifications.php,
 *                  ported), renters → /notifications.
 *
 *   adminPanel() — the ADMIN topbar bell. === ADAPTATION (flagged)
 *                  === legacy rendered NOTHING for admins: admin
 *                  sessions never carry user_id (separate
 *                  admin_logged_in keys, never the user-flow keys),
 *                  so legacy's guest check returned early. This port
 *                  explicitly applies the dropdown to admin too, so
 *                  the admin variant drives the SAME panel with the
 *                  admin's OWN semantics — the exact two numbers the
 *                  admin shell's static bell badge always showed:
 *                  pending host applications + pending listings
 *                  (same SQL as AdminController), each linking to
 *                  its review queue. No mark-read wiring here (queue
 *                  items are not rows in notifications) — items carry
 *                  unread=false so the JS mark path never fires, and
 *                  the "Mark all as read" button + "View all"
 *                  footer are omitted.
 *
 * ROLE DETECTION (panel): legacy trusted $_SESSION['is_host'],
 *   which LEGACY login seeded. The ported login writes only
 *   logged_in / user_id (host pages pass is_host explicitly as a
 *   render var), so the fragment resolves the role from the users
 *   table — the same source of truth every host-page guard uses.
 *   HostQuitHostingController's session->remove('is_host') stays
 *   harmless.
 *
 * DATA (panel, verbatim legacy):
 *   - unread COUNT (authoritative for the bell badge)
 *   - latest 8 notifications (id, message, link, is_read, created_at)
 *   - links parsed for booking-detail / listing-detail ids →
 *     TWO batch queries fetch the listings' cover photo + title
 *     (JOIN listings + LEFT JOIN listing_photos photo_type='cover')
 *   - message text first (EMOJI STRIPPED), derived label fallback
 *   - activity-type icon chips: [lucide-name, color-class] map —
 *     verbatim (message / accepted / declined / application /
 *     payment / review / booking / listing / hostapp / system).
 *     Rendered via <i data-lucide> + the lucide CDN (NOT the Twig
 *     icon macro — the macro table doesn't carry every name, and
 *     legacy never used it here).
 *   - try/catch: table missing → render NOTHING rather than break
 *     the page (error_log verbatim 'notification_dropdown:').
 *
 * MARK-READ: the panel JS posts to the ported endpoint
 *   POST /notifications/mark-read (NotificationsController::markRead)
 *   with the SAME body legacy sent — "id=<id>" or "all=1" — so the
 *   endpoint contract needed no changes.
 *
 * LUCIDE: legacy loaded lucide@latest itself ("double-loading is
 *   harmless (cached)"). The fragment keeps its own CDN script tag
 *   for the same reason — and it is REQUIRED on admin pages, where
 *   the shell renders inline-SVG macros and never loads lucide.
 *
 * PHOTO RESOLUTION: ndd_resolve_photo() verbatim, with the
 *   webprogg/ → root convention applied (bare filename →
 *   /uploads/listing_photos/cover/<name>; webprogg/-prefixed →
 *   root-relative; absolute/HTTP untouched). NOTE this differs
 *   from the controllers' resolvePhoto() cover_ rule on purpose —
 *   the dropdown only ever receives cover-photo paths.
 */
class NotificationDropdownController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /* =========================================================
       USER + HOST PANEL
         {{ render(controller('App\\Controller\\NotificationDropdownController::panel')) }}
    ========================================================= */
    public function panel(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int) $session->get('user_id');

        /* ---- Guests: nothing to show (legacy `return;`) ---- */
        if (!$session->get('logged_in') || $userId <= 0) {
            return new Response('');
        }

        /* ---- Role — DB is the source of truth (docblock) ---- */
        $isHost = false;
        try {
            $isHost = (int) ($this->db->fetchOne(
                'SELECT is_host FROM users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            ) ?? 0) === 1;
        } catch (\Throwable $e) {
            $isHost = false;
        }

        /* ROLE-AWARE "VIEW ALL" DESTINATION */
        $viewAllUrl = $isHost ? '/host/notifications' : '/notifications';

        $unread = 0;
        $items  = [];
        $ready  = true;

        try {
            /* Unread count (authoritative for the badge) */
            $unread = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            );

            /* Latest 8 notifications — message column INCLUDED */
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, message, link, is_read, created_at
                 FROM notifications
                 WHERE user_id = :u
                 ORDER BY created_at DESC
                 LIMIT 8',
                ['u' => $userId]
            );

            /* -----------------------------------------------------
               COLLECT booking/listing ids from the links so we can
               batch-fetch their cover photos (2 queries max).
            ----------------------------------------------------- */
            $bookingIds = [];
            $listingIds = [];
            $parsed     = [];

            foreach ($rows as $row) {
                $link  = (string) ($row['link'] ?? '');
                $query = parse_url($link, PHP_URL_QUERY);
                parse_str((string) $query, $qs);
                $path = strtolower((string) parse_url($link, PHP_URL_PATH));
                $id   = isset($qs['id']) && is_numeric($qs['id']) ? (int) $qs['id'] : 0;

                $parsed[(int) $row['id']] = ['path' => $path, 'id' => $id];

                if ($id > 0 && strpos($path, 'booking-detail') !== false) {
                    $bookingIds[] = $id;
                }
                if ($id > 0 && strpos($path, 'listing-detail') !== false) {
                    $listingIds[] = $id;
                }
            }

            $photos   = [];
            $ctxTitle = [];

            /* booking links → the booking's listing cover photo + title */
            if (!empty($bookingIds)) {
                $in = implode(',', array_map('intval', array_unique($bookingIds)));
                foreach ($this->db->fetchAllAssociative(
                    "SELECT b.id, l.title AS listing_title, p.photo_path
                     FROM bookings b
                     JOIN listings l ON l.id = b.listing_id
                     LEFT JOIN listing_photos p
                            ON p.listing_id = l.id AND p.photo_type = 'cover'
                     WHERE b.id IN ($in)"
                ) as $r) {
                    $photos['b' . (int) $r['id']]   = $this->nddResolvePhoto((string) $r['photo_path']);
                    $ctxTitle['b' . (int) $r['id']] = (string) $r['listing_title'];
                }
            }

            /* listing links → the listing's cover photo + title */
            if (!empty($listingIds)) {
                $in = implode(',', array_map('intval', array_unique($listingIds)));
                foreach ($this->db->fetchAllAssociative(
                    "SELECT l.id, l.title, p.photo_path
                     FROM listings l
                     LEFT JOIN listing_photos p
                            ON p.listing_id = l.id AND p.photo_type = 'cover'
                     WHERE l.id IN ($in)"
                ) as $r) {
                    $photos['L' . (int) $r['id']]   = $this->nddResolvePhoto((string) $r['photo_path']);
                    $ctxTitle['L' . (int) $r['id']] = (string) $r['title'];
                }
            }

            /* -----------------------------------------------------
               BUILD ITEMS — real message first (emoji stripped),
               derived label as fallback; photo when we found one.
            ----------------------------------------------------- */
            foreach ($rows as $row) {
                $link    = (string) ($row['link'] ?? '');
                $message = trim((string) ($row['message'] ?? ''));
                $id      = (int) $row['id'];

                $type = $this->nddType($link, $message);

                /* Message text with emoji stripped; strip the fallback too. */
                $label = $message !== ''
                    ? $this->nddStripEmoji($message)
                    : $this->nddLabel($link);
                if ($label === '') {
                    $label = $this->nddLabel($link);
                }

                $ctxKey = '';
                $imgKey = '';
                if (isset($parsed[$id])) {
                    $path = $parsed[$id]['path'];
                    $bid  = $parsed[$id]['id'];
                    if ($bid > 0 && strpos($path, 'booking-detail') !== false) {
                        $ctxKey = 'b' . $bid;
                        $imgKey = 'b' . $bid;
                    } elseif ($bid > 0 && strpos($path, 'listing-detail') !== false) {
                        $ctxKey = 'L' . $bid;
                        $imgKey = 'L' . $bid;
                    }
                }

                $img = ($imgKey !== '' && isset($photos[$imgKey])) ? $photos[$imgKey] : '';

                [$iconName, $iconClass] = $this->nddIcon($type);

                $items[] = [
                    'id'     => $id,
                    'link'   => $link !== '' ? $link : $viewAllUrl,
                    'unread' => ((int) $row['is_read']) === 0,
                    'ago'    => $this->nddTimeAgo((string) $row['created_at']),
                    'label'  => $label,
                    'icon'   => $iconName,
                    'icls'   => $iconClass,
                    'img'    => $img,
                    'ctx'    => ($ctxKey !== '' && isset($ctxTitle[$ctxKey])) ? $ctxTitle[$ctxKey] : '',
                ];
            }
        } catch (\Throwable $e) {
            /* notifications table missing → render nothing rather
               than break the page. Fix the table, refresh. */
            $ready = false;
            error_log('notification_dropdown: ' . $e->getMessage());
        }

        if (!$ready) {
            return new Response('');
        }

        return $this->render('partials/notification_dropdown.html.twig', [
            'items'       => $items,
            'unread'      => $unread,
            'viewall_url' => $viewAllUrl,
            'markable'    => true,
        ]);
    }

    /* =========================================================
       ADMIN PANEL — same panel, admin's OWN queue semantics
       (see docblock ADAPTATION note).
         {{ render(controller('App\\Controller\\NotificationDropdownController::adminPanel')) }}
    ========================================================= */
    public function adminPanel(Request $request): Response
    {
        $session = $request->getSession();

        /* Admin sessions use the separate admin_* keys (never the
           user-flow keys) — guests/non-admins: nothing to show. */
        if (!$session->get('admin_logged_in')) {
            return new Response('');
        }

        /* The exact two numbers the admin shell's static bell badge
           always showed (AdminController, verbatim SQL). */
        try {
            $pendingHostApps = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM host_applications WHERE status = 'pending'"
            );
            $pendingListings = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM listings WHERE status = 'pending'"
            );
        } catch (\Throwable $e) {
            error_log('notification_dropdown (admin): ' . $e->getMessage());
            return new Response('');
        }

        $unread = $pendingHostApps + $pendingListings;
        $items  = [];

        if ($pendingHostApps > 0) {
            [$icon, $icls] = $this->nddIcon('application');
            $items[] = [
                'id'     => 0,
                'link'   => '/admin/host-applications',
                'unread' => false,
                'ago'    => '',
                'label'  => $pendingHostApps . ' host application' . ($pendingHostApps === 1 ? '' : 's') . ' awaiting review',
                'ctx'    => 'Host applications queue',
                'icon'   => $icon,
                'icls'   => $icls,
                'img'    => '',
            ];
        }

        if ($pendingListings > 0) {
            [$icon, $icls] = $this->nddIcon('listing');
            $items[] = [
                'id'     => 0,
                'link'   => '/admin/listing-applications',
                'unread' => false,
                'ago'    => '',
                'label'  => $pendingListings . ' listing' . ($pendingListings === 1 ? '' : 's') . ' awaiting approval',
                'ctx'    => 'Listing approval queue',
                'icon'   => $icon,
                'icls'   => $icls,
                'img'    => '',
            ];
        }

        return $this->render('partials/notification_dropdown.html.twig', [
            'items'       => $items,
            'unread'      => $unread,
            'viewall_url' => '',    /* footer hidden — the items ARE the links */
            'markable'    => false, /* nothing to mark read in the queue */
        ]);
    }

    /* =====================================================================
     *  ndd_* HELPERS — verbatim legacy semantics (guarded with the
     *  ndd_ prefix instead of function_exists — same clash-proofing).
     * ================================================================= */

    private function nddTimeAgo(string $ts): string
    {
        $diff = time() - strtotime($ts);
        if ($diff < 60)     return 'Just now';
        if ($diff < 3600)   return floor($diff / 60) . 'm ago';
        if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M j, Y', strtotime($ts));
    }

    /* Strips emoji / dingbats / variation selectors so old rows
       that had emoji baked into their messages render clean. */
    private function nddStripEmoji(string $text): string
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

    private function nddLabel(string $link): string
    {
        $l = strtolower($link);

        if (strpos($l, 'message') !== false)        return 'New message';
        if (strpos($l, 'accept') !== false)         return 'Booking accepted';
        if (strpos($l, 'reject') !== false)         return 'Booking declined';
        if (strpos($l, 'pendingtenants') !== false) return 'New tenant application';
        if (strpos($l, 'payment') !== false)        return 'Payment received';
        if (
            strpos($l, 'review') !== false
            || strpos($l, 'rating') !== false
        )      return 'New review';
        if (strpos($l, 'booking') !== false)        return 'Booking update';
        if (strpos($l, 'listing') !== false)        return 'Listing update';
        if (strpos($l, 'hostprofile') !== false)    return 'Host application update';
        if (strpos($l, 'membership') !== false)     return 'Hive Club update';

        return 'New notification';
    }

    private function nddType(string $link, string $message): string
    {
        $l = strtolower($link);
        $m = strtolower($message);

        if (strpos($l, 'message') !== false || strpos($l, 'chat') !== false) return 'message';

        if (
            strpos($l, 'accept') !== false
            || strpos($m, 'accepted') !== false
            || strpos($m, 'approved') !== false
        )                            return 'accepted';

        if (
            strpos($l, 'reject') !== false
            || strpos($m, 'declined') !== false
            || strpos($m, 'rejected') !== false
        )                            return 'declined';

        if (
            strpos($l, 'pendingtenants') !== false
            || strpos($m, 'application') !== false
            || strpos($m, 'applied') !== false
            || strpos($m, 'new tenant') !== false
        )                          return 'application';

        if (
            strpos($l, 'payment') !== false
            || strpos($m, 'payment') !== false
            || strpos($m, 'paid') !== false
        )                                return 'payment';

        if (
            strpos($l, 'review') !== false
            || strpos($l, 'rating') !== false
            || strpos($m, 'review') !== false
        )                              return 'review';

        if (strpos($l, 'booking') !== false)      return 'booking';
        if (
            strpos($l, 'listing') !== false
            || strpos($m, 'listing') !== false
        )                             return 'listing';
        if (strpos($l, 'hostprofile') !== false)  return 'hostapp';

        return 'system';
    }

    /* LUCIDE ICONS per activity type — [lucide-icon-name, color-class].
       Verbatim map (rendered via data-lucide, NOT the Twig macro). */
    private function nddIcon(string $type): array
    {
        switch ($type) {
            case 'message':
                return ['message-square',  'rh-ndd-ic-blue'];   /* 💬 */
            case 'accepted':
                return ['calendar-check',  'rh-ndd-ic-green'];  /* ✅ */
            case 'declined':
                return ['circle-x',        'rh-ndd-ic-red'];    /* ❌ */
            case 'application':
                return ['user-clock',      'rh-ndd-ic-yellow']; /* 📝 */
            case 'payment':
                return ['wallet',          'rh-ndd-ic-green'];  /* 💰 */
            case 'review':
                return ['star',            'rh-ndd-ic-yellow']; /* ⭐ */
            case 'booking':
                return ['calendar-days',   'rh-ndd-ic-blue'];   /* 📅 */
            case 'listing':
                return ['building-2',      'rh-ndd-ic-honey'];  /* 🏠 */
            case 'hostapp':
                return ['user-round',      'rh-ndd-ic-purple']; /* 👤 */
            default:
                return ['bell',            'rh-ndd-ic-gray'];   /* 🔔 */
        }
    }

    /* Resolve a stored photo path to an absolute web path.
       VERBATIM legacy rules, webprogg/ → root applied:
         bare filename → cover convention (same as listing.php) */
    private function nddResolvePhoto(string $path): string
    {
        $path = trim($path);
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
        /* Bare filename → listing cover convention */
        if (strpos($p, '/') === false) {
            return '/uploads/listing_photos/cover/' . $p;
        }
        return '/' . $p;
    }
}
