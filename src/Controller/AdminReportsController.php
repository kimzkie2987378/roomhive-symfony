<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Reports.
 * 1:1 port of legacy admin/adminreports.php.
 *
 *   GET /admin/reports   → platform performance over the last
 *     6 months. Lifetime stat cards + 3 native SVG/CSS charts
 *     + Top Hosts + Top Listings leaderboards.
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * THREE CHARTS (no Chart.js — pure SVG/CSS, numbers always visible):
 *   1. Revenue 6 months → vertical bars, ₱ value above each bar.
 *   2. Bookings 6 months → SVG area/line, count above each dot.
 *   3. New Users 6 months → horizontal bars (purple).
 *
 *   All chart geometry (x/y coordinates, percentages, short labels)
 *   is pre-computed in PHP and passed to Twig as ready-to-render
 *   arrays — mirrors the legacy page, which built the SVG points
 *   server-side so the template stays declarative.
 *
 * Animation: progressive enhancement via IntersectionObserver in
 *   the template's scripts block. Charts render fully without JS
 *   and with prefers-reduced-motion; .on just triggers the CSS
 *   transitions.
 *
 * Lifetime stat cards (5):
 *   - Lifetime Volume (₱ from confirmed+completed bookings)
 *   - Lifetime Bookings (all bookings count)
 *   - Total Users
 *   - Approved Listings (status='approved')
 *   - 6-Month Users (sum of the user series)
 *
 * Top Hosts: 5 hosts ranked by earnings (host_payout_amount or
 *   amount_paid summed over confirmed+completed bookings).
 * Top Listings: 5 listings ranked by booking count.
 */
class AdminReportsController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/reports', name: 'app_admin_reports', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->get('admin_logged_in')) {
            return $this->redirect('/admin/login');
        }

        $adminName  = (string) ($session->get('admin_name')  ?? 'Admin User');
        $adminEmail = (string) ($session->get('admin_email') ?? '');

        /* ---- CSRF token (per-session) ---- */
        $adminCsrf = (string) $session->get(self::CSRF_KEY);
        if ($adminCsrf === '') {
            $adminCsrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $adminCsrf);
        }

        /* =========================================================
           BUILD 6-MONTH SERIES
           Each month seeded with zeros; queries fill in real data.
        ========================================================= */
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts = strtotime("first day of -{$i} months");
            $key = date('Y-m', $ts);
            $months[$key] = [
                'key'      => $key,
                'label'    => date('M Y', $ts),
                'short'    => date('M', $ts),
                'bookings' => 0,
                'revenue'  => 0.0,
                'users'    => 0,
            ];
        }

        $windowStart = date('Y-m-01 00:00:00', strtotime('-5 months'));

        /* ---- Bookings + revenue per month ---- */
        try {
            $bkRows = $this->db->fetchAllAssociative(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                        COUNT(*) AS c,
                        COALESCE(SUM(amount_paid), 0) AS r
                 FROM bookings
                 WHERE created_at >= :s
                 GROUP BY ym",
                ['s' => $windowStart]
            );
            foreach ($bkRows as $row) {
                $key = $row['ym'];
                if (isset($months[$key])) {
                    $months[$key]['bookings'] = (int) $row['c'];
                    $months[$key]['revenue']  = (float) $row['r'];
                }
            }
        } catch (\Throwable $e) {
            error_log('adminreports: bookings series failed: ' . $e->getMessage());
        }

        /* ---- New users per month ---- */
        try {
            $usRows = $this->db->fetchAllAssociative(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
                 FROM users
                 WHERE created_at >= :s
                 GROUP BY ym",
                ['s' => $windowStart]
            );
            foreach ($usRows as $row) {
                $key = $row['ym'];
                if (isset($months[$key])) {
                    $months[$key]['users'] = (int) $row['c'];
                }
            }
        } catch (\Throwable $e) {
            error_log('adminreports: users series failed: ' . $e->getMessage());
        }

        $monthRows = array_values($months);

        $chartLabels   = array_column($monthRows, 'label');
        $bookingSeries = array_column($monthRows, 'bookings');
        $revenueSeries = array_column($monthRows, 'revenue');
        $userSeries    = array_column($monthRows, 'users');

        /* =========================================================
           TOP HOSTS — by earnings over confirmed/completed bookings
        ========================================================= */
        $topHosts = [];
        try {
            $topHosts = $this->db->fetchAllAssociative(
                "SELECT u.id, u.name, COUNT(b.id) AS bookings,
                        COALESCE(SUM(COALESCE(b.host_payout_amount, b.amount_paid)), 0) AS earned
                 FROM bookings b
                 JOIN listings l ON l.id = b.listing_id
                 JOIN users u ON u.id = l.user_id
                 WHERE b.status IN ('confirmed', 'completed')
                 GROUP BY u.id, u.name
                 ORDER BY earned DESC
                 LIMIT 5"
            );
        } catch (\Throwable $e) {
            error_log('adminreports: top hosts failed: ' . $e->getMessage());
        }

        /* =========================================================
           TOP LISTINGS — by booking count (confirmed+completed)
        ========================================================= */
        $topListings = [];
        try {
            $topListings = $this->db->fetchAllAssociative(
                "SELECT l.id, l.title, COUNT(b.id) AS bookings,
                        COALESCE(SUM(b.amount_paid), 0) AS volume
                 FROM listings l
                 JOIN bookings b ON b.listing_id = l.id AND b.status IN ('confirmed', 'completed')
                 GROUP BY l.id, l.title
                 ORDER BY bookings DESC
                 LIMIT 5"
            );
        } catch (\Throwable $e) {
            error_log('adminreports: top listings failed: ' . $e->getMessage());
        }

        /* =========================================================
           LIFETIME TOTALS
        ========================================================= */
        $lifeRevenue  = 0.0;
        $lifeBookings = 0;
        $lifeUsers    = 0;
        $lifeListings = 0;
        try {
            $lifeRevenue = (float) $this->db->fetchOne(
                "SELECT COALESCE(SUM(amount_paid), 0)
                 FROM bookings
                 WHERE status IN ('confirmed', 'completed')"
            );
            $lifeBookings = (int) $this->db->fetchOne("SELECT COUNT(*) FROM bookings");
            $lifeUsers    = (int) $this->db->fetchOne("SELECT COUNT(*) FROM users");
            $lifeListings = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM listings WHERE status = 'approved'"
            );
        } catch (\Throwable $e) {
            error_log('adminreports: lifetime totals failed: ' . $e->getMessage());
        }

        /* =========================================================
           NATIVE CHART PREP — pre-build SVG/CSS values in PHP
           so the template stays declarative.
        ========================================================= */

        /* --- 1) Bookings area/line (per-point numbers + dots) --- */
        $bookMax = max(1, max($bookingSeries ?: [0]));
        $bookMid = (int) round($bookMax / 2);
        $bookPts = [];
        $bookDots = [];
        $count = count($bookingSeries);
        foreach ($bookingSeries as $i => $v) {
            $x = 8 + ($i / max(1, $count - 1)) * 584;
            $y = 18 + (1 - ($v / $bookMax)) * 132;
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

        /* --- 2) Revenue vertical bars (₱ short labels) --- */
        $revMax = max(0.01, max($revenueSeries ?: [0]));
        $revBars = [];
        foreach ($monthRows as $m) {
            $revBars[] = [
                'label' => $m['short'],
                'full'  => $m['label'],
                'v'     => (float) $m['revenue'],
                'short' => '₱' . $this->moneyShort($m['revenue']),
                'pct'   => $m['revenue'] > 0 ? max(2, (int) round(($m['revenue'] / $revMax) * 100)) : 2,
            ];
        }

        /* --- 3) New Users horizontal bars --- */
        $userMax = max(1, max($userSeries ?: [0]));
        $userBars = [];
        foreach ($monthRows as $m) {
            $userBars[] = [
                'label' => $m['label'],
                'v'     => (int) $m['users'],
                'pct'   => $m['users'] > 0 ? max(3, (int) round(($m['users'] / $userMax) * 100)) : 0,
            ];
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        return $this->render('admin/reports.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'life_revenue'       => $lifeRevenue,
            'life_bookings'      => $lifeBookings,
            'life_users'         => $lifeUsers,
            'life_listings'      => $lifeListings,
            'six_month_users'    => array_sum($userSeries),

            'top_hosts'          => $topHosts,
            'top_listings'       => $topListings,

            'chart_labels'       => $chartLabels,
            'book_max'           => $bookMax,
            'book_mid'           => $bookMid,
            'book_points_str'    => $bookPointsStr,
            'book_area_path'     => $bookAreaPath,
            'book_dots'          => $bookDots,

            'rev_bars'           => $revBars,
            'user_bars'          => $userBars,
        ]);
    }

    /* =====================================================================
     *  MONEY SHORT — 1.2M / 3.4K / 980. Same as legacy $repMoneyShort.
     * ================================================================= */

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
