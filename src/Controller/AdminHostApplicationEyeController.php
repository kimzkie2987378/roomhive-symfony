<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Host Application Eye (full-detail viewer).
 * 1:1 port of legacy admin/hostapplicationeye.php.
 *
 *   GET /admin/host-applications/{id}   → shows exactly what the
 *     applicant filled in on becomeahost.php — full name, email,
 *     phone, age, location, ID type/number — plus the actual
 *     uploaded ID photo shown inline, not just a link.
 *
 * Read-only by design (the legacy page has no forms today):
 *   approve / reject / delete stay on the list page
 *   (AdminHostApplicationsController). The CSRF token is still
 *   generated and passed to the template, so approve/reject can
 *   move here later without touching the plumbing — the legacy
 *   page did the same defensively.
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * ID PHOTO 404 FIX (carried over from the legacy page):
 *   host_applications.id_file is stored relative to /webprogg
 *   (same convention as listing_photos.photo_path). Printed raw,
 *   the browser resolves it against /admin/host-applications/ and
 *   the ID image 404s. resolvePhoto() forces it to a site-root
 *   absolute URL — the same helper the listing eye page uses.
 *   An empty result renders the "No ID file on record" panel.
 *
 * filter/page ride along in the query string so "Back to Host
 *   Applications" returns the admin to exactly where they were
 *   in the table (same convention as the legacy backLink).
 */
class AdminHostApplicationEyeController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    private const BADGE_CLASS_MAP = [
        'Pending'  => 'badge-pending',
        'Approved' => 'badge-approved',
        'Rejected' => 'badge-rejected',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/hostapplicationeye.php', methods: ['GET'])]
    #[Route('/webprogg/admin/hostapplicationeye.php', methods: ['GET'])]
    public function legacyView(Request $request): Response
    {
        $id = (int) $request->query->get('id', 0);
        if ($id <= 0) {
            return $this->redirectToRoute('app_admin_host_applications');
        }
        return $this->redirectToRoute('app_admin_host_application_eye', [
            'id'     => $id,
            'filter' => (string) $request->query->get('filter', 'all'),
            'page'   => max(1, (int) $request->query->get('page', 1)),
        ]);
    }

    #[Route('/admin/host-applications/{id}', name: 'app_admin_host_application_eye', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->get('admin_logged_in')) {
            return $this->redirect('/admin/login');
        }

        $adminName  = (string) ($session->get('admin_name')  ?? 'Admin User');
        $adminEmail = (string) ($session->get('admin_email') ?? '');

        /* ---- CSRF token (per-session) — available if approve/reject
           move here later; the legacy page carried it defensively ---- */
        $adminCsrf = (string) $session->get(self::CSRF_KEY);
        if ($adminCsrf === '') {
            $adminCsrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $adminCsrf);
        }

        /* filter/page are only carried along so Back returns the
           admin to exactly where they were in the table */
        $filter = (string) $request->query->get('filter', 'all');
        $page   = max(1, (int) $request->query->get('page', 1));

        /* =========================================================
           LOAD WHAT WAS FILLED IN ON becomeahost.php
        ========================================================= */
        $application = $this->db->fetchAssociative(
            'SELECT ha.id, ha.full_name, ha.email, ha.phone, ha.age, ha.location,
                    ha.id_type, ha.id_number, ha.id_file, ha.status, ha.created_at
             FROM host_applications ha
             WHERE ha.id = :id
             LIMIT 1',
            ['id' => (int) $id]
        );

        if (!$application) {
            return $this->redirectToRoute('app_admin_host_applications');
        }

        /* ---------- ID PHOTO PATH FIX ----------
           host_applications.id_file is stored relative to /webprogg
           (same convention as listing_photos.photo_path). Printed raw,
           the browser resolves it against /admin/host-applications/ and
           the ID photo 404s — the same bug the listing eye page fixed
           for listing photos. An empty result means "No ID file on
           record" and the template renders the missing panel. */
        $idFileUrl = $this->resolvePhoto((string) ($application['id_file'] ?? ''));

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        return $this->render('admin/host_application_eye.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'application'    => $application,
            'id_file_url'    => $idFileUrl,
            'status_label'   => ucfirst((string) $application['status']),
            'id_type_label'  => ucwords(str_replace('-', ' ', (string) $application['id_type'])),
            'avatar_initial' => mb_strtoupper(mb_substr((string) $application['full_name'], 0, 1)),

            'filter'          => $filter,
            'page'            => $page,
            'badge_class_map' => self::BADGE_CLASS_MAP,
        ]);
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same as AdminHostApplicationsController.
     *  Handles the legacy webprogg/-relative paths stored in
     *  host_applications.id_file (host/ prefix → /uploads/host/...).
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
