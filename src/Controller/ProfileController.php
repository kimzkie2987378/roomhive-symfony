<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

class ProfileController extends AbstractController
{
    private const WL_COLORS = ['#eda423', '#2F7DE1', '#1fa971', '#7a4bb0'];

    public function __construct(private readonly Connection $db) {}

    /* =========================================================
       GET /profile — My Account dashboard
    ========================================================= */
    #[Route('/profile', name: 'app_profile', priority: 2)]
    public function index(SessionInterface $session): Response
    {
        if (!$session->get('logged_in')) {
            return $this->redirect('/login');
        }

        $userId = (int) $session->get('user_id');

        try {
            $u = $this->db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$userId]);
        } catch (\Throwable) {
            $u = false;
        }

        if (!$u) {
            $session->invalidate();
            return $this->redirect('/login');
        }

        $pick = function (array $row, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
                    return $row[$k];
                }
            }
            return $default;
        };

        /* ---------- user ---------- */
        $fullName = trim((string) ($u['name'] ?? ''));
        $memberSince = !empty($u['created_at'])
            ? date('F Y', strtotime((string) $u['created_at']))
            : '';

        $avatar = (string) ($session->get('avatar_path') ?? '');
        if ($avatar === '' || str_contains($avatar, 'default-avatar')) {
            $dbAvatar = trim((string) ($u['avatar_path'] ?? ''));
            $avatar = ($dbAvatar !== '' && !str_contains($dbAvatar, 'default-avatar')) ? $dbAvatar : '';
        }

        $location = trim((string) $pick($u, ['city', 'location', 'address', 'barangay'], ''));
        $country  = trim((string) ($u['country'] ?? ''));
        if ($location !== '' && $country !== '' && !str_contains($location, $country)) {
            $location .= ', ' . $country;
        }

        /* ---------- ID verification status ---------- */
        $idStatus = 'none';
        try {
            $docs = $this->db->fetchAllAssociative(
                'SELECT status FROM user_id_documents WHERE user_id = ?',
                [$userId]
            );
            $states = array_map(fn($r) => strtolower((string) $r['status']), $docs);
            if (in_array('approved', $states, true)) {
                $idStatus = 'verified';
            } elseif (count($states) > 0) {
                $idStatus = 'pending';
            }
        } catch (\Throwable) {
            $idStatus = 'none';
        }

        /* ---------- wallet (self-healing) ---------- */
        $balance = 0.0;
        $moneyIn = 0.0;
        try {
            $this->db->executeStatement("
                CREATE TABLE IF NOT EXISTS wallet_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    type VARCHAR(20) NOT NULL,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT 'paid',
                    reference VARCHAR(80) NULL,
                    created_at DATETIME NULL,
                    INDEX (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $cols = $this->db->fetchAllAssociative(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'wallet_balance'"
            );
            if (!$cols) {
                $this->db->executeStatement(
                    'ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0'
                );
            }

            $row = $this->db->fetchAssociative(
                'SELECT wallet_balance FROM users WHERE id = ?',
                [$userId]
            );
            $balance = (float) ($row['wallet_balance'] ?? 0);

            $agg = $this->db->fetchAssociative(
                "SELECT COALESCE(SUM(amount), 0) AS m FROM wallet_transactions
                 WHERE user_id = ? AND status = 'paid'
                   AND type IN ('topup', 'refund', 'reward')",
                [$userId]
            );
            $moneyIn = (float) ($agg['m'] ?? 0);
        } catch (\Throwable) {
            $balance = 0.0;
            $moneyIn = 0.0;
        }
        $walletPct = $moneyIn > 0 ? max(0, min(100, (int) round($balance / $moneyIn * 100))) : 0;

        /* ---------- money formatters ---------- */
        $peso = function ($n): string {
            return '₱' . number_format((float) $n, 2);
        };
        $pesoShort = function ($n): string {
            $n = (float) $n;
            $sign = $n < 0 ? '-' : '';
            $n = abs($n);
            if ($n >= 1000000) return $sign . '₱' . rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
            if ($n >= 1000)    return $sign . '₱' . rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
            return $sign . '₱' . number_format($n, 0);
        };

        /* ---------- recent bookings (3) ---------- */
        $rows = [];
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT b.* FROM bookings b WHERE b.user_id = ? ORDER BY b.booked_at DESC LIMIT 3',
                [$userId]
            );
        } catch (\Throwable) {
            try {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT b.* FROM bookings b WHERE b.user_id = ? ORDER BY b.id DESC LIMIT 3',
                    [$userId]
                );
            } catch (\Throwable) {
                $rows = [];
            }
        }

        $listingIds = array_values(array_filter(array_map(
            fn($r) => (int) ($r['listing_id'] ?? 0),
            $rows
        )));

        $listings = [];
        if ($listingIds) {
            try {
                $in = implode(',', array_fill(0, count($listingIds), '?'));
                $lrows = $this->db->fetchAllAssociative(
                    "SELECT * FROM listings WHERE id IN ($in)",
                    $listingIds
                );
                foreach ($lrows as $l) {
                    $listings[(int) $l['id']] = $l;
                }
            } catch (\Throwable) {
                $listings = [];
            }
        }
        $covers = $this->coverMap($listingIds);

        $bookings = [];
        foreach ($rows as $r) {
            $lid    = (int) ($r['listing_id'] ?? 0);
            $l      = $listings[$lid] ?? [];
            $total  = (float) ($r['total'] ?? 0);
            $paid   = (float) ($r['amount_paid'] ?? 0);
            $status = strtolower(trim((string) ($r['status'] ?? '')));

            if ($total <= 0.005) {
                $total = (float) $pick($l, ['price_per_night', 'price', 'nightly_price'], 0);
            }

            $payState = 'none';
            if ($total > 0 && $paid >= $total - 0.005) {
                $payState = 'full';
            } elseif ($paid > 0.005) {
                $payState = 'advance';
            }
            $payLabel = ['full' => 'Paid in full', 'advance' => 'Advance paid', 'none' => 'Unpaid'][$payState];

            $statusClass = in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)
                ? $status : 'other';
            $statusLabel = $status !== '' ? ucfirst($status) : '—';

            $ci = $pick($r, ['check_in', 'checkin', 'start_date', 'date_from', 'from_date'], '');
            $co = $pick($r, ['check_out', 'checkout', 'end_date', 'date_to', 'to_date'], '');
            $dates = '';
            if ($ci) $dates .= date('M j, Y', strtotime((string) $ci));
            if ($ci && $co) $dates .= '  →  ';
            if ($co) $dates .= date('M j, Y', strtotime((string) $co));

            $cover = (string) ($covers[$lid] ?? '');
            $bookings[] = [
                'title'        => trim((string) $pick($l, ['title', 'name'], 'Listing #' . $lid)),
                'cover'        => $cover,
                'cover_url'    => $this->coverUrl($cover),
                'dates'        => $dates !== '' ? $dates : 'Dates TBC',
                'status_class' => $statusClass,
                'status_label' => $statusLabel,
                'total_str'    => $peso($total),
                'pay_label'    => $payLabel,
                'pay_state'    => $payState,
            ];
        }

        /* ---------- payment summary ---------- */
        $spentWeek = 0.0;
        $spentAll  = 0.0;
        $totalBookings = 0;
        $pendingToPay  = 0.0;
        $pendingCount  = 0;
        try {
            $s = $this->db->fetchAssociative("
                SELECT
                    COALESCE(SUM(CASE WHEN booked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        AND status <> 'cancelled' THEN amount_paid ELSE 0 END), 0) AS spent_week,
                    COALESCE(SUM(CASE WHEN status <> 'cancelled'
                        THEN amount_paid ELSE 0 END), 0) AS spent_all,
                    COUNT(*) AS total_bookings
                FROM bookings
                WHERE user_id = ?
            ", [$userId]);
            $spentWeek     = (float) $s['spent_week'];
            $spentAll      = (float) $s['spent_all'];
            $totalBookings = (int) $s['total_bookings'];

            $p = $this->db->fetchAssociative("
                SELECT COALESCE(SUM(GREATEST(total - amount_paid, 0)), 0) AS due,
                       COUNT(*) AS c
                FROM bookings
                WHERE user_id = ? AND status = 'pending' AND amount_paid < total
            ", [$userId]);
            $pendingToPay = (float) $p['due'];
            $pendingCount = (int) $p['c'];
        } catch (\Throwable) {
            // defaults already 0
        }

        /* ---------- 6-month chart ---------- */
        $byMonth = [];
        try {
            $rowsC = $this->db->fetchAllAssociative("
                SELECT DATE_FORMAT(booked_at, '%Y-%m') AS ym,
                       COUNT(*) AS bk,
                       COALESCE(SUM(amount_paid), 0) AS sp
                FROM bookings
                WHERE user_id = ?
                  AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY ym
            ", [$userId]);
            foreach ($rowsC as $r) {
                $byMonth[(string) $r['ym']] = $r;
            }
        } catch (\Throwable) {
            $byMonth = [];
        }

        $maxBk = 0;
        $maxSp = 0.0;
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts = strtotime("-$i months");
            $ym = date('Y-m', $ts);
            $bk = (int) ($byMonth[$ym]['bk'] ?? 0);
            $sp = (float) ($byMonth[$ym]['sp'] ?? 0);
            $months[] = ['label' => date('M', $ts), 'bk' => $bk, 'sp' => $sp];
            if ($bk > $maxBk) $maxBk = $bk;
            if ($sp > $maxSp) $maxSp = $sp;
        }
        $chart = [];
        foreach ($months as $m) {
            $chart[] = [
                'label'  => $m['label'],
                'bk'     => $m['bk'],
                'bk_h'   => $maxBk > 0 ? max(5, (int) round($m['bk'] / $maxBk * 100)) : 5,
                'sp_str' => $pesoShort($m['sp']),
                'sp_h'   => $maxSp > 0 ? max(4, (int) round($m['sp'] / $maxSp * 100)) : 4,
            ];
        }

        /* ---------- wishlist ---------- */
        $wishlist  = [];
        $wlCats    = [];
        $wlTotal   = 0;
        try {
            $wlTotal = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM wishlist WHERE user_id = ?',
                [$userId]
            );
            try {
                $wlRows = $this->db->fetchAllAssociative(
                    'SELECT w.listing_id, l.* FROM wishlist w
                     JOIN listings l ON l.id = w.listing_id
                     WHERE w.user_id = ?
                     ORDER BY w.id DESC
                     LIMIT 100',
                    [$userId]
                );
            } catch (\Throwable) {
                $wlRows = $this->db->fetchAllAssociative(
                    'SELECT w.listing_id, l.* FROM wishlist w
                     JOIN listings l ON l.id = w.listing_id
                     WHERE w.user_id = ?',
                    [$userId]
                );
            }

            $coversW = $this->coverMap(array_map(fn($r) => (int) $r['listing_id'], $wlRows));

            foreach ($wlRows as $r) {
                $lid   = (int) $r['listing_id'];
                $price = (float) $pick($r, ['price_per_night', 'price', 'nightly_price', 'monthly_price'], 0);
                $cover = (string) ($coversW[$lid] ?? '');
                $cat   = trim((string) $pick($r, ['category', 'type', 'property_type', 'room_type'], 'Other'));
                if ($cat === '') $cat = 'Other';

                $wlCats[] = $cat;
                $wishlist[] = [
                    'id'        => $lid,
                    'title'     => trim((string) $pick($r, ['title', 'name'], 'Listing #' . $lid)),
                    'location'  => trim((string) $pick($r, ['city', 'location', 'address', 'barangay'], '')),
                    'cover'     => $cover,
                    'cover_url' => $this->coverUrl($cover),
                    'price_str' => number_format($price, 0),
                ];
            }
            $wishlist = array_slice($wishlist, 0, 6);
        } catch (\Throwable) {
            $wishlist = [];
            $wlCats   = [];
        }

        /* wishlist category stacked bar (top 4) */
        $catCounts = array_count_values(array_filter($wlCats));
        arsort($catCounts);
        $catTop = array_slice($catCounts, 0, 4, true);
        $catSum = array_sum($catTop);
        $wlBars = [];
        $ci = 0;
        foreach ($catTop as $label => $count) {
            $wlBars[] = [
                'label' => (string) $label,
                'count' => (int) $count,
                'width' => $catSum > 0 ? round($count / $catSum * 100, 1) : 0,
                'color' => self::WL_COLORS[$ci % 4],
            ];
            $ci++;
        }

        /* ---------- notifications badge ---------- */
        $notifCount = 0;
        try {
            $notifCount = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND COALESCE(is_read, 0) = 0',
                [$userId]
            );
        } catch (\Throwable) {
            $notifCount = 0;
        }

        $resp = $this->render('user/profile.html.twig', [
            'user' => [
                'name'         => $fullName !== '' ? $fullName : 'RoomHive member',
                'email'        => (string) ($u['email'] ?? ''),
                'phone'        => (string) $pick($u, ['phone', 'phone_number', 'mobile'], ''),
                'location'     => $location,
                'member_since' => $memberSince,
                'avatar'       => $avatar,
            ],
            'is_host'            => (bool) $session->get('is_host'),
            'nav_avatar'         => $avatar,
            'notification_count' => $notifCount,
            'id_status'          => $idStatus,
            'wallet' => [
                'balance'       => $balance,
                'balance_str'   => $peso($balance),
                'balance_short' => $pesoShort($balance),
                'money_in_str'  => $peso($moneyIn),
                'pct'           => $walletPct,
            ],
            'bookings' => $bookings,
            'summary' => [
                'spent_week_str' => $peso($spentWeek),
                'spent_all_str'  => $peso($spentAll),
                'total_bookings' => $totalBookings,
                'pending_str'    => $peso($pendingToPay),
                'pending_count'  => $pendingCount,
                'pending'        => $pendingToPay,
            ],
            'chart'         => $chart,
            'wishlist'      => $wishlist,
            'wl_categories' => $wlBars,
            'wl_total'      => $wlTotal,
        ]);

        $resp->headers->set('Cache-Control', 'no-store, max-age=0, must-revalidate');
        return $resp;
    }

    /* =========================================================
       POST /profile/avatar — profile photo upload (JSON)
    ========================================================= */
    #[Route('/profile/avatar', name: 'app_profile_avatar', methods: ['POST'], priority: 2)]
    public function uploadAvatar(Request $request, SessionInterface $session): JsonResponse
    {
        if (!$session->get('logged_in')) {
            return $this->json(['ok' => false, 'error' => 'Not logged in'], 401);
        }
        $userId = (int) $session->get('user_id');

        $file = $request->files->get('avatar');
        if (!$file) {
            return $this->json(['ok' => false, 'error' => 'No file received'], 400);
        }
        if (!$file->isValid()) {
            return $this->json(['ok' => false, 'error' => 'Upload failed'], 400);
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = (string) $file->getClientMimeType();
        if (!isset($allowed[$mime])) {
            return $this->json(['ok' => false, 'error' => 'Only JPG, PNG or WebP images are allowed'], 400);
        }
        if ((int) $file->getSize() > 4 * 1024 * 1024) {
            return $this->json(['ok' => false, 'error' => 'Maximum file size is 4 MB'], 400);
        }

        try {
            $dir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $name = 'avatar-' . $userId . '-' . time() . '.' . $allowed[$mime];
            $file->move($dir, $name);
            $url = '/uploads/avatars/' . $name;

            try {
                $cols = $this->db->fetchAllAssociative(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                       AND COLUMN_NAME = 'avatar_path'"
                );
                if (!$cols) {
                    $this->db->executeStatement(
                        'ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL'
                    );
                }
                $this->db->update('users', ['avatar_path' => $url], ['id' => $userId]);
            } catch (\Throwable) {
                // DB update optional — session below keeps the navbar correct
            }

            $session->set('avatar_path', $url);

            return $this->json(['ok' => true, 'url' => $url]);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'error' => 'Could not save the file'], 500);
        }
    }

    /* =========================================================
       POST /wishlist/remove — remove a listing from wishlist
    ========================================================= */
    #[Route('/wishlist/remove', name: 'app_wishlist_remove', methods: ['POST'], priority: 2)]
    public function removeWishlist(Request $request, SessionInterface $session): JsonResponse
    {
        if (!$session->get('logged_in')) {
            return $this->json(['ok' => false, 'error' => 'Not logged in'], 401);
        }
        $userId    = (int) $session->get('user_id');
        $listingId = (int) $request->request->get('listing_id', 0);
        if ($listingId <= 0) {
            return $this->json(['ok' => false, 'error' => 'Missing listing'], 400);
        }
        try {
            $this->db->delete('wishlist', ['user_id' => $userId, 'listing_id' => $listingId]);
            return $this->json(['ok' => true]);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'error' => 'Database error'], 500);
        }
    }

    /* =========================================================
       helpers
    ========================================================= */

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

        // fallback: no cover flagged anywhere — take the first photo per listing
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
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return '/uploads/listings/' . ltrim($path, '/');
    }
}
