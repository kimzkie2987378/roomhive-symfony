<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Host Applications.
 * 1:1 port of legacy admin/hostapplication.php.
 *
 *   GET|POST /admin/host-applications   → review users who applied
 *     to become hosts. Approve / reject / delete from the table row
 *     OR from the right-hand detail panel.
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * Approve is the ONLY place is_host gets set to true — the
 *   transactional UPDATE flips users.is_host = 1 for the applicant.
 *   Reject explicitly demotes the account back to is_host = 0.
 *   Both decisions notify the applicant (bell).
 *
 * Delete simply removes the host_applications row — no cascade
 *   (mirrors the legacy code, which does a bare DELETE).
 *
 * Filter tabs: All, Pending, Approved, Rejected.
 * Pagination: 8 per page.
 *
 * ID-file links go through resolvePhoto() (same helper as
 *   AdminListingsController) so legacy webprogg/-relative paths
 *   resolve to site-root absolute URLs.
 *
 * The eye icon in each row opens the dedicated eye page
 *   (AdminHostApplicationEyeController — /admin/host-applications/{id},
 *   port of legacy hostapplicationeye.php) carrying filter/page so
 *   Back returns to the exact spot in the table. Clicking a row
 *   still selects that applicant in the right-hand detail panel.
 */
class AdminHostApplicationsController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    private const PER_PAGE = 8;

    private const VALID_FILTERS = ['all', 'pending', 'approved', 'rejected'];

    private const VALID_ACTIONS = ['approve', 'reject', 'delete'];

    private const BADGE_CLASS_MAP = [
        'Pending'  => 'badge-pending',
        'Approved' => 'badge-approved',
        'Rejected' => 'badge-rejected',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/host-applications', name: 'app_admin_host_applications', methods: ['GET', 'POST'])]
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
           CSRF-verified; applicant notified on approve/reject;
           approve flips is_host=1, reject demotes is_host=0.
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('action')) {
            $postedToken = (string) $request->request->get('csrf_token', '');
            $targetId   = (int) $request->request->get('id', 0);
            $action     = (string) $request->request->get('action', '');

            if (
                !hash_equals($adminCsrf, $postedToken)
                || $targetId <= 0
                || !in_array($action, self::VALID_ACTIONS, true)
            ) {
                $this->addFlash('admin_host_apps_flash', [
                    'type' => 'error',
                    'text' => 'That request could not be verified.',
                ]);
            } elseif (in_array($action, ['approve', 'reject'], true)) {
                $newStatus = $action === 'approve' ? 'approved' : 'rejected';

                /* Need the applicant's user_id before updating, so
                   we know whose account to flip. */
                $applicantUserId = (int) $this->db->fetchOne(
                    'SELECT user_id FROM host_applications WHERE id = :id',
                    ['id' => $targetId]
                );

                try {
                    $this->db->executeStatement(
                        'UPDATE host_applications SET status = :status, updated_at = NOW() WHERE id = :id',
                        ['status' => $newStatus, 'id' => $targetId]
                    );

                    /* Approve → is_host = 1; reject → is_host = 0 */
                    if ($applicantUserId > 0) {
                        $this->db->executeStatement(
                            'UPDATE users SET is_host = :v WHERE id = :id',
                            ['v' => $newStatus === 'approved' ? 1 : 0, 'id' => $applicantUserId]
                        );

                        if ($newStatus === 'approved') {
                            $this->notifyApplicant(
                                $applicantUserId,
                                'Congratulations! Your host application was approved. You can now list your space on RoomHive.',
                                '/host/profile'
                            );
                        } else {
                            $this->notifyApplicant(
                                $applicantUserId,
                                'Your host application was not approved this time. You can reapply anytime from the Become a Host page.',
                                '/host/become-a-host'
                            );
                        }
                    }

                    $this->addFlash('admin_host_apps_flash', [
                        'type' => 'success',
                        'text' => 'Application #' . $targetId . ' marked ' . $newStatus . '.',
                    ]);
                } catch (\Throwable $e) {
                    error_log('hostapplication: ' . $action . ' failed: ' . $e->getMessage());
                    $this->addFlash('admin_host_apps_flash', [
                        'type' => 'error',
                        'text' => 'Could not update that application.',
                    ]);
                }
            } elseif ($action === 'delete') {
                /* Bare DELETE — no cascade (mirrors legacy) */
                try {
                    $this->db->executeStatement(
                        'DELETE FROM host_applications WHERE id = :id',
                        ['id' => $targetId]
                    );
                    $this->addFlash('admin_host_apps_flash', [
                        'type' => 'success',
                        'text' => 'Application #' . $targetId . ' deleted.',
                    ]);
                } catch (\Throwable $e) {
                    error_log('hostapplication: delete failed: ' . $e->getMessage());
                    $this->addFlash('admin_host_apps_flash', [
                        'type' => 'error',
                        'text' => 'Could not delete that application.',
                    ]);
                }
            }

            /* Redirect back to the same filter/page */
            return $this->redirectToRoute('app_admin_host_applications', [
                'filter' => (string) $request->query->get('filter', 'all'),
                'page'   => (int) $request->query->get('page', 1),
            ]);
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* =========================================================
           LOAD HOST APPLICATIONS (joined to users for member-since)
        ========================================================= */
        $rows = $this->db->fetchAllAssociative(
            "SELECT ha.id, ha.full_name, ha.email, ha.phone, ha.location,
                    ha.id_type, ha.id_number, ha.id_file, ha.status,
                    ha.created_at, u.created_at AS user_created_at
             FROM host_applications ha
             JOIN users u ON u.id = ha.user_id
             ORDER BY ha.created_at DESC"
        );

        $hostApps = array_map(function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'name'         => (string) $r['full_name'],
                'email'        => (string) $r['email'],
                'phone'        => (string) $r['phone'],
                'city'         => (string) $r['location'],
                'member_since' => date('F Y', strtotime((string) $r['user_created_at'])),
                'date'         => date('M j, Y', strtotime((string) $r['created_at'])),
                'time'         => date('g:i A', strtotime((string) $r['created_at'])),
                'status'       => ucfirst((string) $r['status']),
                'id_type'      => (string) $r['id_type'],
                'id_number'    => (string) $r['id_number'],
                'id_file'      => $this->resolvePhoto((string) ($r['id_file'] ?? '')),
            ];
        }, $rows);

        /* ---- ui-avatars fallback per applicant (same look as legacy) ---- */
        foreach ($hostApps as &$a) {
            $a['avatar'] = 'https://ui-avatars.com/api/?background=EDA423&color=fff&bold=true&name='
                . urlencode((string) $a['name']);
        }
        unset($a);

        /* =========================================================
           FILTERS — filter tabs (all/pending/approved/rejected)
        ========================================================= */
        $filter = (string) $request->query->get('filter', 'all');
        if (!in_array($filter, self::VALID_FILTERS, true)) {
            $filter = 'all';
        }

        $counts = [
            'all'      => count($hostApps),
            'pending'  => count(array_filter($hostApps, fn($a) => $a['status'] === 'Pending')),
            'approved' => count(array_filter($hostApps, fn($a) => $a['status'] === 'Approved')),
            'rejected' => count(array_filter($hostApps, fn($a) => $a['status'] === 'Rejected')),
        ];

        $filtered = $filter === 'all'
            ? $hostApps
            : array_values(array_filter(
                $hostApps,
                fn($a) => strtolower($a['status']) === $filter
            ));

        /* =========================================================
           PAGINATION — 8 per page
        ========================================================= */
        $totalItems = count($filtered);
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page       = max(1, min($totalPages, (int) $request->query->get('page', 1)));
        $offset     = ($page - 1) * self::PER_PAGE;
        $pageItems  = array_slice($filtered, $offset, self::PER_PAGE);

        /* ---- Selected application (right-hand detail panel) ---- */
        $selectedId = (int) $request->query->get('id', 0);
        if ($selectedId === 0 && !empty($pageItems)) {
            $selectedId = $pageItems[0]['id'];
        }
        $selected = null;
        foreach ($hostApps as $a) {
            if ($a['id'] === $selectedId) {
                $selected = $a;
                break;
            }
        }

        /* ---- Flash from the session (after redirect) ---- */
        $flash = null;
        $flashBag = $request->getSession()->getFlashBag()->get('admin_host_apps_flash');
        if (!empty($flashBag)) {
            $flash = $flashBag[0];
        }

        return $this->render('admin/host_applications.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'page_items'         => $pageItems,
            'counts'             => $counts,
            'filter'             => $filter,
            'page'               => $page,
            'total_pages'        => $totalPages,
            'total_items'        => $totalItems,
            'offset'             => $offset,
            'per_page'           => self::PER_PAGE,
            'selected'           => $selected,

            'badge_class_map'    => self::BADGE_CLASS_MAP,
            'flash'              => $flash,
        ]);
    }

    /* =====================================================================
     *  NOTIFIER — bell notification to the applicant.
     *  Mirrors the notifyUser() helper in AdminController.
     * ================================================================= */

    private function notifyApplicant(int $userId, string $message, string $link): void
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
            error_log('hostapplication notify failed: ' . $e->getMessage());
        }
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same as AdminListingsController /
     *  AdminListingApplicationEyeController. Handles the legacy
     *  webprogg/-relative paths stored in host_applications.id_file.
     * ================================================================= */

    private function resolvePhoto(string $path, string $fallback = ''): string
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
        return '/uploads/' . $path;
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
