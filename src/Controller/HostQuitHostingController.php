<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Quit Hosting.
 * 1:1 port of legacy host/quithosting.php.
 *
 *   GET|POST /host/quit-hosting   name app_host_quit_hosting
 *
 *   (URL + sidebar key 'quithosting' were pre-reserved by
 *    host/base.html.twig's sidebar — the page the sidebar's red
 *    "Quit Hosting" link has been pointing at all along.)
 *
 * Lets a host stop hosting: is_host flips back to 0, ALL their
 * listings are unlisted, and they land on the regular user
 * dashboard — still logged in. Account, bookings history, and
 * reviews are kept (legacy semantics, unchanged).
 *
 * THE TRANSACTION FIX (legacy "FIXED" note #1): the old unlist
 *   query used WHERE host_id = :id — listings has NO host_id
 *   column (it's user_id). The SQL threw AFTER is_host was
 *   already flipped, leaving a half-quit account with live
 *   listings. Legacy now uses user_id + wraps BOTH steps in a
 *   transaction so they succeed or roll back together. The port
 *   keeps the transaction (DBAL beginTransaction / commit /
 *   rollBack) and the user_id column.
 *
 * HOST GUARD (legacy "FIXED" note #2): quitting as a non-host
 *   was a silent no-op; legacy now bounces non-hosts. NOTE the
 *   different target from every other host page: non-hosts go
 *   to the USER PROFILE (/profile — legacy userprofile.php),
 *   NOT Become a Host — they are already a regular user, so
 *   "become a host again" is their own choice from the dashboard.
 *
 * VALIDATION LADDER (inline errors, NOT PRG — legacy re-renders
 *   the page with .qh-error, same pattern as the Edit Profile
 *   port; only SUCCESS redirects):
 *     CSRF fail          → "Your session expired. Please try again."
 *     password wrong     → "Password is incorrect."
 *       (password_verify against the users table; skipped when
 *        the CSRF already failed — legacy if (empty($errors)))
 *     confirm word ≠ QUIT → "Type QUIT to confirm."
 *       (strtoupper(trim(...)) — case-insensitive, ALWAYS checked,
 *        even when the password already failed — legacy runs it
 *        unconditionally, so both errors can show together)
 *     both clean → TRANSACTION:
 *       1. UPDATE users SET is_host = 0 WHERE id = :id
 *       2. UPDATE listings SET status = 'unlisted' WHERE user_id = :id
 *       commit → unset($_SESSION['is_host']) →
 *       redirect /profile?quit_hosting=1 (legacy
 *       /webprogg/user/userprofile.php?quit_hosting=1 — the ported
 *       profile page is /profile; it does not consume the flag yet,
 *       same policy as stored legacy links awaiting their page port)
 *     PDO failure → rollBack (if inTransaction) →
 *       "Something went wrong. Please try again." +
 *       error_log('quithosting failed: ...') — verbatim.
 *
 * CSRF: legacy self-contained qh_csrf_* helpers (host_init.php
 *   does NOT load functions.php). The port keeps the dedicated
 *   session key 'qh_csrf' — generated once, carried in the form's
 *   hidden qh_csrf field, verified with hash_equals. Same
 *   per-session pattern as HostPayoutsController's pay_csrf,
 *   HostEditProfileController's hep_csrf,
 *   HostNotificationSettingsController's hn_csrf and
 *   HostSecurityController's hs_csrf.
 *
 * SHELL (legacy "SHELL FIX"): three broken shell pieces repaired
 *   in legacy — shared navbar + notification dropdown, the REAL
 *   /host/host_sidebar.php (the old /includes/hostsidebar.php
 *   path silently rendered NO sidebar through an is_file guard),
 *   and a footer. The ported shell (host/base.html.twig) ships
 *   all three: shared navbar with bell + dropdown, collapsible
 *   host sidebar (active_nav = 'quithosting' — the red link),
 *   and the footer come for free.
 *
 * LAYOUT NOTE: legacy wrapped the card in its own .qh-layout
 *   (flex + sidebar + margin 130px) because the page predated
 *   the shell standardization; its CSS FIX note says the page
 *   now matches every other host page. The port drops the
 *   .qh-layout wrapper (the shell's .host-layout replaces it)
 *   and keeps the .qh-card + inner qh-* CSS VERBATIM — the
 *   card, fields, error box, red button and cancel link render
 *   exactly like legacy.
 *
 * ICONS: this page has none (no lucide slots in legacy — no
 *   data-lucide anywhere). The port carries no icons either;
 *   no macro import is needed.
 *
 * CSS NOTE: qh-* styles were self-contained in the legacy page
 *   (its own <style> block) — redeclared page-locally with the
 *   same values, plus dark-mode overrides consistent with the
 *   shell (legacy had none for the card).
 *
 * Auth: logged_in + user_id (else app_login); non-host →
 *   /profile (legacy userprofile.php).
 */
class HostQuitHostingController extends AbstractController
{
    /** Session key for the quit-hosting CSRF token (legacy qh_csrf). */
    private const CSRF_KEY = 'qh_csrf';

    public function __construct(private readonly Connection $db) {}

    #[Route('/host/quit-hosting', name: 'app_host_quit_hosting', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostSecurityController) ---- */
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

        /* ---- HOST GUARD — quitting as a non-host is meaningless.
           NOTE: non-hosts go to the USER PROFILE (legacy
           userprofile.php), NOT Become a Host — they are already a
           regular user. ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/profile');
        }

        /* =========================================================
           CSRF TOKEN — generated once per session (legacy
           qh_csrf_token()); verified on POST below.
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
           HANDLE QUIT (POST) — inline errors, NOT PRG: legacy
           re-renders the page with .qh-error (same pattern as the
           Edit Profile port). Only SUCCESS redirects.
        ========================================================= */
        $errors = [];

        if ($request->isMethod('POST')) {

            $postedToken = (string) $request->request->get('qh_csrf', '');
            $password    = (string) $request->request->get('quit_password', '');
            $confirmWord = strtoupper(trim((string) $request->request->get('confirm_word', '')));

            if (!is_string($postedToken) || $postedToken === '' || !hash_equals($csrfToken, $postedToken)) {

                $errors[] = 'Your session expired. Please try again.';
            }

            /* Verify password against the users table
               (skipped when the CSRF already failed — legacy) */
            if (empty($errors)) {
                $hash = $this->db->fetchOne(
                    'SELECT password FROM users WHERE id = :id LIMIT 1',
                    ['id' => $userId]
                );

                if (!$hash || !password_verify($password, (string) $hash)) {
                    $errors[] = 'Password is incorrect.';
                }
            }

            /* ALWAYS checked — even when the password already
               failed (legacy runs it unconditionally) */
            if ($confirmWord !== 'QUIT') {
                $errors[] = 'Type QUIT to confirm.';
            }

            if (empty($errors)) {
                try {
                    $this->db->beginTransaction();

                    /* 1. Flip the account back to a regular user */
                    $this->db->executeStatement(
                        'UPDATE users SET is_host = 0 WHERE id = :id',
                        ['id' => $userId]
                    );

                    /* 2. Unlist all their listings so tenants can't
                       book them. (The real column is user_id, not
                       host_id — legacy FIXED note #1.) */
                    $this->db->executeStatement(
                        "UPDATE listings SET status = 'unlisted' WHERE user_id = :id",
                        ['id' => $userId]
                    );

                    $this->db->commit();

                    /* 3. Clear host session flag, then send them to
                       the regular-user dashboard — still logged in.
                       (Legacy: unset($_SESSION['is_host']); redirect
                       userprofile.php?quit_hosting=1. The ported
                       profile page is /profile.) */
                    $session->remove('is_host');

                    return $this->redirect('/profile?quit_hosting=1');
                } catch (\Throwable $e) {
                    if ($this->db->isTransactionActive()) {
                        $this->db->rollBack();
                    }
                    $errors[] = 'Something went wrong. Please try again.';
                    error_log('quithosting failed: ' . $e->getMessage());
                }
            }
        }

        return $this->render('host/quit_hosting.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'errors'    => $errors,
            'qh_csrf'   => $csrfToken,
        ]);
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostSecurityController /
     *  HostPayoutsController / HostNotificationSettingsController
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
     *  HostNotificationSettingsController / HostSecurityController.
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
