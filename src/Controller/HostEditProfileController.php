<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Edit Profile (host account editor).
 * 1:1 port of legacy host/hosteditprofile.php.
 *
 *   GET|POST /host/profile/edit   name app_host_edit_profile
 *
 *     (URL + sidebar key 'editprofile' were pre-reserved by
 *      host/base.html.twig's sidebar — the page the sidebar's
 *      "Profile & Account" link has been pointing at all along.)
 *
 * A real editor for the host account:
 *   - Saves Full Name / Phone / Age / Location (email is
 *     locked — same rule as the tenant editprofile.php port,
 *     ProfileController::edit()).
 *   - CSRF-protected POST with a self-contained hep_csrf token
 *     (legacy hosteditprofile.php defined its own hep_csrf_*
 *     helpers because host_init.php does NOT load functions.php
 *     — the port keeps the dedicated session key; same
 *     per-session pattern as HostPayoutsController's pay_csrf).
 *   - Legacy re-renders the form INLINE on save (no PRG): the
 *     up-alert success banner / error list render above the
 *     card, exactly like the legacy page. No rh_flash here.
 *   - Avatar camera upload posts to the SAME endpoint the
 *     tenant pages use — legacy uploadavatar.php was ONE
 *     endpoint for every shell (hosteditprofile.php,
 *     hostprofile.php and user editprofile.php all fetched
 *     /webprogg/user/uploadavatar.php). The port of that file
 *     already exists: POST /profile/avatar
 *     (ProfileController::uploadAvatar, route
 *     app_profile_avatar), so this page's JS posts there — no
 *     duplicate /host/profile/avatar route needed (that URL
 *     only ever existed as a TODO note in
 *     HostProfileController's docblock).
 *
 * LEGACY BEHAVIOUR KEPT INTACT:
 *   - Validation ladder verbatim: CSRF → name/phone/age/
 *     location required → age must be 18..120 → UPDATE →
 *     session refresh (user_name + avatar_path) → $saved.
 *   - PDO failure (missing columns etc.) logs and shows
 *     "Could not save your changes. Please try again." —
 *     same catch as legacy.
 *   - Email field renders disabled with the support hint.
 *   - hasRealAvatar rule: display avatar non-empty AND not
 *     the default-avatar placeholder (same rule as the
 *     navbar) — drives img vs icon in the avatar editor.
 *   - Badge counts: notification_count (navbar bell) +
 *     pending_tenants_count (sidebar), same queries as every
 *     host page.
 *
 * ICON SUBSTITUTIONS (macro keys, admin/_macros.html.twig):
 *     legacy `circle-check`      → macro 'check-circle'
 *     legacy `circle-x`          → macro 'x-circle'
 *     legacy `circle-user-round` → macro 'circle-user-round' (direct)
 *     legacy `user`              → macro 'user-round'
 *     legacy `cake`              → macro 'calendar-days'
 *     legacy `map-pin`           → macro 'pin'
 *     legacy `save`              → macro 'check'
 *   Direct keys: mail, phone, camera.
 *
 * Auth: logged_in + user_id (else app_login); non-host →
 *   /host/become-a-host (legacy becomeahost.php).
 */
class HostEditProfileController extends AbstractController
{
    /** Session key for the edit-profile CSRF token (legacy hep_csrf). */
    private const CSRF_KEY = 'hep_csrf';

    public function __construct(private readonly Connection $db) {}

    #[Route('/host/profile/edit', name: 'app_host_edit_profile', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate ---- */
        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* ---- Load the user row (same columns the editor touches) ---- */
        $dbUser = $this->db->fetchAssociative(
            'SELECT id, name, email, phone, age, location, avatar_path, is_host
               FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if (!$dbUser) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }

        /* ---- HOST GUARD — non-hosts go to become-a-host
           (legacy: header('Location: /webprogg/host/becomeahost.php')) ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* =========================================================
           CSRF TOKEN — generated once per session (legacy
           hep_csrf_token()); the form's hidden hep_csrf field
           carries it and the POST handler verifies it with
           hash_equals (legacy hep_csrf_ok()).
        ========================================================= */
        $csrfToken = (string) $session->get(self::CSRF_KEY);
        if ($csrfToken === '') {
            $csrfToken = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $csrfToken);
        }

        /* =========================================================
           BADGE COUNTS — navbar bell + sidebar badges (same
           queries as every host page; legacy ran them inline).
        ========================================================= */
        $notificationCount = 0;
        try {
            $notificationCount = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            );
        } catch (\Throwable $e) {
            $notificationCount = 0;
        }

        $pendingTenantsCount = $this->countPendingTenants($userId);

        /* =========================================================
           SAVE — CSRF + validation + UPDATE. Legacy re-renders
           the page with inline alerts (no redirect), so the port
           renders the same template with $errors / $saved.
        ========================================================= */
        $errors = [];
        $saved  = false;

        if ($request->isMethod('POST')) {

            $postedToken = (string) $request->request->get('hep_csrf', '');

            if (!is_string($postedToken) || $postedToken === '' || !hash_equals($csrfToken, $postedToken)) {

                $errors[] = 'Your session expired. Please try again.';
            } else {

                $name     = trim((string) $request->request->get('name', ''));
                $phone    = trim((string) $request->request->get('phone', ''));
                $age      = trim((string) $request->request->get('age', ''));
                $location = trim((string) $request->request->get('location', ''));

                if ($name === '') {
                    $errors[] = 'Full name is required.';
                }
                if ($phone === '') {
                    $errors[] = 'Phone number is required.';
                }
                if ($age === '') {
                    $errors[] = 'Age is required.';
                }
                if ($location === '') {
                    $errors[] = 'Location is required.';
                }
                if ($age !== '' && (!ctype_digit($age) || (int) $age < 18 || (int) $age > 120)) {
                    $errors[] = 'Age must be a number between 18 and 120.';
                }

                if (empty($errors)) {

                    try {
                        $this->db->update(
                            'users',
                            [
                                'name'     => $name,
                                'phone'    => $phone,
                                'age'      => (int) $age,
                                'location' => $location,
                            ],
                            ['id' => (int) $dbUser['id']]
                        );

                        /* Keep the session + shell data fresh (legacy:
                           $_SESSION['user_name'] / $_SESSION['avatar_path']) */
                        $session->set('user_name', $name);
                        $session->set('avatar_path', $dbUser['avatar_path'] ?? null);

                        $dbUser['name']     = $name;
                        $dbUser['phone']    = $phone;
                        $dbUser['age']      = $age;
                        $dbUser['location'] = $location;

                        $saved = true;
                    } catch (\Throwable $e) {
                        error_log('hosteditprofile: save failed: ' . $e->getMessage());
                        $errors[] = 'Could not save your changes. Please try again.';
                    }
                }
            }
        }

        /* =========================================================
           HOST DATA for the form (legacy $hostData) — avatar
           resolved through the shared resolver with the host
           avatar fallback contract.
        ========================================================= */
        $avatar = !empty($dbUser['avatar_path'])
            ? $this->resolvePhoto((string) $dbUser['avatar_path'], '/images/default-avatar.png')
            : '/images/default-avatar.png';

        $hostData = [
            'name'     => (string) $dbUser['name'],
            'email'    => (string) $dbUser['email'],
            'phone'    => (string) ($dbUser['phone'] ?? ''),
            'age'      => (string) ($dbUser['age'] ?? ''),
            'location' => (string) ($dbUser['location'] ?? ''),
            'avatar'   => $avatar,
        ];

        /* Real avatar vs temporary icon (same rule as the navbar) */
        $hasRealAvatar = trim($hostData['avatar']) !== ''
            && stripos($hostData['avatar'], 'default-avatar') === false;

        return $this->render('host/edit_profile.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => [
                'name'   => $hostData['name'],
                'avatar' => $hostData['avatar'],
                'email'  => $hostData['email'],
            ],
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'host_data'      => $hostData,
            'has_real_avatar'=> $hasRealAvatar,
            'errors'         => $errors,
            'saved'          => $saved,
            'csrf_token'     => $csrfToken,
        ]);
    }

    /* =====================================================================
     *  BADGE + PHOTO HELPERS — same implementations as
     *  HostProfileController (resolvePhoto copy).
     * ================================================================= */

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
        return '/uploads/' . $path;
    }
}
