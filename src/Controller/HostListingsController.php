<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › My Listings.
 * 1:1 port of legacy user/mylistings.php (the card-style listing
 * manager — NOT the admin grid; the admin grid lives in
 * AdminListingApplicationsController).
 *
 *   GET /host/listings   → the logged-in host's own listings as
 *     cards: cover photo + status pill + "Price Locked" badge,
 *     title / location / ₱ price per month, bookings stat line
 *     (ALL booking requests — any status, cancelled included;
 *     user request: "someone had book it but cancel" must still
 *     be recorded), REAL Views (listings.views,
 *     counted on every detail-page visit by
 *     ListingsController::detail) and REAL Rating (AVG of the
 *     reviews table — "—" while the listing has no reviews),
 *     pending-applicant strip ("{name} applied — awaiting your
 *     decision") or the green "Currently occupied" strip, plus
 *     Edit Listing and Delete Listing actions. Empty state: "No
 *     listings yet" + Add New Listing pointer.
 *
 *   Page header button "Add New Listing" → /host/become-a-host
 *     (legacy becomeahost.php — the listing wizard entry).
 *
 * THE "SOMEONE ON THE SPACE" LOCK (legacy docblock, enforced in
 *   the ENDPOINTS, not here): a listing with any ACTIVE booking
 *   (status 'pending' = paid in full, awaiting decision, or
 *   'confirmed' = occupied) is LOCKED for price and status
 *   changes. Cosmetic fields (title, description, house rules,
 *   amenities, exact address, photos) remain editable. This page
 *   only SURFACES the lock: "Price Locked" badge, the occupied
 *   strip, and the client-side delete guard
 *   (data-has-active → alert). Server-side enforcement belongs
 *   to edit-listing.php / delete-listing.php (SELECT ... FOR
 *   UPDATE re-check) when those files are pasted and ported.
 *
 * OCCUPIED FLAG (this port): has_active_booking counts pending
 *   AND confirmed bookings (legacy "locked" semantics — price
 *   locks while booked or reserving), so it cannot distinguish
 *   "someone applied" from "someone lives here". Each row also
 *   carries is_occupied = a CONFIRMED booking exists on the
 *   listing — someone actually staying. The template uses it to
 *   block Accept Tenant and Edit Listing while the space is
 *   occupied ("cannot accept applying users if the space is
 *   occupied / can edit if no one occupied"), and links the
 *   cover photo + title to /listings/{id} (see the details).
 *   AcceptBookingController re-checks the same rule server-side.
 *
 * ACTION ENDPOINTS (wired here + sibling controllers):
 *     POST /host/listings/{id}/delete   → HostDeleteListingController
 *                                       (legacy delete-listing.php —
 *                                       ported: ownership + CSRF +
 *                                       the reserved/occupied lock,
 *                                       transactional cascade;
 *                                       redirects back here with
 *                                       ?deleted=1 / ?delete_error=…)
 *     GET  /host/listings/edit?id=N     (HostEditListingController)
 *   The 3-dot kebab (Accept/Reject) was removed on request —
 *   that flow lives on the Pending Tenants page.
 *
 * RESULT BANNERS — this page reads the delete endpoint's GET
 *   flags: ?deleted=1 renders the green "Listing deleted"
 *   banner; ?delete_error=occupied|csrf|notfound|failed renders
 *   the matching red banner. (Same GET-flag convention as the
 *   listing detail page.)
 *
 * CSRF — the Delete form's token: per-session _rh_csrf_tokens
 *   bucket key 'host_listing_delete', seeded here and validated
 *   by HostDeleteListingController (same store + helpers as
 *   HostEditListingController).
 *
 * Auth: same guard as HostProfileController — logged_in + user_id
 *   (else /login), then is_host = 1 (else /host/become-a-host;
 *   legacy redirected non-hosts to userprofile.php — this port
 *   routes them to the host onboarding instead, same convention
 *   as every host controller).
 *
 * NO POST HANDLING HERE — the page renders markup only; every
 *   action lives in its own endpoint (Edit = link, Delete = form
 *   POST to HostDeleteListingController, which redirects back
 *   here with its result flag).
 */
class HostListingsController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/host/listings', name: 'app_host_listings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostProfileController) ---- */
        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* ---- Load the user row ---- */
        $dbUser = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host, created_at
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if (!$dbUser) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }

        /* ---- HOST GUARD — non-hosts go to Become a Host ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* =========================================================
           DELETE RESULT FLAGS — the delete endpoint redirects back
           with ?deleted=1 / ?delete_error=… (same GET-flag
           convention as the listing detail page). Read once,
           rendered as banners by the template.
        ========================================================= */
        $deleted     = $request->query->getBoolean('deleted');
        $deleteError = (string) $request->query->get('delete_error', '');

        /* =========================================================
           CSRF for the Delete form — per-session token under the
           'host_listing_delete' bucket key (same _rh_csrf_tokens
           store as the edit page; HostDeleteListingController
           validates it).
        ========================================================= */
        $csrfToken = $this->ensureCsrfToken($session, 'host_listing_delete');

        /* =========================================================
           VIEWS COLUMN SELF-HEAL — the listings.views column does
           not exist in the legacy dump; create it on first run so
           the Views stat below (and the detail-page counter)
           always have something to read. try/catch: a failure is
           logged and the Views stat degrades to 0.
        ========================================================= */
        $this->ensureListingViewsColumn();

        /* =========================================================
           BADGE COUNTS — navbar bell + sidebar pending tenants.
           Legacy computed the unread count once and shared it with
           the sidebar ($hostNotifUnread) — same single query here.
        ========================================================= */
        $notificationCount   = $this->countUnreadNotifications($userId);
        $pendingTenantsCount = $this->countPendingTenants($userId);

        /* ---- Host array (mirrors legacy $host from host_init) ---- */
        $host = [
            'name'         => (string) $dbUser['name'],
            'avatar'       => !empty($dbUser['avatar_path'])
                ? $this->resolvePhoto((string) $dbUser['avatar_path'], '/images/default-avatar.png')
                : '/images/default-avatar.png',
            'member_since' => date('F Y', strtotime((string) $dbUser['created_at'])),
            'location'     => '',
            'email'        => (string) $dbUser['email'],
            'phone'        => '',
            'age'          => '',
            'about'        => '',
        ];

        /* =========================================================
           MY LISTINGS + active-booking awareness — legacy query,
           verbatim (LEFT JOIN cover photo only — NO fallback-to-
           any-photo here, unlike the admin grid; a listing with no
           cover row just renders the placeholder).
        ========================================================= */
        $listings = $this->db->fetchAllAssociative(
            "SELECT l.id, l.title, l.location, l.exact_address, l.price,
                    l.status, l.created_at,
                    p.photo_path AS cover_photo,
                    pb.id AS pending_booking_id, tu.name AS pending_tenant_name,
                    (SELECT COUNT(*) FROM bookings b
                      WHERE b.listing_id = l.id
                        AND b.status IN ('pending', 'confirmed')) AS active_booking_count,
                    (SELECT COUNT(*) FROM bookings b3
                      WHERE b3.listing_id = l.id
                        AND b3.status = 'confirmed') AS confirmed_booking_count
             FROM listings l
             LEFT JOIN listing_photos p
                    ON p.listing_id = l.id AND p.photo_type = 'cover'
             LEFT JOIN bookings pb ON pb.listing_id = l.id AND pb.status = 'pending'
             LEFT JOIN users tu ON tu.id = pb.user_id
             WHERE l.user_id = :id
             ORDER BY l.created_at DESC",
            ['id' => $userId]
        );

        /* =========================================================
           PER-LISTING BOOKING COUNTS — EVERY booking request on
           the space, ANY status (pending, confirmed, completed,
           rejected, cancelled). USER CHANGE: "someone had book it
           but cancel" must still be recorded, so the legacy
           confirmed+completed filter is dropped and the stat
           answers "how many people booked this place". The DELETE
           lock still counts ONLY pending + confirmed — cancelled
           bookings never block deletion.
        ========================================================= */
        $bookingCounts = [];
        foreach ($listings as $l) {
            $bookingCounts[(int) $l['id']] = 0;
        }

        if (!empty($listings)) {
            $countRows = $this->db->fetchAllAssociative(
                "SELECT b.listing_id, COUNT(*) AS booking_count
                 FROM bookings b
                 JOIN listings l ON l.id = b.listing_id
                 WHERE l.user_id = :id
                 GROUP BY b.listing_id",
                ['id' => $userId]
            );
            foreach ($countRows as $row) {
                $bookingCounts[(int) $row['listing_id']] = (int) $row['booking_count'];
            }
        }

        /* =========================================================
           PER-LISTING RATINGS — AVG of the reviews table per
           listing (same source the public listings grid uses),
           restricted to this host's listings. try/catch: a
           missing table = every card falls back to "—".
        ========================================================= */
        $ratingsById = [];
        try {
            $ratingRows = $this->db->fetchAllAssociative(
                "SELECT r.listing_id, AVG(r.rating) AS avg_rating,
                        COUNT(*) AS review_count
                 FROM reviews r
                 JOIN listings l ON l.id = r.listing_id
                 WHERE l.user_id = :id
                 GROUP BY r.listing_id",
                ['id' => $userId]
            );
            foreach ($ratingRows as $r) {
                $ratingsById[(int) $r['listing_id']] = [
                    'avg'   => round((float) $r['avg_rating'], 1),
                    'count' => (int) $r['review_count'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('host listings: ratings query skipped: ' . $e->getMessage());
            $ratingsById = [];
        }

        /* =========================================================
           PER-LISTING VIEW COUNTS — the listings.views column,
           incremented by ListingsController::detail on every
           detail-page visit. Separate grouped query + try/catch
           so a missing column can never 500 the whole page (the
           stat degrades to 0 instead).
        ========================================================= */
        $viewsById = [];
        try {
            $viewRows = $this->db->fetchAllAssociative(
                'SELECT id, views FROM listings WHERE user_id = :id',
                ['id' => $userId]
            );
            foreach ($viewRows as $v) {
                $viewsById[(int) $v['id']] = (int) $v['views'];
            }
        } catch (\Throwable $e) {
            error_log('host listings: views query skipped: ' . $e->getMessage());
            $viewsById = [];
        }

        /* ---- Normalize each row for the template ---- */
        $rows = [];
        foreach ($listings as $l) {
            $id       = (int) $l['id'];
            $status   = (string) $l['status'];
            $hasActive  = ((int) $l['active_booking_count']) > 0;
            $isOccupied = ((int) ($l['confirmed_booking_count'] ?? 0)) > 0;

            $rows[] = [
                'id'                  => $id,
                'title'               => (string) $l['title'],
                'location'            => (string) $l['location'],
                'price'               => (float) $l['price'],
                'status'              => $status,
                'status_class'        => $this->statusClass($status),
                'status_label'        => $this->statusLabel($status),
                'cover_photo'         => $this->resolvePhoto(
                    (string) ($l['cover_photo'] ?? ''),
                    '/images/ListingPlaceholder.png'
                ),
                'pending_booking_id'  => $l['pending_booking_id'] !== null
                    ? (int) $l['pending_booking_id']
                    : null,
                'pending_tenant_name' => (string) ($l['pending_tenant_name'] ?? ''),
                'booking_count'       => $bookingCounts[$id] ?? 0,
                'view_count'          => $viewsById[$id] ?? 0,
                'rating_avg'          => $ratingsById[$id]['avg'] ?? null,
                'rating_count'        => $ratingsById[$id]['count'] ?? 0,
                'has_active_booking'  => $hasActive,
                'is_occupied'         => $isOccupied,
            ];
        }

        return $this->render('host/listings.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'listings'              => $rows,

            /* Delete result banner flags + the Delete form's CSRF token */
            'deleted'               => $deleted,
            'delete_error'          => $deleteError,
            'csrf_host_listing_delete' => $csrfToken,
        ]);
    }

    /* =====================================================================
     *  STATUS HELPERS — 1:1 ports of the legacy hp_status_class() /
     *  hp_status_label() switches (approved renders as "Active").
     * ================================================================= */

    private function statusClass(string $status): string
    {
        switch ($status) {
            case 'approved':
                return 'hp-status-active';
            case 'pending':
                return 'hp-status-pending';
            case 'rejected':
                return 'hp-status-rejected';
            case 'unlisted':
                return 'hp-status-unlisted';
            default:
                return 'hp-status-pending';
        }
    }

    private function statusLabel(string $status): string
    {
        switch ($status) {
            case 'approved':
                return 'Active';
            case 'pending':
                return 'Pending';
            case 'rejected':
                return 'Rejected';
            case 'unlisted':
                return 'Unlisted';
            default:
                return ucfirst($status);
        }
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostProfileController.
     * ================================================================= */

    private function countUnreadNotifications(int $userId): int
    {
        try {
            return (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function countPendingTenants(int $hostUserId): int
    {
        try {
            return (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM bookings b
                 JOIN listings l ON l.id = b.listing_id
                 WHERE l.user_id = :h AND b.status = 'pending'",
                ['h' => $hostUserId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* =====================================================================
     *  CSRF SEED — same _rh_csrf_tokens store as
     *  HostEditListingController / HostDeleteListingController.
     * ================================================================= */

    private function ensureCsrfToken(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, string $key): string
    {
        $tokens = $session->get('_rh_csrf_tokens', []);
        if (!isset($tokens[$key])) {
            $tokens[$key] = bin2hex(random_bytes(16));
            $session->set('_rh_csrf_tokens', $tokens);
        }
        return (string) $tokens[$key];
    }

    /* =====================================================================
     *  VIEWS COLUMN SELF-HEAL — listings.views (INT UNSIGNED NOT
     *  NULL DEFAULT 0) does not exist in the legacy dump. Same
     *  SHOW COLUMNS pattern as AuthController's users-column
     *  self-heal; ListingsController::detail runs the same heal
     *  before it increments, so either page can create it first.
     * ================================================================= */

    private function ensureListingViewsColumn(): void
    {
        try {
            if ($this->db->fetchOne("SHOW COLUMNS FROM listings LIKE 'views'") !== false) {
                return;
            }
            $this->db->executeStatement(
                'ALTER TABLE listings ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0'
            );
        } catch (\Throwable $e) {
            error_log('host listings: views self-heal skipped: ' . $e->getMessage());
        }
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same as HostProfileController /
     *  AdminController / AccountController.
     *
     *  Stored formats handled:
     *    "" / null                → fallback
     *    "https://..."            → as-is
     *    "/absolute/path"         → as-is
     *    "webprogg/uploads/x.jpg" → /uploads/x.jpg
     *    "host/uploaded/x.jpg"    → /uploads/host/uploaded/x.jpg
     *    "uploads/x.jpg"          → /uploads/x.jpg
     *    "cover_xxx.jpg"          → /uploads/listing_photos/cover/cover_xxx.jpg
     *    "xxx.jpg"                → /uploads/listing_photos/xxx.jpg
     * ================================================================= */

    private function resolvePhoto(string $path, string $fallback = '/images/ListingPlaceholder.png'): string
    {
        $path = trim($path);
        if ($path === '') {
            return $fallback;
        }
        /* BUGFIX: no leading-slash early-return — DB values like
           /webprogg/uploads/avatars/… must be remapped, not echoed
           (same fix as HostPublicProfileController) */
        if (preg_match('#^https?://#i', $path)) {
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
