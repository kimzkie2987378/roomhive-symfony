<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Staff Dashboard.
 * Dedicated controller for the staff portal at /staff.
 */
final class StaffController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    /* ---- chart geometry constants ---- */
    private const PLOT_X      = 8;
    private const PLOT_W      = 584;
    private const PLOT_TOP    = 18;
    private const PLOT_BOTTOM = 150;
    private const PLOT_H      = self::PLOT_BOTTOM - self::PLOT_TOP; // 132
    private const DONUT_R     = 40;

    /* ---- color maps ---- */
    private const STATUS_COLOR_MAP = [
        'confirmed' => '#2FA84F',
        'completed' => '#2F7DE1',
        'cancelled' => '#E14B4B',
        'pending'   => '#F5A623',
        'rejected'  => '#B03A3A',
        'failed'    => '#B03A3A',
        'refunded'  => '#9B6BC3',
    ];

    private const LISTING_COLOR_MAP = [
        'approved' => '#2FA84F',
        'pending'  => '#F5A623',
        'rejected' => '#E14B4B',
        'unlisted' => '#8B93A6',
        'draft'    => '#C7CDD9',
    ];

    private const FALLBACK_PALETTE = ['#8B93A6', '#5BC0BE', '#E58BB1', '#C7A252', '#7A8CE8', '#9B6BC3'];

    private const BADGE_CLASS_MAP = [
        'Pending'    => 'badge-pending',
        'Approved'   => 'badge-approved',
        'Confirmed'  => 'badge-confirmed',
        'Cancelled'  => 'badge-cancelled',
        'Completed'  => 'badge-completed',
        'Rejected'   => 'badge-rejected',
        'Processing' => 'badge-completed',
        'Failed'     => 'badge-rejected',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/staff', name: 'app_staff_dashboard', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->get('admin_logged_in')) {
            return $this->redirect('/staff/login');
        }

        $staffName  = (string) ($session->get('admin_name')  ?? 'Staff');
        $staffEmail = (string) ($session->get('admin_email') ?? '');

        /* ---- CSRF token (per-session) ---- */
        $staffCsrf = (string) $session->get(self::CSRF_KEY);
        if ($staffCsrf === '') {
            $staffCsrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $staffCsrf);
        }

        /* =========================================================
           HOST APPLICATION ACTIONS (POST — Approve / Reject)
        ========================================================= */
        if ($request->isMethod('POST')) {
            $postedToken = (string) $request->request->get('csrf_token', '');
            $appId       = (int) $request->request->get('application_id', 0);
            $action      = (string) $request->request->get('host_app_action', '');

            if (
                !hash_equals($staffCsrf, $postedToken)
                || $appId <= 0
                || !in_array($action, ['approve', 'reject'], true)
            ) {
                return $this->redirect('/staff');
            }

            $application = $this->db->fetchAssociative(
                "SELECT id, user_id, status FROM host_applications WHERE id = :id LIMIT 1",
                ['id' => $appId]
            );

            if ($application) {
                $newStatus = $action === 'approve' ? 'approved' : 'rejected';

                try {
                    $this->db->beginTransaction();

                    $this->db->executeStatement(
                        "UPDATE host_applications SET status = :status, updated_at = NOW() WHERE id = :id",
                        ['status' => $newStatus, 'id' => $appId]
                    );

                    $this->db->executeStatement(
                        "UPDATE users SET is_host = :v WHERE id = :user_id",
                        [
                            'v'       => $action === 'approve' ? 1 : 0,
                            'user_id' => (int) $application['user_id'],
                        ]
                    );

                    $this->db->commit();
                } catch (\Throwable $e) {
                    if ($this->db->isTransactionActive()) {
                        $this->db->rollBack();
                    }
                    error_log('staff host app action error: ' . $e->getMessage());
                }

                if ($action === 'approve') {
                    $this->notifyUser(
                        (int) $application['user_id'],
                        'Congratulations! Your host application was approved. You can now list your space on RoomHive.',
                        '/host/profile'
                    );
                } else {
                    $this->notifyUser(
                        (int) $application['user_id'],
                        'Your host application was not approved this time. You can reapply anytime from the Become a Host page.',
                        '/host/become-a-host'
                    );
                }
            }

            return $this->redirect('/staff');
        }

        /* =========================================================
           DASHBOARD DATA
        ========================================================= */

        /* ---- TOP STAT CARDS ---- */
        $totalBookings = (int) $this->db->fetchOne("SELECT COUNT(*) FROM bookings");
        $totalRevenue  = (float) $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount_paid),0) FROM bookings WHERE status IN ('confirmed','completed')"
        );
        $activeListings = (int) $this->db->fetchOne("SELECT COUNT(*) FROM listings WHERE status = 'approved'");
        $totalUsers     = (int) $this->db->fetchOne("SELECT COUNT(*) FROM users");

        $avgRatingRow = $this->db->fetchOne("SELECT AVG(rating) FROM reviews");
        $avgRating    = ($avgRatingRow !== false && $avgRatingRow !== null)
            ? round((float) $avgRatingRow, 1)
            : null;
        $totalReviews = (int) $this->db->fetchOne("SELECT COUNT(*) FROM reviews");

        $stats = [
            [
                'label'   => 'Total Bookings',
                'value'   => number_format($totalBookings),
                'delta'   => '',
                'up'      => true,
                'icon'    => 'calendar',
                'caption' => $totalBookings > 0 ? 'All-time bookings' : 'No bookings yet',
            ],
            [
                'label'   => 'Total Revenue',
                'value'   => '₱' . number_format($totalRevenue),
                'delta'   => '',
                'up'      => true,
                'icon'    => 'wallet',
                'caption' => $totalRevenue > 0 ? 'From confirmed & completed' : 'No revenue yet',
            ],
            [
                'label'   => 'Active Listings',
                'value'   => number_format($activeListings),
                'delta'   => '',
                'up'      => true,
                'icon'    => 'listing',
                'caption' => $activeListings > 0 ? 'Currently live on the site' : 'No listings yet',
            ],
            [
                'label'   => 'Total Users',
                'value'   => number_format($totalUsers),
                'delta'   => '',
                'up'      => true,
                'icon'    => 'users',
                'caption' => $totalUsers > 0 ? 'Registered accounts' : 'No users yet',
            ],
            [
                'label'   => 'Average Rating',
                'value'   => ($avgRating !== null ? $avgRating : '—') . ' / 5',
                'delta'   => '',
                'up'      => true,
                'icon'    => 'star',
                'caption' => $totalReviews > 0 ? "From {$totalReviews} reviews" : 'No ratings yet',
            ],
        ];

        /* ---- BOOKINGS BY STATUS (donut) ---- */
        $statusRows = $this->db->fetchAllAssociative(
            "SELECT status, COUNT(*) AS cnt FROM bookings GROUP BY status"
        );
        $statusCountsRaw = [];
        foreach ($statusRows as $r) {
            $statusCountsRaw[$r['status']] = (int) $r['cnt'];
        }

        $totalBookingsForDonut = array_sum($statusCountsRaw);

        $statusBreakdown = [];
        $fbIdx = 0;
        foreach ($statusCountsRaw as $rawStatus => $count) {
            $key   = strtolower((string) $rawStatus);
            $color = self::STATUS_COLOR_MAP[$key]
                ?? self::FALLBACK_PALETTE[$fbIdx++ % count(self::FALLBACK_PALETTE)];
            $count = (int) $count;
            $statusBreakdown[] = [
                'label' => ucfirst($key),
                'value' => $count,
                'pct'   => $totalBookingsForDonut > 0
                    ? round(($count / $totalBookingsForDonut) * 100) . '%'
                    : '0%',
                'color' => $color,
            ];
        }

        /* ---- RECENT HOST APPLICATIONS (most recent 4) ---- */
        $haRows = $this->db->fetchAllAssociative(
            "SELECT ha.id, ha.full_name, ha.location, ha.status, u.avatar_path
             FROM host_applications ha
             LEFT JOIN users u ON u.id = ha.user_id
             ORDER BY ha.created_at DESC
             LIMIT 4"
        );
        $hostApplications = array_map(function (array $row): array {
            $initialsAvatar = 'https://ui-avatars.com/api/?background=EDA423&color=fff&bold=true&name='
                . urlencode((string) $row['full_name']);
            return [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['full_name'],
                'city'       => (string) $row['location'],
                'status'     => ucfirst((string) $row['status']),
                'raw_status' => (string) $row['status'],
                'img'        => $this->resolvePhoto((string) $row['avatar_path'], $initialsAvatar),
            ];
        }, $haRows);

        /* ---- TOP PERFORMING LISTINGS (by revenue, top 4) ---- */
        $tlRows = $this->db->fetchAllAssociative(
            "SELECT l.title, l.location,
                    p.photo_path AS cover_photo,
                    COUNT(b.id) AS booking_count,
                    COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.amount_paid ELSE 0 END), 0) AS revenue
             FROM listings l
             LEFT JOIN bookings b ON b.listing_id = l.id
             LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.photo_type = 'cover'
             GROUP BY l.id, l.title, l.location, p.photo_path
             ORDER BY revenue DESC, booking_count DESC
             LIMIT 4"
        );
        $topListings = array_map(function (array $row): array {
            return [
                'name'     => (string) $row['title'],
                'city'     => (string) $row['location'],
                'img'      => $this->resolvePhoto((string) $row['cover_photo']),
                'revenue'  => '₱' . number_format((float) $row['revenue']),
                'bookings' => (int) $row['booking_count'] . ' bookings',
            ];
        }, $tlRows);

        /* ---- RECENT BOOKINGS (most recent 4) ---- */
        $rbRows = $this->db->fetchAllAssociative(
            "SELECT l.title, l.location, p.photo_path AS cover_photo, b.created_at, b.amount_paid, b.status
             FROM bookings b
             JOIN listings l ON l.id = b.listing_id
             LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.photo_type = 'cover'
             ORDER BY b.created_at DESC
             LIMIT 4"
        );
        $recentBookings = array_map(function (array $row): array {
            return [
                'name'   => (string) $row['title'],
                'city'   => (string) $row['location'],
                'img'    => $this->resolvePhoto((string) $row['cover_photo']),
                'date'   => date('M j, Y', strtotime((string) $row['created_at'])),
                'amount' => '₱' . number_format((float) $row['amount_paid']),
                'status' => ucfirst((string) $row['status']),
            ];
        }, $rbRows);

        /* ---- PLATFORM SUMMARY (this calendar month) ---- */
        $monthStart = date('Y-m-01 00:00:00');

        $payoutsThisMonth = (float) $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount_paid),0) FROM bookings WHERE status IN ('confirmed','completed') AND created_at >= :start",
            ['start' => $monthStart]
        );
        $newUsersThisMonth = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM users WHERE created_at >= :start",
            ['start' => $monthStart]
        );
        $newListingsThisMonth = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM listings WHERE created_at >= :start",
            ['start' => $monthStart]
        );
        try {
            $newConversationsThisMonth = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM conversations WHERE created_at >= :start",
                ['start' => $monthStart]
            );
        } catch (\Throwable $e) {
            $newConversationsThisMonth = 0;
        }

        $platformSummary = [
            ['label' => 'Payouts This Month',  'value' => '₱' . number_format($payoutsThisMonth),   'icon' => 'wallet'],
            ['label' => 'New Users',           'value' => number_format($newUsersThisMonth),         'icon' => 'user-add'],
            ['label' => 'New Listings',        'value' => number_format($newListingsThisMonth),      'icon' => 'listing'],
            ['label' => 'New Conversations',   'value' => number_format($newConversationsThisMonth), 'icon' => 'message'],
        ];

        /* ---- Notifications: pending apps + pending listings ---- */
        $pendingHostApps   = (int) $this->db->fetchOne("SELECT COUNT(*) FROM host_applications WHERE status = 'pending'");
        $pendingListings   = (int) $this->db->fetchOne("SELECT COUNT(*) FROM listings WHERE status = 'pending'");
        $notificationCount = $pendingHostApps + $pendingListings;

        /* =========================================================
           CHART DATA — last 7 days of bookings / revenue
        ========================================================= */
        $chartLabels   = [];
        $bookingSeries = [];
        $revenueSeries = [];

        $dayBuckets = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $chartLabels[] = date('M j', strtotime($day));
            $dayBuckets[$day] = ['count' => 0, 'revenue' => 0.0];
        }

        $weekRows = $this->db->fetchAllAssociative(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c, COALESCE(SUM(amount_paid), 0) AS r
             FROM bookings
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)"
        );
        foreach ($weekRows as $r) {
            $d = (string) $r['d'];
            if (isset($dayBuckets[$d])) {
                $dayBuckets[$d]['count']   = (int) $r['c'];
                $dayBuckets[$d]['revenue'] = (float) $r['r'];
            }
        }
        foreach ($dayBuckets as $b) {
            $bookingSeries[] = $b['count'];
            $revenueSeries[] = $b['revenue'];
        }

        $weekBookingTotal = array_sum($bookingSeries);
        $weekRevenueTotal = array_sum($revenueSeries);

        /* =========================================================
           6-MONTH USER & LISTING GROWTH
        ========================================================= */
        $growthMonths = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts  = strtotime("first day of -{$i} months");
            $key = date('Y-m', $ts);
            $growthMonths[$key] = [
                'label'    => date('M Y', $ts),
                'users'    => 0,
                'listings' => 0,
            ];
        }
        $growthStart = date('Y-m-01 00:00:00', strtotime('-5 months'));

        try {
            $gUs = $this->db->fetchAllAssociative(
                "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS c
                 FROM users WHERE created_at >= :s GROUP BY ym",
                ['s' => $growthStart]
            );
            foreach ($gUs as $r) {
                if (isset($growthMonths[$r['ym']])) {
                    $growthMonths[$r['ym']]['users'] = (int) $r['c'];
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $gLs = $this->db->fetchAllAssociative(
                "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS c
                 FROM listings WHERE created_at >= :s GROUP BY ym",
                ['s' => $growthStart]
            );
            foreach ($gLs as $r) {
                if (isset($growthMonths[$r['ym']])) {
                    $growthMonths[$r['ym']]['listings'] = (int) $r['c'];
                }
            }
        } catch (\Throwable $e) {
        }

        $growthRows = array_values($growthMonths);

        /* ---- LISTINGS BY STATUS (donut) ---- */
        $lsRows = $this->db->fetchAllAssociative(
            "SELECT status, COUNT(*) AS c FROM listings GROUP BY status"
        );
        $listingStatusRaw = [];
        foreach ($lsRows as $r) {
            $listingStatusRaw[$r['status']] = (int) $r['c'];
        }
        $totalListingsForDonut = array_sum($listingStatusRaw);

        $listingBreakdown = [];
        $lfIdx = 0;
        foreach ($listingStatusRaw as $rawStatus => $count) {
            $key   = strtolower((string) $rawStatus);
            $color = self::LISTING_COLOR_MAP[$key]
                ?? self::FALLBACK_PALETTE[$lfIdx++ % count(self::FALLBACK_PALETTE)];
            $listingBreakdown[] = [
                'label' => ucfirst($key),
                'value' => (int) $count,
                'color' => $color,
            ];
        }

        /* ---- RATING DISTRIBUTION (1-5 stars) ---- */
        $rdRows = $this->db->fetchAllAssociative(
            "SELECT CAST(ROUND(rating) AS SIGNED) AS star, COUNT(*) AS c FROM reviews GROUP BY star"
        );
        $ratingDist = [];
        foreach ($rdRows as $r) {
            $ratingDist[(int) $r['star']] = (int) $r['c'];
        }
        $ratingLabels = ['5★', '4★', '3★', '2★', '1★'];
        $ratingSeries = [
            (int) ($ratingDist[5] ?? 0),
            (int) ($ratingDist[4] ?? 0),
            (int) ($ratingDist[3] ?? 0),
            (int) ($ratingDist[2] ?? 0),
            (int) ($ratingDist[1] ?? 0),
        ];

        /* ---- TOP LOCATIONS BY BOOKINGS (top 6) ---- */
        $locRows = $this->db->fetchAllAssociative(
            "SELECT l.location, COUNT(b.id) AS c
             FROM bookings b
             JOIN listings l ON l.id = b.listing_id
             GROUP BY l.location
             ORDER BY c DESC
             LIMIT 6"
        );
        $locLabels = [];
        $locSeries = [];
        foreach ($locRows as $r) {
            $locLabels[] = (string) $r['location'];
            $locSeries[] = (int) $r['c'];
        }

        /* =========================================================
           CHART GEOMETRY PREP
        ========================================================= */

        /* --- 1) Bookings 7-day line/area --- */
        $bookMax  = max(1, max($bookingSeries ?: [0]));
        $bookMid  = (int) round($bookMax / 2);
        $bookPts  = [];
        $bookDots = [];
        $n        = count($bookingSeries);
        foreach ($bookingSeries as $i => $v) {
            $x = self::PLOT_X + ($n > 1 ? ($i / ($n - 1)) * self::PLOT_W : 0);
            $y = self::PLOT_TOP + (1 - ($v / $bookMax)) * self::PLOT_H;
            $bookPts[] = round($x, 1) . ',' . round($y, 1);
            $bookDots[] = [
                'x'     => round($x, 1),
                'y'     => round($y, 1),
                'ly'    => round(max(11, $y - 9), 1),
                'v'     => (int) $v,
                'label' => $chartLabels[$i],
            ];
        }
        $bookPointsStr = implode(' ', $bookPts);
        $bookAreaPath  = 'M8,150 L' . implode(' L', $bookPts) . ' L592,150 Z';

        /* --- 2) Revenue 7-day vertical bars --- */
        $revMax  = max(0.01, max($revenueSeries ?: [0]));
        $revBars = [];
        foreach ($revenueSeries as $i => $v) {
            $revBars[] = [
                'label' => $chartLabels[$i],
                'v'     => (float) $v,
                'short' => '₱' . $this->moneyShort($v),
                'pct'   => $v > 0 ? max(2, (int) round(($v / $revMax) * 100)) : 2,
            ];
        }

        /* --- 3) Donut segment builder (status + listings) --- */
        $statusDonut  = $this->buildDonutSegments($statusBreakdown, $totalBookingsForDonut);
        $listingDonut = $this->buildDonutSegments($listingBreakdown, $totalListingsForDonut);

        /* --- 4) Growth grouped bars --- */
        $growthMax = max(1, max(array_merge(
            array_column($growthRows, 'users') ?: [0],
            array_column($growthRows, 'listings') ?: [0]
        )));
        $growthBars = [];
        foreach ($growthRows as $gr) {
            $uPct = $gr['users'] > 0
                ? max(2, (int) round(($gr['users'] / $growthMax) * 100))
                : 2;
            $lPct = $gr['listings'] > 0
                ? max(2, (int) round(($gr['listings'] / $growthMax) * 100))
                : 2;
            $growthBars[] = [
                'label'        => $gr['label'],
                'users'        => (int) $gr['users'],
                'users_pct'    => $uPct,
                'listings'     => (int) $gr['listings'],
                'listings_pct' => $lPct,
            ];
        }

        /* --- 5) Rating horizontal bars --- */
        $ratingMax  = max(1, max($ratingSeries ?: [0]));
        $ratingBars = [];
        foreach ($ratingLabels as $i => $lbl) {
            $ratingBars[] = [
                'label' => $lbl,
                'v'     => (int) $ratingSeries[$i],
                'pct'   => $ratingSeries[$i] > 0
                    ? max(3, (int) round(($ratingSeries[$i] / $ratingMax) * 100))
                    : 0,
            ];
        }

        /* --- 6) Top locations horizontal bars --- */
        $locMax  = max(1, max($locSeries ?: [0]));
        $locBars = [];
        foreach ($locLabels as $i => $lbl) {
            $locBars[] = [
                'label' => (string) $lbl,
                'v'     => (int) $locSeries[$i],
                'pct'   => $locSeries[$i] > 0
                    ? max(3, (int) round(($locSeries[$i] / $locMax) * 100))
                    : 0,
            ];
        }

        return $this->render('staff/dashboard.html.twig', [
            'admin_name'               => $staffName,
            'admin_email'              => $staffEmail,
            'admin_csrf'               => $staffCsrf,
            'notification_count'       => $notificationCount,

            'stats'                    => $stats,

            'status_breakdown'         => $statusBreakdown,
            'status_donut'             => $statusDonut,
            'total_bookings_for_donut' => $totalBookingsForDonut,

            'host_applications'        => $hostApplications,
            'top_listings'             => $topListings,
            'recent_bookings'          => $recentBookings,
            'platform_summary'         => $platformSummary,

            'chart_labels'             => $chartLabels,
            'booking_series'           => $bookingSeries,
            'revenue_series'           => $revenueSeries,
            'week_booking_total'       => $weekBookingTotal,
            'week_revenue_total'       => $weekRevenueTotal,
            'book_max'                 => $bookMax,
            'book_mid'                 => $bookMid,
            'book_points_str'          => $bookPointsStr,
            'book_area_path'           => $bookAreaPath,
            'book_dots'                => $bookDots,

            'rev_bars'                 => $revBars,

            'growth_bars'              => $growthBars,
            'growth_max'               => $growthMax,

            'listing_breakdown'        => $listingBreakdown,
            'listing_donut'            => $listingDonut,
            'total_listings_for_donut' => $totalListingsForDonut,

            'rating_bars'              => $ratingBars,
            'total_reviews'            => $totalReviews,

            'loc_bars'                 => $locBars,

            'badge_class_map'          => self::BADGE_CLASS_MAP,
            'current_page'             => '/staff',
        ]);
    }

    private function notifyUser(int $userId, string $msg, string $link = ''): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $this->db->executeStatement(
                "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                 VALUES (:user_id, :message, :link, 0, NOW())",
                [
                    'user_id' => $userId,
                    'message' => $msg,
                    'link'    => $link,
                ]
            );
        } catch (\Throwable $e) {
            error_log('StaffController: notify failed: ' . $e->getMessage());
        }
    }

    private function resolvePhoto(?string $path, string $fallback = '/images/Apartment.png'): string
    {
        if (empty($path)) {
            return $fallback;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $n = str_replace('\\', '/', ltrim($path, '/'));
        if (stripos($n, 'webprogg/') === 0) {
            $n = substr($n, strlen('webprogg/'));
        }
        if (stripos($n, 'host/') === 0) {
            $n = substr($n, strlen('host/'));
        }
        if (stripos($n, 'uploads/') === 0) {
            return '/' . $n;
        }
        if (stripos($n, 'cover_') === 0) {
            return '/uploads/listing_photos/cover/' . basename($n);
        }
        return '/uploads/listing_photos/' . $n;
    }

    private function moneyShort(float $v): string
    {
        if ($v >= 1000000) {
            return number_format($v / 1000000, 1) . 'M';
        }
        if ($v >= 1000) {
            return number_format($v / 1000, 1) . 'K';
        }
        return number_format($v, 0);
    }

    private function buildDonutSegments(array $items, int $total): array
    {
        $r   = self::DONUT_R;
        $C   = 2 * M_PI * $r;
        $acc = 0.0;
        $out = [];
        foreach ($items as $it) {
            $pct = $total > 0 ? ((int) $it['value'] / $total) * 100 : 0;
            $seg = ($pct / 100) * $C;
            if ($seg <= 0.01) {
                continue;
            }
            $out[] = [
                'color'  => $it['color'],
                'label'  => $it['label'],
                'value'  => (int) $it['value'],
                'pctStr' => $total > 0 ? round(($it['value'] / $total) * 100) . '%' : '0%',
                'dash'   => round($seg, 2) . ' ' . round($C - $seg, 2),
                'offset' => round(-$acc, 2),
                'C'      => round($C, 2),
            ];
            $acc += $seg;
        }
        return $out;
    }
}
