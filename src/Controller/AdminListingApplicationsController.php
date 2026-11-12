<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Listings Application.
 * 1:1 port of legacy admin/listingapplication.php.
 *
 *   GET|POST /admin/listing-applications   → review listings submitted
 *     by hosts before they go live. Approve / reject / delete from
 *     the table row OR from the right-hand detail panel.
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * Lists every listing WHERE status != 'draft' (a host hasn't
 * finished the wizard yet if status is still 'draft'). Joined to
 * users for the host's name. Approve/reject notify the host via
 * bell notification. Delete = safe transactional cascade
 * (conversations DETACHED, then photos/wishlist/reviews/
 * bookings/listings).
 *
 * Filter tabs: All, Pending, Approved, Rejected.
 * Pagination: 8 per page.
 *
 * The "eye" detail page at /admin/listing-applications/{id} is
 * a separate controller (AdminListingApplicationEyeController).
 */
class AdminListingApplicationsController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    private const PER_PAGE = 8;

    private const VALID_FILTERS = ['all', 'pending', 'approved', 'rejected'];

    private const VALID_ACTIONS = ['approve', 'reject', 'delete'];

    private const BADGE_CLASS_MAP = [
        'Pending'  => 'badge-pending',
        'Approved' => 'badge-approved',
        'Rejected' => 'badge-rejected',
        'Unlisted' => 'badge-unlisted',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/listing-applications', name: 'app_admin_listing_applications', methods: ['GET', 'POST'])]
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
           HANDLE ACTIONS (POST): approve / reject / delete
           CSRF-verified; host notified on approve/reject/delete;
           delete = cascading transaction with conversation detach.
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('action')) {
            $postedToken = (string) $request->request->get('csrf_token', '');
            $targetId   = (int) $request->request->get('id', 0);
            $action     = (string) $request->request->get('action', '');

            if (!hash_equals($adminCsrf, $postedToken)
                || $targetId <= 0
                || !in_array($action, self::VALID_ACTIONS, true)) {
                $this->addFlash('admin_listing_apps_flash', [
                    'type' => 'error',
                    'text' => 'That request could not be verified.',
                ]);
            } else {
                /* owner + title needed for notifications + delete cascade */
                $listingRow = $this->db->fetchAssociative(
                    'SELECT user_id, title FROM listings WHERE id = :id LIMIT 1',
                    ['id' => $targetId]
                );

                if (!$listingRow) {
                    $this->addFlash('admin_listing_apps_flash', [
                        'type' => 'error',
                        'text' => 'That listing no longer exists.',
                    ]);
                } elseif (in_array($action, ['approve', 'reject'], true)) {
                    $newStatus = $action === 'approve' ? 'approved' : 'rejected';

                    try {
                        $this->db->executeStatement(
                            'UPDATE listings SET status = :status, updated_at = NOW() WHERE id = :id',
                            ['status' => $newStatus, 'id' => $targetId]
                        );

                        $this->addFlash('admin_listing_apps_flash', [
                            'type' => 'success',
                            'text' => 'Listing #' . $targetId . ' marked ' . $newStatus . '.',
                        ]);

                        /* Notify host — matching legacy wording */
                        $this->notifyHost(
                            (int) $listingRow['user_id'],
                            $this->buildMessage($action, (string) $listingRow['title']),
                            $action === 'approve'
                                ? '/listings/' . $targetId
                                : '/profile'
                        );
                    } catch (\Throwable $e) {
                        error_log('listingapplication: ' . $action . ' failed: ' . $e->getMessage());
                        $this->addFlash('admin_listing_apps_flash', [
                            'type' => 'error',
                            'text' => 'Could not update that listing.',
                        ]);
                    }
                } elseif ($action === 'delete') {
                    try {
                        $this->db->beginTransaction();
                        $this->cascadingDelete($targetId);
                        $this->db->commit();

                        $this->addFlash('admin_listing_apps_flash', [
                            'type' => 'success',
                            'text' => 'Listing #' . $targetId . ' permanently deleted.',
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
                        error_log('listingapplication: delete failed: ' . $e->getMessage());
                        $this->addFlash('admin_listing_apps_flash', [
                            'type' => 'error',
                            'text' => 'Could not delete that listing.',
                        ]);
                    }
                }
            }

            /* Redirect back to the same filter/page */
            return $this->redirectToRoute('app_admin_listing_applications', [
                'filter' => (string) $request->query->get('filter', 'all'),
                'page'   => (int) $request->query->get('page', 1),
            ]);
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* =========================================================
           LOAD LISTINGS (skip unfinished drafts)
        ========================================================= */
        $rows = $this->db->fetchAllAssociative(
            "SELECT l.id, l.title, l.location, l.property_type, l.price,
                    l.description, l.amenities, l.status, l.created_at,
                    u.name AS host_name
             FROM listings l
             JOIN users u ON u.id = l.user_id
             WHERE l.status != 'draft'
             ORDER BY l.created_at DESC"
        );

        /* ---- Decode amenities JSON once per row ---- */
        $listingApps = array_map(function (array $r): array {
            return [
                'id'        => (int) $r['id'],
                'title'     => (string) $r['title'],
                'host'      => (string) $r['host_name'],
                'city'      => (string) $r['location'],
                'type'      => ucfirst((string) $r['property_type']),
                'price'     => '₱' . number_format((float) $r['price']) . ' / month',
                'date'      => date('M j, Y', strtotime((string) $r['created_at'])),
                'time'      => date('g:i A', strtotime((string) $r['created_at'])),
                'status'    => ucfirst((string) $r['status']),
                'desc'      => (string) $r['description'],
                'amenities' => json_decode((string) ($r['amenities'] ?? '[]'), true) ?? [],
            ];
        }, $rows);

        /* ---- ui-avatars fallback per listing (same look as legacy) ---- */
        foreach ($listingApps as &$l) {
            $l['avatar'] = 'https://ui-avatars.com/api/?background=1C2A38&color=fff&bold=true&name='
                . urlencode((string) $l['host']);
        }
        unset($l);

        /* =========================================================
           FILTERS — filter tabs (all/pending/approved/rejected)
        ========================================================= */
        $filter = (string) $request->query->get('filter', 'all');
        if (!in_array($filter, self::VALID_FILTERS, true)) {
            $filter = 'all';
        }

        $counts = [
            'all'      => count($listingApps),
            'pending'  => count(array_filter($listingApps, fn ($a) => $a['status'] === 'Pending')),
            'approved' => count(array_filter($listingApps, fn ($a) => $a['status'] === 'Approved')),
            'rejected' => count(array_filter($listingApps, fn ($a) => $a['status'] === 'Rejected')),
        ];

        $filtered = $filter === 'all'
            ? $listingApps
            : array_values(array_filter(
                $listingApps,
                fn ($a) => strtolower($a['status']) === $filter
            ));

        /* =========================================================
           PAGINATION — 8 per page
        ========================================================= */
        $totalItems = count($filtered);
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page       = max(1, min($totalPages, (int) $request->query->get('page', 1)));
        $offset     = ($page - 1) * self::PER_PAGE;
        $pageItems  = array_slice($filtered, $offset, self::PER_PAGE);

        /* ---- Selected listing (right-hand detail panel) ---- */
        $selectedId = (int) $request->query->get('id', 0);
        if ($selectedId === 0 && !empty($pageItems)) {
            $selectedId = $pageItems[0]['id'];
        }
        $selected = null;
        foreach ($listingApps as $l) {
            if ($l['id'] === $selectedId) {
                $selected = $l;
                break;
            }
        }

        /* ---- Flash from the session (after redirect) ---- */
        $flash = null;
        $flashBag = $request->getSession()->getFlashBag()->get('admin_listing_apps_flash');
        if (!empty($flashBag)) {
            $flash = $flashBag[0];
        }

        return $this->render('admin/listing_applications.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'page_items'        => $pageItems,
            'counts'            => $counts,
            'filter'            => $filter,
            'page'              => $page,
            'total_pages'        => $totalPages,
            'total_items'       => $totalItems,
            'offset'            => $offset,
            'per_page'          => self::PER_PAGE,
            'selected'          => $selected,

            'badge_class_map'   => self::BADGE_CLASS_MAP,
            'required_documents' => [
                'Land Title / Lease Contract',
                'Barangay Business Clearance',
                'Property Photos (Exterior & Interior)',
                'Fire Safety Certificate',
            ],
            'flash'             => $flash,
        ]);
    }

    /* =====================================================================
     *  CASCADING DELETE — DETACH conversations (listing_id = NULL)
     *  instead of destroying them. Then DELETE photos/wishlist/reviews/
     *  bookings/listings. Mirrors AdminListingsController::cascadingDelete
     *  but without the per-table try/catch (caller wraps in transaction).
     * ================================================================= */

    private function cascadingDelete(int $listingId): void
    {
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
            error_log('listingapplication delete: conversations detach skipped: ' . $e->getMessage());
        }

        foreach (['listing_photos', 'wishlist', 'reviews', 'bookings'] as $tbl) {
            try {
                $this->db->executeStatement(
                    "DELETE FROM $tbl WHERE listing_id = :id",
                    ['id' => $listingId]
                );
            } catch (\Throwable $e) {
                /* table may not exist on fresh installs — skip silently */
            }
        }

        $this->db->executeStatement(
            'DELETE FROM listings WHERE id = :id',
            ['id' => $listingId]
        );
    }

    /* =====================================================================
     *  NOTIFIER + message/link builders — same as AdminListingsController.
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
            error_log('listingapplication notify failed: ' . $e->getMessage());
        }
    }

    private function buildMessage(string $action, string $title): string
    {
        return match ($action) {
            'approve' => 'Your listing "' . $title . '" was approved and is now live on RoomHive.',
            'reject'  => 'Your listing "' . $title . '" was not approved. Edit and resubmit it from My Listings.',
            default   => 'Your listing "' . $title . '" was updated by an administrator.',
        };
    }

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
