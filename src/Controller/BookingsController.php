<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — My Bookings (renter side). 1:1 of legacy
 * booking/userbookings.php on the shared account shell
 * (partials/navbar.html.twig + partials/sidebar.html.twig,
 * sidebar highlight key 'bookings').
 *
 *   GET /bookings[?status=all|pending|confirmed|completed|cancelled|rejected]
 *
 * Status tabs (same set as the host side): All · Pending ·
 * Confirmed · Completed · Cancelled · Declined. DB status for the
 * "Declined" tab is 'rejected' (same mapping HostBookingsController
 * uses). Each tab shows its live count; the header shows the
 * all-status total.
 *
 * Feeds templates/user/bookings.html.twig with:
 *   bookings          — rows: id, title, location, dates, status,
 *                       cover, cover_url, payment_state, total_fmt,
 *                       paid_fmt, left_fmt, show_refund, refunded_fmt
 *   bookings_total    — all-status count (header "My Bookings (n)")
 *   tabs              — key => label map (all…rejected)
 *   status_counts     — key => count map (includes 'all')
 *   status_filter     — active tab key
 *   is_host / nav_avatar / notification_count — shell contract
 *
 * THIS VERSION — COVER PHOTO + ROW FIX:
 *   The booking query now pulls the listing's cover photo (same
 *   subselect as HostBookingsController: photo_type = 'cover',
 *   oldest first) and resolves it through the shared resolvePhoto()
 *   mapping (strips the legacy webprogg/ prefix, maps bare
 *   cover_xxx.jpg names to /uploads/listing_photos/cover/, passes
 *   full uploads/ paths through). The template renders the real
 *   photo when cover is set and keeps the house-icon placeholder
 *   otherwise — previously the controller never supplied cover /
 *   cover_url, so every row rendered the grey placeholder.
 *
 * Payment display (same logic as the receipt + AccountController):
 *   paid > 0 and nothing left  → "Fully Paid"  + total
 *   paid > 0, balance left     → "Reserve Paid" + paid + "₱n left" pill
 *   nothing paid               → "Booking Total" + total
 *   cancelled with money paid  → refunded-to-wallet row (green
 *                                 amount + "✓ Credited" pill)
 */
class BookingsController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/bookings', name: 'app_bookings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as NotificationsController) ---- */
        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* ---- Load the viewer row ---- */
        $viewer = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host, created_at
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if (!$viewer) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }

        $viewerIsHost = !empty($viewer['is_host']);

        /* ---- Navbar bell badge (best-effort, never breaks the page) ---- */
        $notificationCount = 0;
        try {
            $notificationCount = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            );
        } catch (\Throwable $e) {
            $notificationCount = 0;
        }

        /* ---- Navbar avatar (same fallback as AccountController) ---- */
        $navAvatar = !empty($viewer['avatar_path'])
            ? $this->resolvePhoto((string) $viewer['avatar_path'])
            : '/images/default-avatar.png';
        if ($navAvatar === '') {
            $navAvatar = '/images/default-avatar.png';
        }

        /* =====================================================
           BOOKINGS — every booking of this user, newest first,
           with the listing's cover photo (same subselect as
           HostBookingsController, verbatim shape).
        ====================================================== */
        $bookingRows = $this->db->fetchAllAssociative(
            "SELECT b.id,
                    b.status,
                    b.total,
                    b.amount_paid,
                    b.booked_at,
                    l.title,
                    l.location,
                    (SELECT lp.photo_path
                     FROM listing_photos lp
                     WHERE lp.listing_id = l.id AND lp.photo_type = 'cover'
                     ORDER BY lp.created_at ASC
                     LIMIT 1) AS cover_photo
             FROM bookings b
             INNER JOIN listings l ON l.id = b.listing_id
             WHERE b.user_id = :id
             ORDER BY b.booked_at DESC, b.id DESC",
            ['id' => $userId]
        );

        /* =====================================================
           STATUS TABS + COUNTS — count every row once, then the
           active tab filters what is DISPLAYED (counts stay
           honest regardless of the filter).
        ====================================================== */
        $tabs = [
            'all'       => 'All',
            'pending'   => 'Pending',
            'confirmed' => 'Confirmed',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'rejected'  => 'Declined',
        ];

        $statusCounts = [
            'all'       => 0,
            'pending'   => 0,
            'confirmed' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'rejected'  => 0,
        ];
        foreach ($bookingRows as $row) {
            $s = (string) $row['status'];
            if (array_key_exists($s, $statusCounts)) {
                $statusCounts[$s]++;
            }
            $statusCounts['all']++;
        }

        $statusFilter = (string) $request->query->get('status', 'all');
        if (!array_key_exists($statusFilter, $tabs)) {
            $statusFilter = 'all';
        }

        $bookingsTotal = $statusCounts['all'];

        /* =====================================================
           DISPLAY ROWS — tab filter + payment display + cover.
        ====================================================== */
        $bookings = [];
        foreach ($bookingRows as $row) {
            $status = (string) $row['status'];

            /* Tab filter ('all' shows everything) */
            if ($statusFilter !== 'all' && $status !== $statusFilter) {
                continue;
            }

            $total = (float) ($row['total'] ?? 0);
            $paid  = (float) ($row['amount_paid'] ?? 0);
            $left  = round(max(0, $total - $paid), 2);

            /* Payment state — same thresholds as AccountController */
            if ($paid > 0.005 && $left <= 0.005) {
                $payState = 'full';
            } elseif ($paid > 0.005) {
                $payState = 'advance';
            } else {
                $payState = 'none';
            }

            /* Cancelled with money paid → wallet refund display */
            $showRefund = ($status === 'cancelled' && $paid > 0.005);

            /* Cover photo — '' when the listing has none, so the
               template keeps the house-icon placeholder */
            $coverPath = trim((string) ($row['cover_photo'] ?? ''));
            $coverUrl  = $coverPath !== '' ? $this->resolvePhoto($coverPath) : '';

            $bookings[] = [
                'id'           => (int) $row['id'],
                'title'        => (string) $row['title'],
                'location'     => (string) $row['location'],
                'dates'        => !empty($row['booked_at'])
                    ? date('M j, Y', strtotime((string) $row['booked_at']))
                    : '',
                'status'       => $status,
                'cover'        => $coverUrl !== '',
                'cover_url'    => $coverUrl,
                'payment_state' => $payState,
                'total_fmt'    => number_format($total, 2),
                'paid_fmt'     => number_format($paid, 2),
                'left_fmt'     => number_format($left, 2),
                'show_refund'  => $showRefund,
                'refunded_fmt' => number_format($paid, 2),
            ];
        }

        return $this->render('user/bookings.html.twig', [
            'is_host'           => $viewerIsHost,
            'nav_avatar'        => $navAvatar,
            'notification_count' => $notificationCount,

            'bookings'          => $bookings,
            'bookings_total'    => $bookingsTotal,
            'tabs'              => $tabs,
            'status_counts'     => $statusCounts,
            'status_filter'     => $statusFilter,
        ]);
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same mapping as HostBookingsController /
     *  AccountController / AdminController / HostPayoutsController.
     *
     *  Legacy DB shapes handled:
     *    uploads/listing_photos/cover/cover_x.jpg  → /uploads/… (pass-through)
     *    /webprogg/uploads/…                       → prefix stripped → /uploads/…
     *    cover_xxx.jpg (bare name)                 → /uploads/listing_photos/cover/…
     *    listing_photos/additional_xxx.jpg         → /uploads/listing_photos/…
     *    https://…                                 → untouched
     *  Empty input → '' (caller decides the placeholder).
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
