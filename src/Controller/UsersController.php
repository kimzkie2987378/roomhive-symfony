<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Users.
 * 1:1 port of legacy admin/adminusers.php.
 *
 *   GET|POST /admin/users   → user list (search + role filter)
 *     + cascading delete (POST with delete_user_id)
 *     + detail view (GET with ?user=ID — populates the detail modal).
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * Self-heal: personal_photo column on users + user_id_documents
 *   table are ensured on first request — both used by the detail
 *   modal's verification breakdown + personal photo section.
 *
 * Cascading delete: 7 phases in a single transaction. See
 *   cascadingDelete() for the full per-table list.
 *
 * Detail modal: every per-table count is queried SEPARATELY and
 *   guarded — a missing/mismatched table shows "—" instead of
 *   zeroing out all the others. Real totals drive the
 *   "X of Y" section headers.
 */
class UsersController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/users', name: 'app_admin_users', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->get('admin_logged_in')) {
            return $this->redirect('/admin/login');
        }

        $adminName  = (string) ($session->get('admin_name')  ?? 'Admin User');
        $adminEmail = (string) ($session->get('admin_email') ?? '');

        /* ---- CSRF token (per-session, separate from user-flow) ---- */
        $adminCsrf = (string) $session->get(self::CSRF_KEY);
        if ($adminCsrf === '') {
            $adminCsrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $adminCsrf);
        }

        /* ---- Self-heal: personal_photo column + user_id_documents table ---- */
        $this->ensurePersonalPhotoColumn();
        $this->ensureUserIdDocumentsTable();

        /* =========================================================
           DELETE USER (POST) — cascading, transactional.
        ========================================================= */
        $flash = null;

        if ($request->isMethod('POST') && $request->request->has('delete_user_id')) {
            $targetUserId = (int) $request->request->get('delete_user_id');
            $postedToken  = (string) $request->request->get('csrf_token', '');
            $tokenOk      = hash_equals($adminCsrf, $postedToken);

            if (!$tokenOk || $targetUserId <= 0) {
                return $this->redirectToRoute('app_admin_users', ['error' => 'bad_request']);
            }

            $targetUser = $this->db->fetchAssociative(
                'SELECT id, name FROM users WHERE id = :id LIMIT 1',
                ['id' => $targetUserId]
            );

            if (!$targetUser) {
                return $this->redirectToRoute('app_admin_users', ['error' => 'not_found']);
            }

            try {
                $this->db->beginTransaction();
                $this->cascadingDelete($targetUserId);
                $this->db->commit();

                return $this->redirectToRoute('app_admin_users', [
                    'deleted' => $targetUser['name'],
                ]);
            } catch (\Throwable $e) {
                if ($this->db->isTransactionActive()) {
                    $this->db->rollBack();
                }
                error_log('adminusers delete failed: ' . $e->getMessage());

                return $this->redirectToRoute('app_admin_users', ['error' => 'delete_failed']);
            }
        }

        /* ---- Flash from query string (after redirect) ---- */
        if ($request->query->has('deleted')) {
            $flash = [
                'type' => 'success',
                'text' => $request->query->get('deleted') . ' was deleted, along with all of their data.',
            ];
        } elseif ($request->query->has('error')) {
            $messages = [
                'delete_failed' => 'Something went wrong while deleting that user. Nothing was changed.',
                'not_found'     => 'That user no longer exists.',
                'bad_request'   => 'That request could not be verified. Please try again.',
            ];
            $err   = (string) $request->query->get('error');
            $flash = [
                'type' => 'error',
                'text' => $messages[$err] ?? 'Something went wrong.',
            ];
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* =========================================================
           FILTERS — search + role
        ========================================================= */
        $search = trim((string) $request->query->get('q', ''));
        $role   = (string) $request->query->get('role', 'all');

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]           = '(u.name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)';
            $params['search']  = '%' . $search . '%';
        }
        if ($role === 'host') {
            $where[] = 'u.is_host = 1';
        } elseif ($role === 'guest') {
            $where[] = 'u.is_host = 0';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        /* =========================================================
           USERS LIST
        ========================================================= */
        $sql = "SELECT u.id, u.name, u.email, u.phone, u.age, u.avatar_path, u.location,
                       u.created_at, u.is_host,
                       (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.id) AS booking_count,
                       (SELECT COUNT(*) FROM listings l WHERE l.user_id = u.id) AS listing_count,
                       (SELECT COUNT(*) FROM reviews r WHERE r.user_id = u.id) AS review_count
                FROM users u
                $whereSql
                ORDER BY u.created_at DESC";
        $users = $this->db->fetchAllAssociative($sql, $params);

        $totalUsersCount = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users');
        $totalHostsCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM users WHERE is_host = 1'
        );

        /* =========================================================
           USER DETAIL — ?user=ID
        ========================================================= */
        $detailUser = null;
        $detail     = [
            'totals'   => ['bookings' => 0, 'listings' => 0, 'reviews' => 0],
            'docs'     => [],
            'bookings' => [],
            'listings' => [],
            'reviews'  => [],
            'counts'   => [
                'wishlist' => null,
                'saved'  => null,
                'notifs' => null,
                'tickets'  => null,
                'convos' => null,
                'msgs'   => null,
                'hive'     => false,
                'hive_tier' => null,
            ],
        ];
        $verHasId      = false;
        $verIdStatus   = null;
        $verMissing    = [];
        $detailVerified = false;

        $detailId = (int) $request->query->get('user', 0);

        if ($detailId > 0) {
            $detailUser = $this->db->fetchAssociative(
                'SELECT id, name, email, phone, age, location, avatar_path,
                        is_host, created_at, personal_photo
                 FROM users WHERE id = :id LIMIT 1',
                ['id' => $detailId]
            );

            if (!$detailUser) {
                return $this->redirectToRoute('app_admin_users');
            }

            /* ---- Verification breakdown (accurate) ---- */
            try {
                $verIdStatus = (string) $this->db->fetchOne(
                    "SELECT status FROM user_id_documents
                     WHERE user_id = :u AND status != 'rejected'
                     ORDER BY uploaded_at DESC
                     LIMIT 1",
                    ['u' => $detailId]
                );
                $verHasId = $verIdStatus !== '';
            } catch (\Throwable $e) {
                $verHasId = false;
            }

            if (trim((string) ($detailUser['name'] ?? '')) === '') {
                $verMissing[] = 'Name';
            }
            if (trim((string) ($detailUser['phone'] ?? '')) === '') {
                $verMissing[] = 'Phone';
            }
            if (!isset($detailUser['age']) || $detailUser['age'] === null || $detailUser['age'] === '') {
                $verMissing[] = 'Age';
            }
            if (trim((string) ($detailUser['location'] ?? '')) === '') {
                $verMissing[] = 'Location';
            }
            $detailVerified = $verHasId && empty($verMissing);

            /* ---- ID documents ---- */
            try {
                $detail['docs'] = $this->db->fetchAllAssociative(
                    'SELECT id_type, id_number, file_path, status, admin_note, uploaded_at
                     FROM user_id_documents
                     WHERE user_id = :u
                     ORDER BY uploaded_at DESC
                     LIMIT 5',
                    ['u' => $detailId]
                );
            } catch (\Throwable $e) {
                $detail['docs'] = [];
            }

            /* ---- Bookings (LEFT JOIN so deleted listings still show) ---- */
            try {
                $detail['totals']['bookings'] = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM bookings WHERE user_id = :u',
                    ['u' => $detailId]
                );
            } catch (\Throwable $e) {
            }

            try {
                $detail['bookings'] = $this->db->fetchAllAssociative(
                    "SELECT b.id, b.total, b.status, b.booked_at,
                            COALESCE(l.title, 'Listing removed') AS title
                     FROM bookings b
                     LEFT JOIN listings l ON l.id = b.listing_id
                     WHERE b.user_id = :u
                     ORDER BY b.booked_at DESC
                     LIMIT 5",
                    ['u' => $detailId]
                );
            } catch (\Throwable $e) {
                $detail['bookings'] = [];
            }

            /* ---- Listings ---- */
            try {
                $detail['totals']['listings'] = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM listings WHERE user_id = :u',
                    ['u' => $detailId]
                );
            } catch (\Throwable $e) {
            }

            try {
                $detail['listings'] = $this->db->fetchAllAssociative(
                    "SELECT l.id, l.title, l.price, l.status, l.created_at,
                            p.photo_path AS cover_photo
                     FROM listings l
                     LEFT JOIN listing_photos p
                            ON p.listing_id = l.id AND p.photo_type = 'cover'
                     WHERE l.user_id = :u
                     ORDER BY l.created_at DESC
                     LIMIT 5",
                    ['u' => $detailId]
                );
            } catch (\Throwable $e) {
                $detail['listings'] = [];
            }

            /* ---- Reviews (LEFT JOIN so reviews on deleted listings still count) ---- */
            try {
                $detail['totals']['reviews'] = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM reviews WHERE user_id = :u',
                    ['u' => $detailId]
                );
            } catch (\Throwable $e) {
            }

            try {
                $detail['reviews'] = $this->db->fetchAllAssociative(
                    "SELECT r.rating, r.created_at,
                            COALESCE(l.title, 'Listing removed') AS title
                     FROM reviews r
                     LEFT JOIN listings l ON l.id = r.listing_id
                     WHERE r.user_id = :u
                     ORDER BY r.created_at DESC
                     LIMIT 5",
                    ['u' => $detailId]
                );
            } catch (\Throwable $e) {
                $detail['reviews'] = [];
            }

            /* ---- Per-table counts — each guarded independently ---- */
            $countTables = [
                'wishlist' => 'SELECT COUNT(*) FROM wishlist WHERE user_id = :u',
                'saved'    => 'SELECT COUNT(*) FROM saved_searches WHERE user_id = :u',
                'notifs'   => 'SELECT COUNT(*) FROM notifications WHERE user_id = :u',
                'tickets'  => 'SELECT COUNT(*) FROM support_tickets WHERE user_id = :u',
            ];

            foreach ($countTables as $key => $cntSql) {
                try {
                    $detail['counts'][$key] = (int) $this->db->fetchOne(
                        $cntSql,
                        ['u' => $detailId]
                    );
                } catch (\Throwable $e) {
                    $detail['counts'][$key] = null;
                }
            }

            /* Conversations both directions */
            try {
                $detail['counts']['convos'] = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM conversations WHERE user_id = :u1 OR host_id = :u2',
                    ['u1' => $detailId, 'u2' => $detailId]
                );
            } catch (\Throwable $e) {
                $detail['counts']['convos'] = null;
            }

            /* Messages sent */
            try {
                $detail['counts']['msgs'] = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM messages WHERE sender_id = :u',
                    ['u' => $detailId]
                );
            } catch (\Throwable $e) {
                $detail['counts']['msgs'] = null;
            }

            /* Hive Club tier */
            try {
                $tier = (string) $this->db->fetchOne(
                    'SELECT tier FROM hive_members
                     WHERE user_id = :u ORDER BY id DESC LIMIT 1',
                    ['u' => $detailId]
                );
                if ($tier !== '') {
                    $detail['counts']['hive']      = true;
                    $detail['counts']['hive_tier'] = $tier;
                }
            } catch (\Throwable $e) {
                /* stays default: hive=false, hive_tier=null → shows "—" */
            }
        }

        return $this->render('admin/users.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'users'              => $users,
            'total_users_count'  => $totalUsersCount,
            'total_hosts_count'  => $totalHostsCount,
            'search'             => $search,
            'role'               => $role,
            'flash'              => $flash,

            'detail_user'        => $detailUser,
            'detail'             => $detail,
            'ver_has_id'         => $verHasId,
            'ver_id_status'      => $verIdStatus,
            'ver_missing'        => $verMissing,
            'detail_verified'    => $detailVerified,
            'detail_id'          => $detailId,
        ]);
    }

    /* =====================================================================
     *  CASCADING DELETE — 7 phases in a single transaction.
     *  Mirrors legacy adminusers.php delete block 1:1.
     * ================================================================= */

    private function cascadingDelete(int $userId): void
    {
        /* 1. LISTINGS OWNED BY THE USER (and all their dependents) */
        $ownedListingIds = $this->db->fetchFirstColumn(
            'SELECT id FROM listings WHERE user_id = :uid',
            ['uid' => $userId]
        );

        if ($ownedListingIds) {
            /* conversations.listing_id may not exist; detach if it does */
            try {
                $convCols = $this->db->fetchFirstColumn(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'conversations'"
                );
                if (in_array('listing_id', $convCols, true)) {
                    $this->db->executeStatement(
                        'UPDATE conversations SET listing_id = NULL WHERE listing_id IN (?)',
                        [$ownedListingIds],
                        [Connection::PARAM_INT_ARRAY]
                    );
                }
            } catch (\Throwable $e) {
                error_log('adminusers cascade: conversations detach skipped: ' . $e->getMessage());
            }

            $this->deleteIn('DELETE FROM bookings WHERE listing_id IN (?)', $ownedListingIds);
            $this->deleteIn('DELETE FROM reviews WHERE listing_id IN (?)', $ownedListingIds);
            $this->deleteIn('DELETE FROM wishlist WHERE listing_id IN (?)', $ownedListingIds);
            $this->deleteIn('DELETE FROM listing_photos WHERE listing_id IN (?)', $ownedListingIds);
            $this->deleteIn('DELETE FROM listings WHERE id IN (?)', $ownedListingIds);
        }

        /* 2. CONVERSATIONS BOTH DIRECTIONS + THEIR MESSAGES */
        try {
            $convIds = $this->db->fetchFirstColumn(
                'SELECT id FROM conversations WHERE user_id = :uid OR host_id = :uid2',
                ['uid' => $userId, 'uid2' => $userId]
            );

            if ($convIds) {
                $this->deleteIn('DELETE FROM messages WHERE conversation_id IN (?)', $convIds);
                $this->deleteIn('DELETE FROM conversations WHERE id IN (?)', $convIds);
            }

            $this->db->executeStatement(
                'DELETE FROM messages WHERE sender_id = :uid',
                ['uid' => $userId]
            );
        } catch (\Throwable $e) {
            error_log('adminusers cascade: conversations/messages: ' . $e->getMessage());
        }

        /* 3-7. OWN ROWS + ACCOUNT */
        $this->deleteBy('DELETE FROM bookings WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM reviews WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM wishlist WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM notifications WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM saved_searches WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM support_tickets WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM notification_settings WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM notification_preferences WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM payouts WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM payout_methods WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM user_id_documents WHERE user_id = :uid', $userId);

        /* Hive Club — cascade through hive_members */
        try {
            $hiveMemberIds = $this->db->fetchFirstColumn(
                'SELECT id FROM hive_members WHERE user_id = :uid',
                ['uid' => $userId]
            );

            if ($hiveMemberIds) {
                $this->deleteIn(
                    'DELETE FROM hiveclub_transactions WHERE hive_member_id IN (?)',
                    $hiveMemberIds
                );
            }
        } catch (\Throwable $e) {
            /* hive_members may not exist — skip silently */
        }

        $this->deleteBy('DELETE FROM hiveclub_transactions WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM hive_redemptions WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM hive_points_ledger WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM hive_members WHERE user_id = :uid', $userId);

        $this->deleteBy('DELETE FROM host_applications WHERE user_id = :uid', $userId);
        $this->deleteBy('DELETE FROM users WHERE id = :uid', $userId);
    }

    private function deleteIn(string $sql, array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        try {
            $this->db->executeStatement(
                $sql,
                [$ids],
                [Connection::PARAM_INT_ARRAY]
            );
        } catch (\Throwable $e) {
            /* table may not exist on fresh installs — skip silently */
        }
    }

    private function deleteBy(string $sql, int $userId): void
    {
        try {
            $this->db->executeStatement($sql, ['uid' => $userId]);
        } catch (\Throwable $e) {
            /* table may not exist on fresh installs — skip silently */
        }
    }

    /* =====================================================================
     *  SELF-HEAL — ensure columns/tables exist before any SELECT.
     * ================================================================= */

    private function ensurePersonalPhotoColumn(): void
    {
        try {
            $exists = (bool) $this->db->fetchOne(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'personal_photo'
                 LIMIT 1"
            );
            if (!$exists) {
                $this->db->executeStatement(
                    'ALTER TABLE users ADD COLUMN personal_photo VARCHAR(255) NULL'
                );
            }
        } catch (\Throwable $e) {
            error_log('adminusers: personal_photo ensure failed: ' . $e->getMessage());
        }
    }

    private function ensureUserIdDocumentsTable(): void
    {
        try {
            $exists = (bool) $this->db->fetchOne(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'user_id_documents'
                 LIMIT 1"
            );
            if (!$exists) {
                $this->db->executeStatement(
                    "CREATE TABLE user_id_documents (
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
            }
        } catch (\Throwable $e) {
            error_log('adminusers: user_id_documents ensure failed: ' . $e->getMessage());
        }
    }

    /* =====================================================================
     *  NOTIFICATIONS badge count for the topbar bell.
     * ================================================================= */

    private function countPending(): int
    {
        try {
            $hostApps   = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM host_applications WHERE status = 'pending'"
            );
            $listings   = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM listings WHERE status = 'pending'"
            );
            return $hostApps + $listings;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
