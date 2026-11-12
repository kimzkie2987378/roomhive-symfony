<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Security.
 * 1:1 port of legacy host/hostsecurity.php.
 *
 *   GET|POST /host/security   name app_host_security
 *
 *   (URL + sidebar key 'security' were pre-reserved by
 *    host/base.html.twig's sidebar — the page the sidebar's
 *    "Security" link has been pointing at all along.)
 *
 * Two POST handlers, both CSRF-guarded, both PRG:
 *
 *   • form change_password (name change_password)
 *     validation ladder verbatim from legacy:
 *       CSRF fail → "Your session expired. Please try again."
 *       any field empty → "Please fill in all password fields."
 *       current wrong (password_verify) → "Your current password is incorrect."
 *       new < 8 chars → "New password must be at least 8 characters."
 *       new ≠ confirm → "New password and confirmation do not match."
 *       new === current (password_verify against storedHash)
 *         → "New password must be different from your current one."
 *       else UPDATE users SET password = password_hash($new,
 *         PASSWORD_DEFAULT) → "Password changed successfully."
 *
 *   • form toggle_2fa (name toggle_2fa)
 *     flips users.two_factor_enabled 0/1 and flashes the matching
 *     message ("...enabled for your account." / "...disabled.").
 *     Login enforcement is a separate task (legacy comment kept).
 *
 * SHELL (legacy "SHELL FIX"): host_navbar.php / host_footer.php
 *   were retired with the shell migration (legacy re-added
 *   notification_dropdown.php manually after includes/navbar.php).
 *   The ported shared navbar (partials/navbar.html.twig, included
 *   by host/base.html.twig) already ships the bell + notification
 *   dropdown, so there is no separate dropdown include here —
 *   same as every other ported host page. Sidebar + footer come
 *   from host/base.html.twig.
 *
 * HOST GUARD (legacy "GUARD FIX" — was missing): non-hosts go to
 *   Become a Host (legacy: header('Location: /webprogg/host/
 *   becomeahost.php'); the port redirects to /host/become-a-host,
 *   same as HostEditProfileController / HostPayoutsController /
 *   HostNotificationSettingsController).
 *
 * CSRF (legacy "CSRF FIX"): BOTH POST handlers were untokenized —
 *   a forged cross-site request could change the host's PASSWORD.
 *   Legacy added self-contained hs_csrf_* helpers (host_init.php
 *   does NOT load functions.php). The port keeps the dedicated
 *   session key 'hs_csrf' — generated once, carried in both
 *   forms' hidden hs_csrf field, verified with hash_equals.
 *   Same per-session pattern as HostPayoutsController's pay_csrf,
 *   HostEditProfileController's hep_csrf and
 *   HostNotificationSettingsController's hn_csrf.
 *
 * PRG + FLASH: legacy flashes through hp_flash_set() and
 *   hardcoded the redirect (legacy note: was $_SERVER['PHP_SELF']
 *   → fixed path). The port keeps PRG with the shared session
 *   flash 'rh_flash' = ['type','message'], consumed once on the
 *   following GET (the pattern HostBookingsController established
 *   for hp_flash_set/take).
 *
 * SELF-HEAL: legacy assumed users.two_factor_enabled exists; the
 *   renter-side SecurityController port already self-heals this
 *   column. The port runs the same ALTER on the host side so the
 *   page does not fatal on a fresh DB (same defensive pattern as
 *   the wallet + renter security ports).
 *
 * ACTIVE SESSIONS card: legacy rendered a static row ("This device
 *   — current session") + a LOG OUT link. The port keeps the same
 *   markup verbatim; the LOG OUT link points at /logout, the same
 *   URL the host sidebar's Log Out uses.
 *
 * ICONS: legacy hostsecurity.php has NO PNG/emoji icon slots
 *   (legacy comment "None needed — this page has no PNG/emoji
 *   icon slots"). The port carries no icons either — the flash
 *   banner is text-only, exactly like legacy.
 *
 * CSS NOTE: hp-page-header / hp-flash / hp-card / hp-card-header
 *   / hp-soon-badge / hp-form-grid / hp-field / hp-input /
 *   hp-form-actions / hp-btn-primary / hp-btn-outline /
 *   hp-setting-row / hp-session-row / hp-session-dot /
 *   hp-btn-danger-outline came from legacy hostprofile.css, which
 *   the Symfony shell does not ship — redeclared page-locally with
 *   the same values the other ported host pages standardized on
 *   (same approach as host/payouts.html.twig +
 *   host/notification_settings.html.twig).
 *
 * Auth: logged_in + user_id (else app_login); non-host →
 *   /host/become-a-host (legacy becomeahost.php).
 */
class HostSecurityController extends AbstractController
{
    /** Session key for the security CSRF token (legacy hs_csrf). */
    private const CSRF_KEY = 'hs_csrf';

    public function __construct(private readonly Connection $db) {}

    #[Route('/host/security', name: 'app_host_security', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostPayoutsController) ---- */
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

        /* ---- HOST GUARD — non-hosts go to Become a Host
           (legacy: header('Location: /webprogg/host/becomeahost.php')) ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* =========================================================
           SELF-HEAL — two_factor_enabled (legacy assumed it exists;
           renter-side SecurityController self-heals it too).
        ========================================================= */
        try {
            if ($this->db->fetchOne("SHOW COLUMNS FROM users LIKE 'two_factor_enabled'") === false) {
                $this->db->executeStatement(
                    'ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0'
                );
            }
        } catch (\Throwable $e) {
            /* Best-effort — the load below will surface a real error */
        }

        /* =========================================================
           CSRF TOKEN — generated once per session (legacy
           hs_csrf_token()); verified on BOTH POST handlers below.
        ========================================================= */
        $csrfToken = (string) $session->get(self::CSRF_KEY);
        if ($csrfToken === '') {
            $csrfToken = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $csrfToken);
        }

        /* =========================================================
           BADGE COUNTS — navbar bell + sidebar badges. Legacy
           computed the unread count ONCE and shared it with the
           sidebar ($hostNotifUnread = $notification_count); the
           port keeps that single-query contract (the shell reuses
           notification_count for both bell and sidebar badge).
        ========================================================= */
        $notificationCount = $this->countUnreadNotifications($userId);

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
           FETCH password hash + real 2FA flag (legacy $secRow).
        ========================================================= */
        $secRow = $this->db->fetchAssociative(
            'SELECT password, two_factor_enabled FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        $storedHash       = (string) ($secRow['password'] ?? '');
        $twoFactorEnabled = (int) ($secRow['two_factor_enabled'] ?? 0);

        /* =========================================================
           TOGGLE 2FA (POST toggle_2fa) — CSRF-verified, flip the
           flag, flash the matching message, PRG back. Login
           enforcement is a separate task (legacy comment kept).
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('toggle_2fa')) {

            $postedToken = (string) $request->request->get('hs_csrf', '');

            if (!is_string($postedToken) || $postedToken === '' || !hash_equals($csrfToken, $postedToken)) {

                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'Your session expired. Please try again.',
                ]);
            } else {
                $new = $twoFactorEnabled ? 0 : 1;
                try {
                    $this->db->update('users', ['two_factor_enabled' => $new], ['id' => $userId]);
                    $twoFactorEnabled = $new;
                    $session->set('rh_flash', [
                        'type'    => 'success',
                        'message' => $new
                            ? 'Two-factor authentication enabled for your account.'
                            : 'Two-factor authentication disabled.',
                    ]);
                } catch (\Throwable $e) {
                    $session->set('rh_flash', [
                        'type'    => 'error',
                        'message' => 'Could not update your two-factor setting.',
                    ]);
                }
            }

            return $this->redirectToRoute('app_host_security');
        }

        /* =========================================================
           CHANGE PASSWORD (POST change_password) — CSRF + the
           full legacy validation ladder (any empty → password_verify
           current → strlen 8 → match → not same as current).
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('change_password')) {

            $postedToken = (string) $request->request->get('hs_csrf', '');
            $current     = (string) $request->request->get('current_password', '');
            $new         = (string) $request->request->get('new_password', '');
            $confirm     = (string) $request->request->get('confirm_password', '');

            if (!is_string($postedToken) || $postedToken === '' || !hash_equals($csrfToken, $postedToken)) {

                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'Your session expired. Please try again.',
                ]);
            } elseif ($current === '' || $new === '' || $confirm === '') {
                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'Please fill in all password fields.',
                ]);
            } elseif (!password_verify($current, $storedHash)) {
                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'Your current password is incorrect.',
                ]);
            } elseif (strlen($new) < 8) {
                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'New password must be at least 8 characters.',
                ]);
            } elseif ($new !== $confirm) {
                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'New password and confirmation do not match.',
                ]);
            } elseif (password_verify($new, $storedHash)) {
                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'New password must be different from your current one.',
                ]);
            } else {
                try {
                    $this->db->update(
                        'users',
                        ['password' => password_hash($new, PASSWORD_DEFAULT)],
                        ['id' => $userId]
                    );
                    $session->set('rh_flash', [
                        'type'    => 'success',
                        'message' => 'Password changed successfully.',
                    ]);
                } catch (\Throwable $e) {
                    error_log('hostsecurity: password update failed: ' . $e->getMessage());
                    $session->set('rh_flash', [
                        'type'    => 'error',
                        'message' => 'Could not update your password. Please try again.',
                    ]);
                }
            }

            return $this->redirectToRoute('app_host_security');
        }

        /* =========================================================
           SESSION FLASH (rh_flash) — consumed once, then cleared
           (legacy $flash = hp_flash_take()).
        ========================================================= */
        $sessionFlash = null;
        $sf = $session->get('rh_flash');
        if (is_array($sf) && !empty($sf['message'])) {
            $sessionFlash = [
                'type'    => (string) ($sf['type'] ?? 'success'),
                'message' => (string) $sf['message'],
            ];
            $session->remove('rh_flash');
        }

        return $this->render('host/security.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'two_factor_enabled' => $twoFactorEnabled,
            'hs_csrf'            => $csrfToken,
            'session_flash'      => $sessionFlash,
        ]);
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostBookingsController
     *  / HostPayoutsController / HostNotificationSettingsController
     *  (best-effort: DB hiccups count 0).
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
     *  PHOTO PATH RESOLVER — same as HostProfileController /
     *  HostEditProfileController / HostPayoutsController /
     *  HostNotificationSettingsController.
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
