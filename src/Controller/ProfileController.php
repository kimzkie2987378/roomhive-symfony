<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Profile (dashboard + edit) — ONE class owns every /profile* route.
 *
 *   GET|POST /profile                       → My Account dashboard
 *   POST     /profile/avatar                → profile photo (JSON)
 *   GET|POST /profile/edit                  → edit profile + ID verify
 *   POST     /profile/edit/upload-personal  → personal photo (JSON)
 *   POST     /profile/edit (upload_personal=1) → answered as JSON too —
 *              the camera/Choose-File JS posts to the PAGE URL, so edit()
 *              delegates those requests to uploadPersonal().
 *
 * Template contract (templates/user/profile_edit.html.twig):
 *   user{name,avatar,personal,email,phone,age,location}  ← MAPPED, not raw row
 *   idTypes, idDocs, isVerified, hasIdNoProfile,
 *   errors, saved, savedId, justVerified, notification_count
 *
 * CSRF: both template forms render csrf_token('profile_edit'); this
 * controller validates the SAME token id via isCsrfTokenValid().
 *
 * FIX (this version): dashboard booking thumbs + wishlist cards
 * resolve covers through legacyPhotoUrl() — the same resolver the
 * ListingsController and WishlistController use. The old coverUrl()
 * pointed every photo at /uploads/listings/ (a directory that does
 * not exist), so each image 404'd and the page fell back to the
 * gray placeholder box. Covers now handle the legacy DB values:
 * webprogg/…, host/…, uploads/…, cover_… and bare filenames.
 */
class ProfileController extends AbstractController
{
    private const WL_COLORS = ['#eda423', '#2F7DE1', '#1fa971', '#7a4bb0'];

    private const CSRF_KEY = 'profile_edit';

    private const ID_TYPES = [
        'passport'        => 'Passport',
        'drivers-license' => "Driver's License",
        'philsys'         => 'PhilSys / National ID',
        'umid'            => 'UMID',
        'voters-id'       => "Voter's ID",
        'postal-id'       => 'Postal ID',
        'prc-id'          => 'PRC ID',
        'student-id'      => 'Student ID',
        'sss-id'          => 'SSS ID',
        'tin-id'          => 'TIN ID',
    ];

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
            if (in_array('verified', $states, true) || in_array('approved', $states, true)) {
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
                    reference VARCHAR(40) NOT NULL UNIQUE,
                    type ENUM('topup','spend','refund','reward') NOT NULL,
                    amount DECIMAL(12,2) NOT NULL,
                    balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
                    description VARCHAR(240) NOT NULL DEFAULT '',
                    payment_method VARCHAR(30) DEFAULT NULL,
                    payment_status ENUM('pending','paid','failed') NOT NULL DEFAULT 'paid',
                    gcash_reference VARCHAR(60) DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_wt_user (user_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            foreach (
                [
                    'balance_after'   => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
                    'description'     => "VARCHAR(240) NOT NULL DEFAULT ''",
                    'payment_method'  => 'VARCHAR(30) DEFAULT NULL',
                    'payment_status'  => "ENUM('pending','paid','failed') NOT NULL DEFAULT 'paid'",
                    'gcash_reference' => 'VARCHAR(60) DEFAULT NULL',
                ] as $maybeMissing => $definition
            ) {
                $col = $this->db->fetchOne(
                    "SHOW COLUMNS FROM wallet_transactions LIKE '{$maybeMissing}'"
                );
                if ($col === false) {
                    $this->db->executeStatement(
                        "ALTER TABLE wallet_transactions ADD COLUMN {$maybeMissing} {$definition}"
                    );
                }
            }

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

            $agg = false;
            foreach (['payment_status', 'status'] as $statusCol) {
                try {
                    $agg = $this->db->fetchAssociative(
                        "SELECT COALESCE(SUM(amount), 0) AS m FROM wallet_transactions
                         WHERE user_id = ? AND {$statusCol} = 'paid'
                           AND type IN ('topup', 'refund', 'reward')",
                        [$userId]
                    );
                    break;
                } catch (\Throwable) {
                    /* schema variant — try the next status column */
                }
            }
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
                'cover_url'    => $this->legacyPhotoUrl($cover),
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
                    'cover_url' => $this->legacyPhotoUrl($cover),
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

    /* =====================================================================
     |  EDIT PROFILE — 1:1 port of legacy user/editprofile.php.
     | ================================================================== */

    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'], priority: 2)]
    public function edit(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* ---- SELF-HEAL personal_photo BEFORE the select ---- */
        try {
            $ppCol = $this->db->fetchOne("SHOW COLUMNS FROM users LIKE 'personal_photo'");
            if ($ppCol === false) {
                $this->db->executeStatement('ALTER TABLE users ADD COLUMN personal_photo VARCHAR(255) NULL');
            }
        } catch (\Throwable $e) {
            error_log('editprofile: personal_photo ensure failed: ' . $e->getMessage());
        }

        $hasPersonalPhoto = true;
        try {
            $user = $this->db->fetchAssociative(
                'SELECT id, name, email, phone, age, location, avatar_path, is_host, personal_photo
                   FROM users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            );
        } catch (\Throwable) {
            $hasPersonalPhoto = false;
            try {
                $user = $this->db->fetchAssociative(
                    'SELECT id, name, email, phone, age, location, avatar_path, is_host
                       FROM users WHERE id = :id LIMIT 1',
                    ['id' => $userId]
                );
            } catch (\Throwable $e) {
                error_log('editprofile: users select failed: ' . $e->getMessage());
                throw $this->createNotFoundException('Sorry, something went wrong. Please try again later.');
            }
        }

        if (!$user) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        $user['id'] = (int)$user['id'];

        if ((int)$user['is_host'] === 1) {
            return new RedirectResponse('/host/profile');
        }

        /* ---- SELF-HEAL — user_id_documents table ---- */
        try {
            $this->db->executeStatement("
                CREATE TABLE IF NOT EXISTS user_id_documents (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    id_type VARCHAR(60) NOT NULL,
                    id_number VARCHAR(80) NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                    admin_note VARCHAR(255) NULL,
                    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    reviewed_at DATETIME NULL,
                    INDEX idx_uid (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            error_log('editprofile: could not ensure user_id_documents table: ' . $e->getMessage());
        }

        $errors   = [];
        $saved    = false;
        $savedId  = $request->query->get('saved') === 'id';

        /* =========================================================
           PERSONAL PHOTO XHR — the camera / Choose-File JS posts
           upload_personal=1 to THIS URL (/profile/edit). Answer it
           as JSON by delegating to the dedicated endpoint.
        ========================================================== */
        if ($request->isMethod('POST') && $request->request->get('upload_personal')) {
            return $this->uploadPersonal($request);
        }

        /* =========================================================
           ID UPLOAD
        ========================================================== */
        if ($request->isMethod('POST') && $request->request->get('upload_id')) {

            $idType   = trim((string)$request->request->get('id_type', ''));
            $idNumber = trim((string)$request->request->get('id_number', ''));
            $file     = $request->files->get('id_file');

            if (!$this->csrfOk($request)) {
                $errors[] = 'Your session expired. Please try uploading again.';
            } elseif (!isset(self::ID_TYPES[$idType])) {
                $errors[] = 'Please choose a valid ID type.';
            } elseif ($idNumber === '' || mb_strlen($idNumber) > 80) {
                $errors[] = 'Please enter the ID number (max 80 characters).';
            } elseif (!$file instanceof UploadedFile || !$file->isValid()) {
                $errors[] = 'Please choose a file or take a photo.';
            } elseif ((int)$file->getSize() > 5 * 1024 * 1024) {
                $errors[] = 'File is too large. Maximum size is 5MB.';
            } else {
                $ext        = strtolower($file->getClientOriginalExtension() ?: '');
                $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

                if (!in_array($ext, $allowedExt, true) || !in_array($file->getMimeType(), $allowedMime, true)) {
                    $errors[] = 'Invalid file type. Allowed: JPG, PNG, WEBP, or PDF.';
                } else {
                    $dir = $this->publicDir() . '/uploads/id_documents';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }

                    $newName = 'uid' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

                    try {
                        $file->move($dir, $newName);
                        $storedPath = '/uploads/id_documents/' . $newName;

                        $profileComplete = $this->profileComplete($user);
                        $docStatus       = $profileComplete ? 'verified' : 'pending';

                        $this->db->insert('user_id_documents', [
                            'user_id'     => $userId,
                            'id_type'     => $idType,
                            'id_number'   => $idNumber,
                            'file_path'   => $storedPath,
                            'status'      => $docStatus,
                            'uploaded_at' => date('Y-m-d H:i:s'),
                        ]);

                        if ($profileComplete) {
                            $this->notify(
                                $userId,
                                "You're verified! Your Verified badge is now active and you can list a space.",
                                '/profile'
                            );
                        }

                        return new RedirectResponse('/profile/edit?saved=id#verify-card');
                    } catch (\Throwable $e) {
                        error_log('editprofile: ID insert/move failed: ' . $e->getMessage());
                        $errors[] = 'Could not save your ID. Please try again.';
                        @unlink($dir . '/' . $newName);
                    }
                }
            }
        }

        /* =========================================================
           PROFILE SAVE + AUTO-VERIFY PROMOTION
        ========================================================== */
        $justVerified = false;

        if ($request->isMethod('POST') && !$request->request->get('upload_id')) {
            if (!$this->csrfOk($request)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $name     = trim((string)$request->request->get('name', ''));
                $phone    = trim((string)$request->request->get('phone', ''));
                $age      = trim((string)$request->request->get('age', ''));
                $location = trim((string)$request->request->get('location', ''));

                if ($name === '')     $errors[] = 'Full name is required.';
                if ($phone === '')    $errors[] = 'Phone number is required to get verified.';
                if ($age === '')      $errors[] = 'Age is required to get verified.';
                if ($location === '') $errors[] = 'Location is required to get verified.';
                if ($age !== '' && (!ctype_digit($age) || (int)$age < 18 || (int)$age > 120)) {
                    $errors[] = 'Age must be a number between 18 and 120.';
                }

                if (empty($errors)) {
                    $this->db->update('users', [
                        'name'     => $name,
                        'phone'    => $phone,
                        'age'      => (int)$age,
                        'location' => $location,
                    ], ['id' => $userId]);

                    $user['name']     = $name;
                    $user['phone']    = $phone;
                    $user['age']      = $age;
                    $user['location'] = $location;

                    $saved = true;

                    try {
                        $hasDoc = (int)$this->db->fetchOne(
                            "SELECT COUNT(*) FROM user_id_documents WHERE user_id = :u AND status != 'rejected'",
                            ['u' => $userId]
                        );

                        if ($hasDoc > 0 && $this->profileComplete($user)) {
                            $promoted = $this->db->executeStatement(
                                "UPDATE user_id_documents
                                    SET status = 'verified', reviewed_at = NOW()
                                  WHERE user_id = :u AND status = 'pending'",
                                ['u' => $userId]
                            );

                            if ($promoted > 0) {
                                $justVerified = true;
                                $this->notify(
                                    $userId,
                                    "You're verified! Your Verified badge is now active and you can list a space.",
                                    '/profile'
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        error_log('editprofile: auto-verify promotion failed: ' . $e->getMessage());
                    }
                }
            }
        }

        /* ---- last 5 ID documents ---- */
        $idDocs = [];
        try {
            $idDocs = $this->db->fetchAllAssociative(
                'SELECT id, id_type, id_number, file_path, status, admin_note, uploaded_at
                   FROM user_id_documents
                  WHERE user_id = :u
                  ORDER BY uploaded_at DESC
                  LIMIT 5',
                ['u' => $userId]
            );
        } catch (\Throwable) {
            $idDocs = [];
        }

        $isVerified     = $this->isUserVerified($userId);
        $hasIdNoProfile = !$isVerified && !empty($idDocs);

        /* =========================================================
           FIX (line 694) — map the RAW DB row to the exact keys
           the template uses. The template reads user.avatar /
           user.personal; the DB gives avatar_path / personal_photo.
        ========================================================== */
        $ageRaw = $user['age'] ?? null;
        $userView = [
            'name'     => (string)($user['name'] ?? ''),
            'avatar'   => !empty($user['avatar_path'])
                ? (string)$user['avatar_path']
                : '/images/default-avatar.png',
            'personal' => ($hasPersonalPhoto && !empty($user['personal_photo']))
                ? (string)$user['personal_photo']
                : '',
            'email'    => (string)($user['email'] ?? ''),
            'phone'    => (string)($user['phone'] ?? ''),
            'age'      => ($ageRaw === null || $ageRaw === '') ? '' : (string)$ageRaw,
            'location' => (string)($user['location'] ?? ''),
        ];

        return $this->render('user/profile_edit.html.twig', [
            'user'               => $userView,
            'idTypes'            => self::ID_TYPES,
            'idDocs'             => $idDocs,
            'isVerified'         => $isVerified,
            'hasIdNoProfile'     => $hasIdNoProfile,
            'errors'             => $errors,
            'saved'              => $saved,
            'savedId'            => $savedId,
            'justVerified'       => $justVerified,
            'notification_count' => $this->notifCount($userId),
        ]);
    }

    /* =====================================================================
     |  PERSONAL PHOTO — legacy uploadpersonal.php, folded in here.
     |  Answers the JSON contract the camera JS expects.
     | ================================================================== */

    #[Route('/profile/edit/upload-personal', name: 'app_profile_upload_personal', methods: ['POST'], priority: 2)]
    public function uploadPersonal(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(['ok' => false, 'message' => 'Please sign in again.'], 401);
        }

        /* The camera / Choose-File JS posts the page's _csrf_token along. */
        if (!$this->csrfOk($request)) {
            return $this->json(['ok' => false, 'message' => 'Your session expired — reload the page and try again.'], 400);
        }

        $file = $request->files->get('personal_photo');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json(['ok' => false, 'message' => 'Please choose a file or take a photo.'], 400);
        }
        if ((int)$file->getSize() > 5 * 1024 * 1024) {
            return $this->json(['ok' => false, 'message' => 'File is too large. Maximum size is 5MB.'], 400);
        }

        $ext         = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $allowedExt  = ['jpg', 'jpeg', 'png', 'webp'];
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];

        if (!in_array($ext, $allowedExt, true) || !in_array($file->getMimeType(), $allowedMime, true)) {
            return $this->json(['ok' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, or WEBP.'], 400);
        }

        $dir = $this->publicDir() . '/uploads/personal_photos';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $newName = 'personal' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        try {
            $file->move($dir, $newName);

            $storedPath = '/uploads/personal_photos/' . $newName;
            $this->db->update('users', ['personal_photo' => $storedPath], ['id' => $userId]);

            return $this->json(['ok' => true, 'url' => $storedPath]);
        } catch (\Throwable $e) {
            error_log('editprofile: personal photo upload failed: ' . $e->getMessage());
            return $this->json(['ok' => false, 'message' => 'Could not save the photo. Please try again.'], 500);
        }
    }

    /* =====================================================================
     |  HELPERS — dashboard (cover photo probing)
     | ================================================================== */

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

    /**
     * FIX — legacyPhotoUrl(): same resolver ListingsController and
     * WishlistController use. The old coverUrl() pointed every photo
     * at /uploads/listings/ (a directory that does not exist), so
     * booking thumbs and wishlist cards on this page fell back to
     * the placeholder. This one handles the legacy DB values:
     * webprogg/…, host/…, uploads/…, cover_… and bare filenames.
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

    /* Kept for compatibility — every caller now goes through
       legacyPhotoUrl() so legacy paths resolve correctly. */
    private function coverUrl(string $path): string
    {
        return $this->legacyPhotoUrl($path);
    }

    /* =====================================================================
     |  HELPERS — edit-profile
     | ================================================================== */

    private function publicDir(): string
    {
        return (string)$this->getParameter('kernel.project_dir') . '/public';
    }

    /**
     * Legacy ep_profile_complete(): name, phone, age, location.
     * All four filled = profile complete.
     */
    private function profileComplete(array $u): bool
    {
        return trim((string)($u['name'] ?? '')) !== ''
            && trim((string)($u['phone'] ?? '')) !== ''
            && (($u['age'] ?? null) !== null && $u['age'] !== '')
            && trim((string)($u['location'] ?? '')) !== '';
    }

    /**
     * Legacy is_user_verified(): a 'verified' row in user_id_documents.
     */
    private function isUserVerified(int $userId): bool
    {
        try {
            return (int)$this->db->fetchOne(
                "SELECT COUNT(*) FROM user_id_documents WHERE user_id = :u AND status = 'verified'",
                ['u' => $userId]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Legacy ep_notify(): best-effort INSERT into notifications. */
    private function notify(int $userId, string $message, string $link): void
    {
        try {
            $this->db->insert('notifications', [
                'user_id'    => $userId,
                'message'    => mb_substr($message, 0, 240),
                'link'       => $link,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) { /* best-effort */
        }
    }

    /**
     * Validates the token your template renders with
     * csrf_token('profile_edit'). Same token id on both sides —
     * this is what makes SAVE CHANGES, UPLOAD ID and the
     * personal-photo XHR all pass.
     */
    private function csrfOk(Request $request): bool
    {
        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || $token === '') {
            $token = $request->request->get('csrf_token');
        }
        return is_string($token)
            && $token !== ''
            && $this->isCsrfTokenValid(self::CSRF_KEY, $token);
    }

    private function notifCount(int $userId): int
    {
        try {
            return (int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0',
                ['id' => $userId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}
