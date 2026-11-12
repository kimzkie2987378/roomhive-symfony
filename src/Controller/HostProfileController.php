<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host Profile (Overview dashboard).
 * 1:1 port of legacy host/hostprofile.php.
 *
 *   GET /host/profile   → the host's landing dashboard: profile info
 *     card (with camera-upload avatar), live performance overview
 *     (4 mini charts), daily earnings SVG area chart, 6-month
 *     earnings trend bar chart, and the My Listings table with
 *     current-occupant strips + accept/reject/delete actions.
 *
 * Auth: user session (logged_in + user_id). The host guard
 *   (is_host = 1) redirects non-hosts to /host/become-a-host.
 *
 * SCHEMA NOTE: bookings has NO `total` and NO `paid_at`. Money =
 *   host_payout_amount (falls back to amount_paid); confirmed/
 *   completed status is treated as paid. Same convention as
 *   AdminPayoutsController.
 *
 * OCCUPANCY: a listing whose stay window covers TODAY (confirmed
 *   or completed booking; checkout NULL = ongoing monthly stay)
 *   shows an "Occupied" status badge + the current tenant's
 *   details (avatar, name, email, phone, check-in → check-out).
 *   Occupied listings cannot be deleted (client-side lock +
 *   server-side guard in the delete endpoint when ported).
 *
 * ACTION ENDPOINTS (TODO — not yet ported): the accept/reject/
 *   delete/avatar-upload buttons POST to:
 *     /host/bookings/{id}/accept   (legacy accept-booking.php)
 *     /host/bookings/{id}/reject   (legacy reject-booking.php)
 *     /host/listings/{id}/delete   (legacy delete-listing.php)
 *     /host/profile/avatar         (legacy uploadavatar.php)
 *   When you paste those legacy files, create the routes and the
 *   JS in the template will work as-is.
 */
class HostProfileController extends AbstractController
{
    private const OCCUPANCY_WINDOW_DAYS = 30;

    public function __construct(private readonly Connection $db) {}

    #[Route('/host/profile', name: 'app_host_profile', methods: ['GET'])]
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
            'SELECT id, name, email, phone, age, avatar_path, location,
                    password, is_host, created_at
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
           BADGE COUNTS — navbar bell + sidebar pending tenants
        ========================================================= */
        $notificationCount = $this->countUnreadNotifications($userId);
        $pendingTenantsCount = $this->countPendingTenants($userId);

        /* ---- Host array (mirrors legacy $host from host_init) ---- */
        $host = [
            'name'         => (string) $dbUser['name'],
            'avatar'       => !empty($dbUser['avatar_path'])
                ? $this->resolvePhoto((string) $dbUser['avatar_path'], '/images/default-avatar.png')
                : '/images/default-avatar.png',
            'member_since' => date('F Y', strtotime((string) $dbUser['created_at'])),
            'location'     => (string) ($dbUser['location'] ?? ''),
            'email'        => (string) $dbUser['email'],
            'phone'        => (string) ($dbUser['phone'] ?? ''),
            'age'          => (string) ($dbUser['age'] ?? ''),
            'about'        => '', /* users table has no `about` column yet */
        ];

        /* =========================================================
           MY LISTINGS — with cover photo + pending booking + tenant
        ========================================================= */
        $listings = $this->db->fetchAllAssociative(
            "SELECT l.id, l.title, l.location, l.exact_address, l.price,
                    l.status, l.created_at,
                    p.photo_path AS cover_photo,
                    pb.id AS pending_booking_id,
                    tu.name AS pending_tenant_name
             FROM listings l
             LEFT JOIN listing_photos p
                    ON p.listing_id = l.id AND p.photo_type = 'cover'
             LEFT JOIN bookings pb ON pb.listing_id = l.id AND pb.status = 'pending'
             LEFT JOIN users tu ON tu.id = pb.user_id
             WHERE l.user_id = :id
             ORDER BY l.created_at DESC",
            ['id' => $userId]
        );

        $listingsTotal = count($listings);

        /* Normalize each listing row for the template */
        $listings = array_map(function (array $l): array {
            return [
                'id'                   => (int) $l['id'],
                'title'                => (string) $l['title'],
                'location'             => (string) $l['location'],
                'exact_address'        => (string) $l['exact_address'],
                'price'                => (float) $l['price'],
                'status'               => (string) $l['status'],
                'created_at'           => (string) $l['created_at'],
                'cover_photo'          => $this->resolvePhoto(
                    (string) ($l['cover_photo'] ?? ''),
                    '/images/ListingPlaceholder.png'
                ),
                'pending_booking_id'  => $l['pending_booking_id'] !== null ? (int) $l['pending_booking_id'] : null,
                'pending_tenant_name' => (string) ($l['pending_tenant_name'] ?? ''),
            ];
        }, $listings);

        /* =========================================================
           CURRENT OCCUPANTS — confirmed/completed bookings covering
           today. Keep the most recent stay per listing.
        ========================================================= */
        $occupantRows = $this->db->fetchAllAssociative(
            "SELECT b.listing_id, b.id AS booking_id,
                    b.checkin_date, b.checkout_date,
                    u.id AS tenant_id, u.name AS tenant_name,
                    u.email AS tenant_email, u.phone AS tenant_phone,
                    u.avatar_path AS tenant_avatar
             FROM bookings b
             JOIN listings l ON l.id = b.listing_id
             JOIN users u ON u.id = b.user_id
             WHERE l.user_id = :id
               AND b.status IN ('confirmed', 'completed')
               AND b.checkin_date IS NOT NULL
               AND b.checkin_date <= CURDATE()
               AND (b.checkout_date IS NULL OR b.checkout_date >= CURDATE())
             ORDER BY b.checkin_date DESC",
            ['id' => $userId]
        );

        $currentOccupants = [];
        foreach ($occupantRows as $row) {
            $lid = (int) $row['listing_id'];
            if (isset($currentOccupants[$lid])) {
                continue; /* keep the most recent stay per listing */
            }
            $currentOccupants[$lid] = [
                'booking_id' => (int) $row['booking_id'],
                'name'       => (string) $row['tenant_name'],
                'email'      => (string) $row['tenant_email'],
                'phone'      => (string) ($row['tenant_phone'] ?? ''),
                'avatar'     => !empty($row['tenant_avatar'])
                    ? $this->resolvePhoto((string) $row['tenant_avatar'], '/images/default-avatar.png')
                    : '/images/default-avatar.png',
                'checkin'    => !empty($row['checkin_date'])
                    ? date('M j, Y', strtotime((string) $row['checkin_date']))
                    : '—',
                'checkout'   => !empty($row['checkout_date'])
                    ? date('M j, Y', strtotime((string) $row['checkout_date']))
                    : 'Ongoing',
            ];
        }

        /* =========================================================
           BOOKING-BASED METRICS (all-time — feeds listing rows)
        ========================================================= */
        $hostBookings = $this->db->fetchAllAssociative(
            "SELECT b.listing_id, b.amount_paid, b.host_payout_amount,
                    b.checkin_date, b.checkout_date
             FROM bookings b
             JOIN listings l ON l.id = b.listing_id
             WHERE l.user_id = :id
               AND b.status IN ('confirmed', 'completed')",
            ['id' => $userId]
        );

        $windowStart = new \DateTime('-' . self::OCCUPANCY_WINDOW_DAYS . ' days');
        $windowEnd   = new \DateTime('today');

        $listingStats = [];
        foreach ($listings as $l) {
            $listingStats[$l['id']] = [
                'bookings'        => 0,
                'earnings'        => 0.0,
                'occupied_nights' => 0,
            ];
        }

        foreach ($hostBookings as $b) {
            $lid = (int) $b['listing_id'];
            if (!isset($listingStats[$lid])) {
                continue;
            }
            $listingStats[$lid]['bookings']++;
            $listingStats[$lid]['earnings'] += (float) (
                $b['host_payout_amount'] !== null
                ? $b['host_payout_amount']
                : $b['amount_paid']
            );
            $listingStats[$lid]['occupied_nights'] += $this->overlapNights(
                (string) $b['checkin_date'],
                (string) $b['checkout_date'],
                $windowStart,
                $windowEnd
            );
        }

        $totalBookings       = 0;
        $totalEarnings       = 0.0;
        $totalOccupiedNights = 0;
        foreach ($listingStats as $stats) {
            $totalBookings       += $stats['bookings'];
            $totalEarnings       += $stats['earnings'];
            $totalOccupiedNights += $stats['occupied_nights'];
        }

        $occupancyRate = $listingsTotal > 0
            ? (int) round(min(100, ($totalOccupiedNights / (self::OCCUPANCY_WINDOW_DAYS * $listingsTotal)) * 100))
            : 0;

        /* Per-listing occupancy (for the table) */
        foreach ($listings as &$l) {
            $stats = $listingStats[$l['id']];
            $l['stats_bookings']        = $stats['bookings'];
            $l['stats_earnings']        = $stats['earnings'];
            $l['stats_occupied_nights'] = $stats['occupied_nights'];
            $l['listing_occupancy']    = self::OCCUPANCY_WINDOW_DAYS > 0
                ? (int) round(min(100, ($stats['occupied_nights'] / self::OCCUPANCY_WINDOW_DAYS) * 100))
                : 0;
            $l['is_occupied'] = isset($currentOccupants[$l['id']]);
            $l['occupant']    = $l['is_occupied'] ? $currentOccupants[$l['id']] : null;
        }
        unset($l);

        /* =========================================================
           GREETING + TENURE
        ========================================================= */
        $hour = (int) date('G');
        $greeting = $hour < 12 ? 'Good morning'
            : ($hour < 18 ? 'Good afternoon' : 'Good evening');
        $firstName = explode(' ', trim($host['name']))[0];
        $daysAsHost = max(1, (int) floor((time() - strtotime((string) $dbUser['created_at'])) / 86400));

        /* =========================================================
           MONTHLY EARNINGS — last 6 months (real query)
        ========================================================= */
        $monthTotals = [];
        try {
            $monthlyRows = $this->db->fetchAllAssociative(
                "SELECT DATE_FORMAT(b.booked_at, '%Y-%m') AS ym,
                        SUM(CASE
                                WHEN b.host_payout_amount IS NULL THEN b.amount_paid
                                ELSE b.host_payout_amount
                            END) AS month_total
                 FROM bookings b
                 JOIN listings l ON l.id = b.listing_id
                 WHERE l.user_id = :id
                   AND b.status IN ('confirmed', 'completed')
                   AND b.booked_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                 GROUP BY DATE_FORMAT(b.booked_at, '%Y-%m')",
                ['id' => $userId]
            );
            foreach ($monthlyRows as $row) {
                $monthTotals[$row['ym']] = (float) $row['month_total'];
            }
        } catch (\Throwable $e) {
            error_log('hostprofile: monthly earnings failed: ' . $e->getMessage());
        }

        $earnMonths    = [];
        $sixMonthTotal = 0.0;
        for ($i = 5; $i >= 0; $i--) {
            $key   = date('Y-m', strtotime("-{$i} months"));
            $total = $monthTotals[$key] ?? 0.0;
            $sixMonthTotal += $total;
            $earnMonths[] = [
                'label' => date('M', strtotime("-{$i} months")),
                'total' => $total,
                'pct'   => 0, /* filled below */
            ];
        }
        $maxMonthTotal = 0.0;
        foreach ($earnMonths as $m) {
            $maxMonthTotal = max($maxMonthTotal, $m['total']);
        }
        foreach ($earnMonths as &$m) {
            $m['pct'] = $maxMonthTotal > 0
                ? (int) round(($m['total'] / $maxMonthTotal) * 100)
                : 0;
        }
        unset($m);

        /* =========================================================
           DAILY ACTIVITY — last 30 days (real query)
        ========================================================= */
        $dailyMap = [];
        try {
            $dailyRows = $this->db->fetchAllAssociative(
                "SELECT DATE(b.booked_at) AS d,
                        COUNT(*) AS bookings,
                        SUM(CASE
                                WHEN b.status IN ('confirmed', 'completed')
                                THEN COALESCE(b.host_payout_amount, b.amount_paid, 0)
                                ELSE 0
                            END) AS earnings
                 FROM bookings b
                 JOIN listings l ON l.id = b.listing_id
                 WHERE l.user_id = :id
                   AND b.booked_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                 GROUP BY DATE(b.booked_at)",
                ['id' => $userId]
            );
            foreach ($dailyRows as $row) {
                $dailyMap[$row['d']] = [
                    'bookings' => (int) $row['bookings'],
                    'earnings' => (float) $row['earnings'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('hostprofile: daily activity failed: ' . $e->getMessage());
        }

        $dailySeries  = [];
        $bookings30d  = 0;
        $earnings30d  = 0.0;
        for ($i = 29; $i >= 0; $i--) {
            $ts  = strtotime("-{$i} days");
            $key = date('Y-m-d', $ts);
            $bk  = $dailyMap[$key]['bookings'] ?? 0;
            $er  = $dailyMap[$key]['earnings'] ?? 0.0;
            $bookings30d += $bk;
            $earnings30d += $er;
            $dailySeries[] = [
                'label'    => date('M j', $ts),
                'bookings' => $bk,
                'earnings' => $er,
                'spark_h' => 0,   /* filled below */
                'heat_a'   => 0.0, /* filled below */
                'pv_x'     => 0.0, /* filled below */
                'pv_y'     => 0.0, /* filled below */
            ];
        }

        $n30 = count($dailySeries);
        $dailyBookMax    = 0;
        $dailyEarnMaxRaw = 0.0;
        foreach ($dailySeries as $p) {
            $dailyBookMax    = max($dailyBookMax, $p['bookings']);
            $dailyEarnMaxRaw = max($dailyEarnMaxRaw, $p['earnings']);
        }
        $chartEarnMax = max($dailyEarnMaxRaw, 0.01);

        /* Fill the per-day chart values */
        $pvPoints = [];
        foreach ($dailySeries as $i => &$p) {
            $p['spark_h'] = $dailyBookMax > 0
                ? max(6, (int) round(($p['bookings'] / $dailyBookMax) * 100))
                : 6;
            $p['heat_a'] = $dailyEarnMaxRaw > 0
                ? max(0.08, round($p['earnings'] / $dailyEarnMaxRaw, 2))
                : 0.08;
            $x = 8 + ($i / max(1, $n30 - 1)) * 584;
            $y = 18 + (1 - ($p['earnings'] / $chartEarnMax)) * 132;
            $p['pv_x'] = round($x, 1);
            $p['pv_y'] = round($y, 1);
            $pvPoints[] = round($x, 1) . ',' . round($y, 1);
        }
        unset($p);
        $pvPointsStr = implode(' ', $pvPoints);
        $pvAreaPath  = 'M8,150 L' . implode(' L', $pvPoints) . ' L592,150 Z';

        /* =========================================================
           BOOKING STATUS MIX (for the stacked bar card)
        ========================================================= */
        $statusMix = [];
        try {
            $mixRows = $this->db->fetchAllAssociative(
                "SELECT b.status, COUNT(*) AS c
                 FROM bookings b
                 JOIN listings l ON l.id = b.listing_id
                 WHERE l.user_id = :id
                 GROUP BY b.status",
                ['id' => $userId]
            );
            foreach ($mixRows as $row) {
                $statusMix[$row['status']] = (int) $row['c'];
            }
        } catch (\Throwable $e) {
            error_log('hostprofile: status mix failed: ' . $e->getMessage());
        }

        $mixTotal = array_sum($statusMix);
        $mixSegments = [
            ['label' => 'Pending',    'count' => $statusMix['pending'] ?? 0,   'color' => '#eda423'],
            ['label' => 'Confirmed',  'count' => $statusMix['confirmed'] ?? 0, 'color' => '#1fa971'],
            ['label' => 'Completed',  'count' => $statusMix['completed'] ?? 0, 'color' => '#33517E'],
            [
                'label' => 'Cancelled / Rejected',
                'count' => ($statusMix['cancelled'] ?? 0) + ($statusMix['rejected'] ?? 0),
                'color' => '#e0524d',
            ],
        ];
        /* Pre-compute width % per segment */
        foreach ($mixSegments as &$seg) {
            $seg['pct'] = $mixTotal > 0 ? round(($seg['count'] / $mixTotal) * 100, 1) : 0.0;
        }
        unset($seg);

        /* =========================================================
           STATUS CLASS + LABEL MAPS (for listing rows)
        ========================================================= */
        $statusClassMap = [
            'approved'  => 'hp-status-active',
            'pending'   => 'hp-status-pending',
            'rejected'  => 'hp-status-rejected',
            'unlisted'  => 'hp-status-unlisted',
        ];
        $statusLabelMap = [
            'approved'  => 'Active',
            'pending'   => 'Pending',
            'rejected'  => 'Rejected',
            'unlisted'  => 'Unlisted',
        ];

        /* ---- X-axis labels for the daily chart (6 sample points) ---- */
        $dailyXLabels = [];
        foreach ([0, 6, 12, 18, 24, 29] as $xi) {
            $dailyXLabels[] = $dailySeries[$xi]['label'] ?? '';
        }

        return $this->render('host/profile.html.twig', [
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            'greeting'    => $greeting,
            'first_name'  => $firstName,
            'days_as_host' => $daysAsHost,

            'listings'         => $listings,
            'listings_total'   => $listingsTotal,
            'current_occupants' => $currentOccupants,

            'total_bookings'        => $totalBookings,
            'total_earnings'        => $totalEarnings,
            'total_occupied_nights' => $totalOccupiedNights,
            'occupancy_rate'        => $occupancyRate,
            'occupancy_window_days' => self::OCCUPANCY_WINDOW_DAYS,

            'bookings_30d'    => $bookings30d,
            'earnings_30d'    => $earnings30d,

            'daily_series'     => $dailySeries,
            'daily_book_max'   => $dailyBookMax,
            'daily_earn_max'   => $dailyEarnMaxRaw,
            'chart_earn_max'   => $chartEarnMax,
            'pv_points_str'    => $pvPointsStr,
            'pv_area_path'     => $pvAreaPath,
            'daily_x_labels'  => $dailyXLabels,

            'earn_months'      => $earnMonths,
            'six_month_total'  => $sixMonthTotal,

            'mix_segments'    => $mixSegments,
            'mix_total'        => $mixTotal,

            'status_class_map' => $statusClassMap,
            'status_label_map' => $statusLabelMap,

            'active_nav' => 'overview',
        ]);
    }

    /* =====================================================================
     *  OVERLAP NIGHTS — count nights a booking overlaps with the window.
     *  Mirrors the legacy hp_overlap_nights() helper.
     * ================================================================= */

    private function overlapNights(string $checkin, string $checkout, \DateTime $windowStart, \DateTime $windowEnd): int
    {
        if ($checkin === '') {
            return 0;
        }
        try {
            $start = new \DateTime($checkin);
        } catch (\Throwable $e) {
            return 0;
        }
        try {
            $end = $checkout !== ''
                ? new \DateTime($checkout)
                : (clone $windowEnd)->modify('+1 day');
        } catch (\Throwable $e) {
            $end = (clone $windowEnd)->modify('+1 day');
        }

        $overlapStart = max($start, $windowStart);
        $overlapEnd   = min($end, $windowEnd);

        if ($overlapEnd <= $overlapStart) {
            return 0;
        }
        return (int) $overlapStart->diff($overlapEnd)->days;
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same as AdminListingsController.
     *  Handles legacy webprogg/-relative + host/ + uploads/ + bare.
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
        return '/uploads/' . $path;
    }

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
}
