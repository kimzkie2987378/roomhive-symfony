<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Listing Application Eye (full-detail viewer).
 * 1:1 port of legacy admin/listingapplicationeye.php.
 *
 *   GET|POST /admin/listing-applications/{id}   → everything the host
 *     filled in across the listing wizard — host-step2.php ("Add Your
 *     Space") + host-step3.php ("Upload Photos") — with the actual
 *     images shown inline, not just linked.
 *
 *   POST with action=approve|reject + id + csrf_token → updates
 *     listings.status, notifies the host (same wording as the list
 *     page so the decision is identical from either screen).
 *
 * Auth: same admin session keys as AdminController.
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * Photo paths are normalized via resolvePhoto() (same helper as
 *   AdminListingsController) — DB stores them relative to /webprogg
 *   which would 404 against the current page's folder without
 *   normalization.
 *
 * Amenity labels mirror host-step2.php's keys.
 */
class AdminListingApplicationEyeController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    private const VALID_ACTIONS = ['approve', 'reject'];

    private const BADGE_CLASS_MAP = [
        'Pending'  => 'badge-pending',
        'Approved' => 'badge-approved',
        'Rejected' => 'badge-rejected',
        'Unlisted' => 'badge-unlisted',
    ];

    /** Amenity keys → labels (same as host-step2.php). */
    private const AMENITY_LABELS = [
        'wifi'             => 'Wi-fi',
        'aircon'           => 'Aircon',
        'pet-friendly'     => 'Pet Friendly',
        'free-water'       => 'Free Water',
        'free-electricity' => 'Free Electricity',
        'security'         => '24/7 Security',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/listing-applications/{id}', name: 'app_admin_listing_application_eye', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function view(int $id, Request $request): Response
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

        $listingId = (int) $id;
        $filter    = (string) $request->query->get('filter', 'all');
        $page      = (int) $request->query->get('page', 1);

        /* =========================================================
           HANDLE APPROVE / REJECT (POST)
           CSRF-verified; the host is notified of the decision
           (same wording as the list page).
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('action')) {
            $postedToken = (string) $request->request->get('csrf_token', '');
            $targetId   = (int) $request->request->get('id', 0);
            $action     = (string) $request->request->get('action', '');

            if (hash_equals($adminCsrf, $postedToken)
                && $targetId > 0
                && in_array($action, self::VALID_ACTIONS, true)) {
                $newStatus = $action === 'approve' ? 'approved' : 'rejected';

                /* owner + title needed for the notification */
                $listingRow = $this->db->fetchAssociative(
                    'SELECT user_id, title FROM listings WHERE id = :id LIMIT 1',
                    ['id' => $targetId]
                );

                try {
                    $this->db->executeStatement(
                        'UPDATE listings SET status = :status, updated_at = NOW() WHERE id = :id',
                        ['status' => $newStatus, 'id' => $targetId]
                    );

                    if ($listingRow) {
                        $message = $newStatus === 'approved'
                            ? 'Your listing "' . $listingRow['title'] . '" was approved and is now live on RoomHive.'
                            : 'Your listing "' . $listingRow['title'] . '" was not approved. Edit and resubmit it from My Listings.';
                        $link = $newStatus === 'approved'
                            ? '/listings/' . $targetId
                            : '/profile';

                        $this->notifyHost((int) $listingRow['user_id'], $message, $link);
                    }
                } catch (\Throwable $e) {
                    error_log('listingapplicationeye: ' . $action . ' failed: ' . $e->getMessage());
                }
            }

            /* Redirect back to this eye page (now shows updated status) */
            return $this->redirectToRoute('app_admin_listing_application_eye', [
                'id'     => $targetId,
                'filter' => $filter,
                'page'   => $page,
            ]);
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* =========================================================
           LOAD THE LISTING (Step 2 data — "Add Your Space")
        ========================================================= */
        $listing = $this->db->fetchAssociative(
            'SELECT l.id, l.title, l.category, l.property_type, l.location, l.exact_address,
                    l.price, l.capacity, l.bedrooms, l.bathrooms, l.size_sqm, l.floor,
                    l.parking, l.amenities, l.description, l.house_rules, l.status,
                    l.created_at,
                    u.name AS host_name, u.email AS host_email, u.phone AS host_phone
             FROM listings l
             JOIN users u ON u.id = l.user_id
             WHERE l.id = :id
             LIMIT 1',
            ['id' => $listingId]
        );

        if (!$listing) {
            return $this->redirectToRoute('app_admin_listing_applications');
        }

        /* =========================================================
           LOAD PHOTOS (Step 3 data — "Upload Photos")
        ========================================================= */
        $coverPhoto = (string) $this->db->fetchOne(
            "SELECT photo_path FROM listing_photos
             WHERE listing_id = :id AND photo_type = 'cover' LIMIT 1",
            ['id' => $listingId]
        );

        $additionalPhotos = $this->db->fetchFirstColumn(
            "SELECT photo_path FROM listing_photos
             WHERE listing_id = :id AND photo_type = 'additional'
             ORDER BY sort_order ASC",
            ['id' => $listingId]
        );

        /* ---- Normalize all photo paths through resolvePhoto() ---- */
        $coverPhoto = $this->resolvePhoto($coverPhoto);
        $additionalPhotos = array_map(fn ($p): string => $this->resolvePhoto((string) $p), $additionalPhotos);

        /* ---- Decode amenities JSON ---- */
        $selectedAmenities = json_decode((string) ($listing['amenities'] ?? '[]'), true) ?? [];

        /* ---- Flash from the session (after redirect) ---- */
        $flash = null;
        $flashBag = $request->getSession()->getFlashBag()->get('admin_listing_eye_flash');
        if (!empty($flashBag)) {
            $flash = $flashBag[0];
        }

        return $this->render('admin/listing_application_eye.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'listing'             => $listing,
            'cover_photo'         => $coverPhoto,
            'additional_photos'   => $additionalPhotos,
            'selected_amenities'  => $selectedAmenities,
            'amenity_labels'      => self::AMENITY_LABELS,
            'status_label'        => ucfirst((string) $listing['status']),

            'filter'             => $filter,
            'page'               => $page,
            'badge_class_map'    => self::BADGE_CLASS_MAP,
            'flash'              => $flash,
        ]);
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same as AdminListingsController::resolvePhoto.
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
            error_log('listingapplicationeye notify failed: ' . $e->getMessage());
        }
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
