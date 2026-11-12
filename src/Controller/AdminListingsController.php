<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Listings.
 * 1:1 port of legacy admin/adminlistings.php.
 *
 *   GET|POST /admin/listings   → listings list (filter tabs +
 *     pagination) + 4 actions (approve / reject / unlist /
 *     delete) — all CSRF-verified + host notified on each.
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * FIXES preserved from legacy:
 *   - approve/reject/unlist/delete now notify the host (bell
 *     notification, same wording as listingapplication.php).
 *   - Delete DETACHES conversations (listing_id = NULL) instead
 *     of destroying them — the (tenant, host) chat history
 *     survives outside this listing.
 *
 * Filter tabs: All, Pending, Approved, Rejected, Unlisted.
 * Pagination: 12 per page.
 */
class AdminListingsController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    private const PER_PAGE = 12;

    /** Filter tabs can show these statuses. 'all' is special. */
    private const VALID_FILTERS = ['all', 'pending', 'approved', 'rejected', 'unlisted'];

    /** Actions the admin can take on a row. */
    private const VALID_ACTIONS = ['approve', 'reject', 'unlist', 'delete'];

    /** Map action verb → listings.status column value. */
    private const STATUS_ACTION_MAP = [
        'approve' => 'approved',
        'reject'  => 'rejected',
        'unlist'  => 'unlisted',
    ];

    /** Map for the badge class on a status label. */
    private const BADGE_CLASS_MAP = [
        'Pending'   => 'badge-pending',
        'Approved'  => 'badge-approved',
        'Rejected'  => 'badge-rejected',
        'Unlisted'  => 'badge-unlisted',
        'Confirmed' => 'badge-confirmed',
        'Completed' => 'badge-completed',
        'Cancelled' => 'badge-cancelled',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/listings', name: 'app_admin_listings', methods: ['GET', 'POST'])]
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

        /* =========================================================
           ACTIONS: approve / reject / unlist / delete
           CSRF-verified; host notified on each; delete DETACHES
           conversations (listing_id = NULL) instead of destroying
           them.
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('action')) {
            $postedToken = (string) $request->request->get('csrf_token', '');
            $listingId   = (int) $request->request->get('listing_id', 0);
            $action      = (string) $request->request->get('action', '');

            if (!hash_equals($adminCsrf, $postedToken)) {
                $this->addFlash('admin_listings_flash', [
                    'type' => 'error',
                    'text' => 'That request could not be verified.',
                ]);
            } elseif ($listingId <= 0 || !in_array($action, self::VALID_ACTIONS, true)) {
                $this->addFlash('admin_listings_flash', [
                    'type' => 'error',
                    'text' => 'Invalid listing or action.',
                ]);
            } else {
                /* Owner + title needed for notifications + delete cascade */
                $listingRow = $this->db->fetchAssociative(
                    'SELECT user_id, title FROM listings WHERE id = :id LIMIT 1',
                    ['id' => $listingId]
                );

                if (!$listingRow) {
                    $this->addFlash('admin_listings_flash', [
                        'type' => 'error',
                        'text' => 'That listing no longer exists.',
                    ]);
                } elseif (in_array($action, ['approve', 'reject', 'unlist'], true)) {
                    $newStatus = self::STATUS_ACTION_MAP[$action];

                    try {
                        $this->db->executeStatement(
                            'UPDATE listings SET status = :s, updated_at = NOW() WHERE id = :id',
                            ['s' => $newStatus, 'id' => $listingId]
                        );

                        $this->addFlash('admin_listings_flash', [
                            'type' => 'success',
                            'text' => 'Listing #' . $listingId . ' marked ' . $newStatus . '.',
                        ]);

                        /* Notify the host (matching legacy wording) */
                        $this->notifyHost(
                            (int) $listingRow['user_id'],
                            $this->buildMessage($action, (string) $listingRow['title']),
                            $this->buildLink($action, $listingId)
                        );
                    } catch (\Throwable $e) {
                        error_log('adminlistings: ' . $action . ' failed: ' . $e->getMessage());
                        $this->addFlash('admin_listings_flash', [
                            'type' => 'error',
                            'text' => 'Could not update that listing.',
                        ]);
                    }
                } elseif ($action === 'delete') {
                    try {
                        $this->db->beginTransaction();
                        $this->cascadingDelete($listingId);
                        $this->db->commit();

                        $this->addFlash('admin_listings_flash', [
                            'type' => 'success',
                            'text' => 'Listing #' . $listingId . ' permanently deleted.',
                        ]);

                        $this->notifyHost(
                            (int) $listingRow['user_id'],
                            'Your listing "' . (string) $listingRow['title'] . '" was removed by an administrator.',
                            '/profile'
                        );
                    } catch (\Throwable $e) {
                        if ($this->db->isTransactionActive()) {
                            $this->db->rollBack();
                        }
                        error_log('adminlistings: delete failed: ' . $e->getMessage());
                        $this->addFlash('admin_listings_flash', [
                            'type' => 'error',
                            'text' => 'Could not delete that listing.',
                        ]);
                    }
                }
            }

            /* Redirect back to the page the admin was viewing */
            return $this->redirectToRoute('app_admin_listings', [
                'status' => (string) $request->request->get('ret_status', 'all'),
                'page'   => (int) $request->request->get('ret_page', 1),
            ]);
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* =========================================================
           FILTERS — status tabs + pagination
        ========================================================= */
        $status = (string) $request->query->get('status', 'all');
        if (!in_array($status, self::VALID_FILTERS, true)) {
            $status = 'all';
        }
        $page = max(1, (int) $request->query->get('page', 1));

        $where  = [];
        $params = [];
        if ($status !== 'all') {
            $where[]      = 'l.status = :st';
            $params['st'] = $status;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        /* ---- Total count for pagination ---- */
        $totalItems = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM listings l $whereSql",
            $params
        );
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * self::PER_PAGE;

        /* ---- Page rows ----
           Subqueries for cover_photo + photo_count + booking_count
           mirror the legacy SELECT exactly — EXCEPT the cover photo
           now falls back to ANY photo if no photo_type='cover' row
           exists (some listings have photos but none marked cover,
           so the subquery used to return NULL and the page showed a
           blank image box). */
        $listings = $this->db->fetchAllAssociative(
            "SELECT l.id, l.title, l.location, l.property_type, l.price,
                    l.status, l.created_at,
                    u.name AS host_name,
                    (SELECT photo_path FROM listing_photos lp
                       WHERE lp.listing_id = l.id
                       ORDER BY (lp.photo_type = 'cover') DESC, lp.id ASC
                       LIMIT 1) AS cover_photo,
                    (SELECT COUNT(*) FROM listing_photos lp
                       WHERE lp.listing_id = l.id) AS photo_count,
                    (SELECT COUNT(*) FROM bookings b
                       WHERE b.listing_id = l.id) AS booking_count
             FROM listings l
             JOIN users u ON u.id = l.user_id
             $whereSql
             ORDER BY l.created_at DESC
             LIMIT " . self::PER_PAGE . ' OFFSET ' . $offset,
            $params
        );

        /* ---- Normalize cover_photo paths ----
           The DB stores paths in legacy formats that don't work as
           browser URLs (e.g. "webprogg/uploads/...", "host/uploads/...",
           or just "cover_xxx.jpg"). resolvePhoto() turns them into
           valid /uploads/... URLs. Mirrors the same helper in
           AdminController + AccountController. */
        foreach ($listings as &$row) {
            $row['cover_photo'] = $this->resolvePhoto((string) ($row['cover_photo'] ?? ''));
        }
        unset($row);

        /* ---- Per-status counts for the filter tabs ---- */
        $countRows = $this->db->fetchAllKeyValue(
            'SELECT status, COUNT(*) AS c FROM listings GROUP BY status'
        );
        $counts = [];
        foreach ($countRows as $k => $v) {
            $counts[(string) $k] = (int) $v;
        }
        $cnt             = fn(string $k): int => $counts[$k] ?? 0;
        $cntAll          = array_sum($counts);

        /* ---- Flash from the session (after redirect) ---- */
        $flash = null;
        $flashBag = $request->getSession()->getFlashBag()->get('admin_listings_flash');
        if (!empty($flashBag)) {
            $flash = $flashBag[0];
        }

        return $this->render('admin/listings.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'listings'           => $listings,
            'status'             => $status,
            'page'               => $page,
            'total_pages'        => $totalPages,

            'cnt_all'            => $cntAll,
            'cnt_pending'        => $cnt('pending'),
            'cnt_approved'       => $cnt('approved'),
            'cnt_rejected'       => $cnt('rejected'),
            'cnt_unlisted'       => $cnt('unlisted'),

            'badge_class_map'    => self::BADGE_CLASS_MAP,
            'flash'              => $flash,
        ]);
    }

    /* =====================================================================
     *  CASCADING DELETE — DETACH conversations (listing_id = NULL)
     *  instead of destroying them. Then DELETE photos/bookings/
     *  reviews/wishlist/listings in one transaction.
     * ================================================================= */

    private function cascadingDelete(int $listingId): void
    {
        /* Detach conversations — the (tenant, host) chat history
           may still matter outside this listing. Column may not
           exist on fresh installs; skip silently. */
        try {
            $convCols = $this->db->fetchFirstColumn(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'conversations'"
            );
            if (in_array('listing_id', $convCols, true)) {
                $this->db->executeStatement(
                    'UPDATE conversations SET listing_id = NULL WHERE listing_id = :id',
                    ['id' => $listingId]
                );
            }
        } catch (\Throwable $e) {
            error_log('adminlistings: conversations detach skipped: ' . $e->getMessage());
        }

        /* DELETE photos / bookings / reviews / wishlist */
        foreach (['listing_photos', 'bookings', 'reviews', 'wishlist'] as $tbl) {
            try {
                $this->db->executeStatement(
                    "DELETE FROM $tbl WHERE listing_id = :id",
                    ['id' => $listingId]
                );
            } catch (\Throwable $e) {
                /* table may not exist on fresh installs — skip silently */
            }
        }

        /* DELETE the listing itself */
        $this->db->executeStatement(
            'DELETE FROM listings WHERE id = :id',
            ['id' => $listingId]
        );
    }

    /* =====================================================================
     *  NOTIFIER — bell notification to the host. Mirrors
     *  admin_notify_user() in legacy adminlistings.php + the
     *  notifyUser() helper in AdminController.
     * ================================================================= */

    private function notifyHost(int $userId, string $message, string $link): void
    {
        if ($userId <= 0 || trim($message) === '') {
            return;
        }
        try {
            $this->db->executeStatement(
                'INSERT INTO notifications (user_id, message, link, is_read, created_at)
                 VALUES (:u, :m, :l, 0, NOW())',
                [
                    'u' => $userId,
                    'm' => mb_substr(trim($message), 0, 240),
                    'l' => $link,
                ]
            );
        } catch (\Throwable $e) {
            error_log('adminlistings notify failed: ' . $e->getMessage());
        }
    }

    private function buildMessage(string $action, string $title): string
    {
        return match ($action) {
            'approve' => 'Your listing "' . $title . '" was approved and is now live on RoomHive.',
            'reject'  => 'Your listing "' . $title . '" was not approved. Edit and resubmit it from My Listings.',
            'unlist'  => 'Your listing "' . $title . '" was unlisted by an administrator and is hidden from search.',
            default   => 'Your listing "' . $title . '" was updated by an administrator.',
        };
    }

    private function buildLink(string $action, int $listingId): string
    {
        return match ($action) {
            'approve' => '/listings/' . $listingId,
            default   => '/profile',
        };
    }

    /* =====================================================================
     *  NOTIFICATIONS badge count for the topbar bell.
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

    /* =====================================================================
     *  PHOTO PATH RESOLVER
     *  Mirrors AdminController::resolvePhoto + AccountController::resolvePhoto
     *  + the legacy admin_resolve_photo() helper in admin_init.php.
     *
     *  Stored formats handled:
     *    "" / null             → fallback (/images/ListingPlaceholder.png)
     *    "https://..."         → as-is
     *    "/absolute/path"      → as-is
     *    "webprogg/uploads/x.jpg" → /uploads/x.jpg
     *    "host/uploaded/x.jpg"    → /uploads/host/uploaded/x.jpg
     *    "uploads/x.jpg"       → /uploads/x.jpg
     *    "cover_xxx.jpg"       → /uploads/listing_photos/cover/cover_xxx.jpg
     *    "xxx.jpg"             → /uploads/listing_photos/xxx.jpg
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
        return '/uploads/listing_photos/' . $path;
    }
}
