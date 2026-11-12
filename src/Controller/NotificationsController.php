<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Notifications (full page, BOTH roles).
 * 1:1 port of legacy user/notifications.php — the bell dropdown's
 * "View all notifications" target. Notifications are keyed by user_id,
 * so whoever is logged in sees their own (hosts AND renters).
 *
 *   GET  /notifications            → paginated list (20/page)
 *   POST /notifications/mark-read  → {id: N} or {all: 1}
 *                                    (legacy mark-notifications-read.php)
 *
 * Legacy behaviour kept intact:
 *   - Activity type derived from the stored link/message
 *     (message/accepted/declined/application/payment/review/booking/
 *     listing/system) → lucide icon + colour chip, same mapping as the
 *     bell dropdown.
 *   - Emoji stripped from message text; batch-fetched cover photos and
 *     listing titles for booking/listing links; "n unread" pill +
 *     Mark-all-as-read button only when there are unread rows.
 *   - Item click marks read then navigates to the stored link (legacy
 *     /webprogg/... links stay as stored — they 404 until those pages
 *     are ported).
 *
 * Port notes:
 *   - Legacy rendered a host shell for hosts; the host dashboard shell
 *     isn't ported yet, so BOTH roles currently get the renter account
 *     shell (sidebar + navbar) — the list markup is identical anyway.
 *   - np_time_ago / np_strip_emoji / np_type / np_icon moved into
 *     controller methods; Twig just renders.
 */
class NotificationsController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(private readonly Connection $db) {}

    #[Route('/notifications', name: 'app_notifications', methods: ['GET'], priority: 2)]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $viewer = $this->db->fetchAssociative(
            'SELECT id, name, avatar_path, is_host, created_at FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if (!$viewer) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        $viewer['id'] = (int)$viewer['id'];
        $viewerIsHost = !empty($viewer['is_host']);

        /* real unread count — navbar badge + "n unread" pill, one query */
        $unreadCount = (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
            ['u' => $userId]
        );

        /* -----------------------------------------------------
           PAGINATION + DATA
        ----------------------------------------------------- */
        $page = max(1, $request->query->getInt('page', 1));

        $totalItems = (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :u',
            ['u' => $userId]
        );
        $totalPages = max(1, (int)ceil($totalItems / self::PER_PAGE));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * self::PER_PAGE;
        $perPage    = self::PER_PAGE;

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, message, link, is_read, created_at
               FROM notifications
              WHERE user_id = :u
              ORDER BY created_at DESC
              LIMIT $perPage OFFSET $offset",
            ['u' => $userId]
        );

        /* batch-fetch cover photos + titles for booking/listing links */
        $bookingIds = [];
        $listingIds = [];
        $parsed     = [];

        foreach ($rows as $row) {
            $link = (string)($row['link'] ?? '');
            parse_str((string)parse_url($link, PHP_URL_QUERY), $qs);
            $path = strtolower((string)parse_url($link, PHP_URL_PATH));
            $id   = isset($qs['id']) && is_numeric($qs['id']) ? (int)$qs['id'] : 0;

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
                $photos['b' . (int)$r['id']] = $this->resolvePhoto($r['photo_path'] ?? null);
                $titles['b' . (int)$r['id']] = (string)$r['title'];
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
                $photos['L' . (int)$r['id']] = $this->resolvePhoto($r['photo_path'] ?? null);
                $titles['L' . (int)$r['id']] = (string)$r['title'];
            }
        }

        /* -----------------------------------------------------
           BUILD ITEMS (shared markup, both shells in legacy)
        ----------------------------------------------------- */
        $items = [];

        foreach ($rows as $row) {
            $id      = (int)$row['id'];
            $link    = (string)($row['link'] ?? '');
            $message = trim((string)($row['message'] ?? ''));
            $isRead  = (int)$row['is_read'];

            $type = $this->deriveType($link, $message);
            [$iconName, $icls] = $this->typeIcon($type);

            /* emoji stripped; fall back to a derived label if the
               message becomes empty after stripping */
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
                'id'    => $id,
                'link'  => $link !== '' ? $link : '/notifications',
                'icon'  => $iconName,
                'icls'  => $icls,
                'img'   => ($imgKey !== '' && isset($photos[$imgKey])) ? $photos[$imgKey] : '',
                'ctx'   => ($ctxKey !== '' && isset($titles[$ctxKey])) ? $titles[$ctxKey] : '',
                'label' => $label,
                'time'  => $this->timeAgo((string)$row['created_at']),
                'unread' => !$isRead,
            ];
        }

        return $this->render('user/notifications.html.twig', [
            'user'          => $viewer,
            'is_host'       => $viewerIsHost,
            'items'         => $items,
            'unread_count'  => $unreadCount,
            'notif_count'   => $unreadCount,
            'page'          => $page,
            'total_pages'   => $totalPages,
        ]);
    }

    /* =====================================================================
     |  MARK READ — legacy notifications/mark-notifications-read.php
     | ================================================================== */

    #[Route('/notifications/mark-read', name: 'app_notifications_mark_read', methods: ['POST'], priority: 2)]
    public function markRead(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(['ok' => false], 401);
        }

        try {
            if ($request->request->get('all')) {
                $this->db->executeStatement(
                    'UPDATE notifications SET is_read = 1 WHERE user_id = :u AND is_read = 0',
                    ['u' => $userId]
                );
            } else {
                $id = (int)$request->request->get('id');
                if ($id > 0) {
                    $this->db->executeStatement(
                        'UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :u',
                        ['id' => $id, 'u' => $userId]
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('notifications mark-read failed: ' . $e->getMessage());
            return $this->json(['ok' => false], 500);
        }

        return $this->json(['ok' => true]);
    }

    /* =====================================================================
     |  HELPERS — np_* from legacy, now controller methods
     | ================================================================== */

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
        return trim((string)preg_replace('/\s{2,}/', ' ', (string)$clean));
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
     * the bell dropdown (ndd_icon) so both views match.
     * [lucide-icon-name, color-class]
     */
    private function typeIcon(string $type): array
    {
        switch ($type) {
            case 'message':
                return ['message-square', 'np-ic-blue'];
            case 'accepted':
                return ['calendar-check', 'np-ic-green'];
            case 'declined':
                return ['circle-x',       'np-ic-red'];
            case 'application':
                return ['user-clock',     'np-ic-yellow'];
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
     * Legacy np_resolve_photo(): http(s) stays as-is; a stored 'webprogg/'
     * prefix is stripped to the site root; a bare filename is a cover
     * photo inside uploads/listing_photos/cover/.
     */
    private function resolvePhoto(?string $path): string
    {
        $path = trim((string)$path);
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
}
