<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    #[Route('/profile', name: 'app_profile')]
    public function overview(Request $request): Response
    {
        $session = $request->getSession();

        if ($session->get('logged_in') !== true) {
            return $this->redirectToRoute('app_login');
        }

        $userId = (int) $session->get('user_id', 0);

        $user = $this->db->fetchAssociative(
            "SELECT id, name, email, phone, age, location, avatar_path, is_host, created_at
             FROM users WHERE id = :id LIMIT 1",
            ['id' => $userId]
        );

        if ($user === false) {
            $session->invalidate();
            return $this->redirectToRoute('app_login');
        }

        /* HOST REDIRECT — hosts have their own dashboard */
        if ((int) ($user['is_host'] ?? 0) === 1) {
            return $this->redirectToRoute('app_profile'); // ← swap to the host dashboard route when ported
        }

        /* Keep the session avatar in sync */
        $session->set('avatar_path', $user['avatar_path'] ?? null);

        /* =====================================================
           VERIFICATION — is_verified column if present, else
           the user_id_documents fallback; never fatal.
        ====================================================== */
        $isVerified   = false;
        $hasPendingId = false;

        try {
            if ($this->hasColumn('users', 'is_verified')) {
                $isVerified = (bool) $this->db->fetchOne(
                    'SELECT is_verified FROM users WHERE id = :id',
                    ['id' => $userId]
                );
            }

            if (!$isVerified && $this->tableExists('user_id_documents')) {
                $hasPendingId = (int) $this->db->fetchOne(
                    "SELECT COUNT(*) FROM user_id_documents
                     WHERE user_id = :u AND status != 'rejected'",
                    ['u' => $userId]
                ) > 0;
            }
        } catch (\Throwable $e) {
            error_log('account: verification check failed: ' . $e->getMessage());
        }

        /* ---- Real unread bell count ---- */
        $notificationCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
            ['u' => $userId]
        );

        /* =====================================================
           WALLET — balance + lifetime money-in + donut pct
        ====================================================== */
        $walletBalance = 0.0;
        $walletMoneyIn = 0.0;
        $walletPct     = 0;

        try {
            $walletBalance = (float) ($this->db->fetchOne(
                'SELECT wallet_balance FROM users WHERE id = :id',
                ['id' => $userId]
            ) ?: 0);

            if ($this->tableExists('wallet_transactions')) {
                $walletMoneyIn = (float) ($this->db->fetchOne(
                    "SELECT COALESCE(SUM(amount), 0)
                     FROM wallet_transactions
                     WHERE user_id = :id
                       AND payment_status = 'paid'
                       AND type IN ('topup', 'refund', 'reward')",
                    ['id' => $userId]
                ) ?: 0);
            }

            if ($walletMoneyIn > 0) {
                $walletPct = (int) max(0, min(100, round(($walletBalance / $walletMoneyIn) * 100)));
            }
        } catch (\Throwable $e) {
            error_log('account: wallet snapshot failed: ' . $e->getMessage());
        }

        /* =====================================================
           RECENT BOOKINGS (3, honest payment display)
        ====================================================== */
        $bookingRows = $this->db->fetchAllAssociative(
            "SELECT b.id, b.total, b.amount_paid, b.status, b.booked_at,
                    l.title, l.location,
                    p.photo_path AS cover_photo
             FROM bookings b
             JOIN listings l ON l.id = b.listing_id
             LEFT JOIN listing_photos p
                    ON p.listing_id = l.id AND p.photo_type = 'cover'
             WHERE b.user_id = :id
             ORDER BY b.booked_at DESC
             LIMIT 3",
            ['id' => $userId]
        );

        $bookings = array_map(function (array $row): array {
            $total = (float) $row['total'];
            $paid  = (float) ($row['amount_paid'] ?? 0);
            $left  = round(max(0, $total - $paid), 2);

            $payState = 'none';
            if ($paid > 0.005 && $left <= 0.005) {
                $payState = 'full';
            } elseif ($paid > 0.005) {
                $payState = 'advance';
            }

            return [
                'id'        => (int) $row['id'],
                'title'     => (string) $row['title'],
                'location'  => (string) $row['location'],
                'thumb'     => $this->resolvePhoto((string) ($row['cover_photo'] ?? '')),
                'dates'     => date('M j, Y', strtotime((string) $row['booked_at'])),
                'status'    => (string) $row['status'],
                'total'     => number_format($total, 2),
                'paid'      => number_format($paid, 2),
                'pay_state' => $payState,
            ];
        }, $bookingRows);

        /* =====================================================
           PAYMENT SUMMARY — bookings only (Hive Club removed)
        ====================================================== */
        $allBookings = $this->db->fetchAllAssociative(
            "SELECT amount_paid, booked_at FROM bookings
             WHERE user_id = :id AND status != 'cancelled'",
            ['id' => $userId]
        );

        $oneWeekAgo = strtotime('-7 days');
        $spentAllTime  = 0.0;
        $spentThisWeek = 0.0;

        foreach ($allBookings as $b) {
            $amount = (float) ($b['amount_paid'] ?? 0);
            $spentAllTime += $amount;
            if (strtotime((string) $b['booked_at']) >= $oneWeekAgo) {
                $spentThisWeek += $amount;
            }
        }

        $totalBookingsAll = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM bookings WHERE user_id = :id',
            ['id' => $userId]
        );

        $pendingToPay = (float) ($this->db->fetchOne(
            "SELECT COALESCE(SUM(GREATEST(total - amount_paid, 0)), 0)
             FROM bookings
             WHERE user_id = :id AND status = 'pending' AND amount_paid < total",
            ['id' => $userId]
        ) ?: 0);

        /* =====================================================
           CHART DATA — bookings & spend per month, last 6
        ====================================================== */
        $monthMap = [];
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT DATE_FORMAT(booked_at, '%Y-%m') AS ym,
                        COUNT(*) AS c,
                        COALESCE(SUM(amount_paid), 0) AS s
                 FROM bookings
                 WHERE user_id = :id
                   AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                 GROUP BY ym",
                ['id' => $userId]
            );
            foreach ($rows as $r) {
                $monthMap[$r['ym']] = ['c' => (int) $r['c'], 's' => (float) $r['s']];
            }
        } catch (\Throwable $e) {
            $monthMap = [];
        }

        $chartMonths = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts  = strtotime("-{$i} months");
            $key = date('Y-m', $ts);
            $chartMonths[] = [
                'label'    => date('M', $ts),
                'bookings' => $monthMap[$key]['c'] ?? 0,
                'spend'    => $monthMap[$key]['s'] ?? 0.0,
            ];
        }

        $bkMax = 1;
        $spMax = 0.01;
        foreach ($chartMonths as $m) {
            $bkMax = max($bkMax, $m['bookings']);
            $spMax = max($spMax, $m['spend']);
        }

        /* =====================================================
           WISHLIST — 6 recent + category breakdown (guarded:
           the wishlist table may not exist in every DB)
        ====================================================== */
        $wishlist      = [];
        $wishlistTotal = 0;
        $wlCats        = [];

        try {
            if ($this->tableExists('wishlist')) {
                $wishlistRows = $this->db->fetchAllAssociative(
                    "SELECT l.id, l.title, l.location, l.price,
                            p.photo_path AS cover_photo
                     FROM wishlist w
                     JOIN listings l ON l.id = w.listing_id
                     LEFT JOIN listing_photos p
                            ON p.listing_id = l.id AND p.photo_type = 'cover'
                     WHERE w.user_id = :id
                     ORDER BY w.created_at DESC
                     LIMIT 6",
                    ['id' => $userId]
                );

                $wishlist = array_map(fn(array $row): array => [
                    'id'       => (int) $row['id'],
                    'title'    => (string) $row['title'],
                    'location' => (string) $row['location'],
                    'thumb'    => $this->resolvePhoto((string) ($row['cover_photo'] ?? '')),
                    'price'    => number_format((float) $row['price'], 0),
                ], $wishlistRows);

                $wishlistTotal = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM wishlist WHERE user_id = :id',
                    ['id' => $userId]
                );

                $wlCats = $this->db->fetchAllAssociative(
                    "SELECT l.category, COUNT(*) AS c
                     FROM wishlist w
                     JOIN listings l ON l.id = w.listing_id
                     WHERE w.user_id = :id
                     GROUP BY l.category
                     ORDER BY c DESC
                     LIMIT 4",
                    ['id' => $userId]
                );
            }
        } catch (\Throwable $e) {
            error_log('account: wishlist failed: ' . $e->getMessage());
        }

        $wlCatTotal = array_sum(array_map(fn($r) => (int) $r['c'], $wlCats));

        return $this->render('account/profile.html.twig', [
            'is_logged_in'       => true,
            'nav_avatar'         => (string) ($user['avatar_path'] ?? '') ?: '/images/default-avatar.png',
            'notification_count' => $notificationCount,
            'current_page'       => '/profile',
            'active_sidebar'     => 'overview',

            'user' => [
                'name'         => (string) $user['name'],
                'avatar'       => (string) ($user['avatar_path'] ?? '') ?: '/images/default-avatar.png',
                'location'     => (string) ($user['location'] ?? ''),
                'email'        => (string) $user['email'],
                'phone'        => (string) ($user['phone'] ?? ''),
                'age'          => (string) ($user['age'] ?? ''),
                'member_since' => date('F Y', strtotime((string) $user['created_at'])),
            ],

            'is_verified'    => $isVerified,
            'has_pending_id' => $hasPendingId,

            'wallet_balance' => $walletBalance,
            'wallet_money_in' => $walletMoneyIn,
            'wallet_pct'     => $walletPct,
            'wallet_donut_offset' => round(125.66 * (1 - min(100, $walletPct) / 100), 2),

            'bookings'          => $bookings,
            'total_bookings_all' => $totalBookingsAll,
            'spent_this_week'   => number_format($spentThisWeek, 2),
            'spent_all_time'    => number_format($spentAllTime, 2),
            'pending_to_pay'    => number_format($pendingToPay, 2),

            'chart_months' => $chartMonths,
            'bk_max'       => $bkMax,
            'sp_max'       => $spMax,

            'wishlist'       => $wishlist,
            'wishlist_total' => $wishlistTotal,
            'wl_cats'        => $wlCats,
            'wl_cat_total'   => $wlCatTotal,
        ]);
    }

    /* ---- helpers ---- */
    private function hasColumn(string $table, string $column): bool
    {
        return isset($this->db->createSchemaManager()->listTableColumns($table)[$column]);
    }

    private function tableExists(string $table): bool
    {
        return $this->db->createSchemaManager()->tablesExist([$table]);
    }

    private function resolvePhoto(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/images/ListingPlaceholder.png';
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
