<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Notification Settings.
 * 1:1 port of legacy host/hostnotificationsettings.php.
 *
 *   GET|POST /host/notification-settings
 *   name app_host_notification_settings
 *
 *   (URL + sidebar key 'notificationsettings' were pre-reserved by
 *    host/base.html.twig's sidebar — the page the sidebar's
 *    "Notification Settings" link has been pointing at all along.)
 *
 * The 6 REAL columns of the notification_preferences table
 * (4 email + 2 push toggles), default-ON when no row exists yet,
 * UPSERT on save — all unchanged from legacy.
 *
 * SHELL (legacy "SHHELL FIX" note): the retired host_navbar.php
 *   used to auto-include notification_dropdown.php; legacy re-added
 *   it manually after includes/navbar.php. The ported shared navbar
 *   (partials/navbar.html.twig, included by host/base.html.twig)
 *   already ships the bell + notification dropdown, so there is no
 *   separate dropdown include here — same as every other ported
 *   host page. Sidebar + footer come from host/base.html.twig.
 *
 * HOST GUARD (legacy "GUARD FIX"): the page had no host guard —
 *   non-hosts could reach it. Non-hosts now go to Become a Host
 *   (legacy: header('Location: /webprogg/host/becomeahost.php');
 *   the port redirects to /host/become-a-host, same as
 *   HostEditProfileController / HostPayoutsController).
 *
 * CSRF (legacy "CSRF FIX"): the save POST was untokenized; legacy
 *   added self-contained hn_csrf_* helpers (host_init.php does NOT
 *   load functions.php). The port keeps the dedicated session key
 *   'hn_csrf' — generated once, carried in the form's hidden
 *   hn_csrf field, verified with hash_equals. Same per-session
 *   pattern as HostPayoutsController's pay_csrf and
 *   HostEditProfileController's hep_csrf.
 *
 * PRG + FLASH: legacy flashes through hp_flash_set() and hardcodes
 *   the redirect back to the page URL. The port keeps PRG with the
 *   shared session flash 'rh_flash' = ['type','message'], consumed
 *   once on the following GET (the pattern HostBookingsController
 *   established for hp_flash_set/take). Messages verbatim:
 *     - "Your session expired. Please try again."  (CSRF fail)
 *     - "Notification preferences saved."          (success)
 *     - "Could not save your preferences."         (PDOException)
 *   (Legacy's catch is silent — no error_log — kept as-is.)
 *
 * KEPT (legacy notes):
 *   - Flash message carries its Lucide icon (circle-check on
 *     success / circle-alert on error) — rendered through the
 *     macro + the page-local .hp-flash svg sizing rule, exactly
 *     like the legacy <style> block.
 *   - $activePage = 'notificationsettings' (the sidebar's
 *     settings-group key) → active_nav in Twig.
 *   - Unread count computed ONCE and shared with the sidebar
 *     (legacy $hostNotifUnread = $notification_count): the port
 *     passes notification_count once and the shell reuses it for
 *     the navbar bell AND the sidebar badge — no extra query.
 *   - pending_tenants_count computed for the sidebar badge.
 *   - The 6-column schema, UPSERT logic (SELECT-then-UPDATE/INSERT
 *     with updated_at = NOW() on the UPDATE branch) and the
 *     default-ON loading are unchanged.
 *
 * ICON SUBSTITUTIONS (macro keys, admin/_macros.html.twig):
 *     legacy `circle-check` → macro 'check-circle'   (same glyph,
 *                             older name — same substitution the
 *                             Edit Profile port uses)
 *     legacy `circle-alert` → macro 'alert-triangle' (not in the
 *                             macro table; closest existing alert
 *                             glyph — same "!" attention beat)
 *   No other icons on this page.
 *
 * CSS NOTE: hp-page-header / hp-flash / hp-setting-row /
 *   hp-switch / hp-slider / hp-form-actions / hp-btn-primary came
 *   from legacy hostprofile.css, which the Symfony shell does not
 *   ship — redeclared page-locally with the same values the other
 *   ported host pages standardized on (same approach as
 *   host/payouts.html.twig).
 *
 * Auth: logged_in + user_id (else app_login); non-host →
 *   /host/become-a-host (legacy becomeahost.php).
 */
class HostNotificationSettingsController extends AbstractController
{
    /** Session key for the settings CSRF token (legacy hn_csrf). */
    private const CSRF_KEY = 'hn_csrf';

    public function __construct(private readonly Connection $db) {}

    #[Route('/host/notification-settings', name: 'app_host_notification_settings', methods: ['GET', 'POST'])]
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
           CSRF TOKEN — generated once per session (legacy
           hn_csrf_token()); verified on POST below.
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
           THE 6 REAL COLUMNS of notification_preferences
           [key => [row title, row description]] — verbatim legacy
           $prefKeys; the template loops this map (same as legacy).
        ========================================================= */
        $prefKeys = [
            'email_booking_updates' => ['Email · Booking updates', 'Requests, confirmations, and changes for your listings.'],
            'email_messages'        => ['Email · New messages', 'When a tenant sends you a message.'],
            'email_promos'          => ['Email · Promotions', 'Platform-wide promos and offers.'],
            'email_reviews'         => ['Email · Reviews', 'When a tenant leaves a review on your listing.'],
            'push_booking_updates'  => ['Push · Booking updates', 'Real-time booking alerts on this browser.'],
            'push_messages'         => ['Push · New messages', 'Real-time message alerts on this browser.'],
        ];

        /* =========================================================
           SAVE (POST save_prefs) — CSRF-verified, then every toggle
           is presence-mapped 1/0 (unchecked checkboxes don't post),
           then SELECT-then-UPDATE/INSERT UPSERT. Every path flashes
           and PRG-redirects back to this page (legacy hardcoded the
           page URL — already fixed there; the port uses the route).
           A POST without save_prefs falls through and renders the
           page, same as legacy.
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('save_prefs')) {

            $postedToken = (string) $request->request->get('hn_csrf', '');

            if (!is_string($postedToken) || $postedToken === '' || !hash_equals($csrfToken, $postedToken)) {

                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'Your session expired. Please try again.',
                ]);
            } else {

                $values = [];
                foreach ($prefKeys as $key => $meta) {
                    $values[$key] = $request->request->has($key) ? 1 : 0;
                }

                try {
                    $existing = $this->db->fetchAssociative(
                        'SELECT user_id FROM notification_preferences WHERE user_id = :u LIMIT 1',
                        ['u' => $userId]
                    );

                    if ($existing) {
                        $this->db->executeStatement(
                            "UPDATE notification_preferences
                             SET email_booking_updates = :a, email_messages = :b, email_promos = :c,
                                 email_reviews = :d, push_booking_updates = :e, push_messages = :f,
                                 updated_at = NOW()
                             WHERE user_id = :u",
                            [
                                'u' => $userId,
                                'a' => $values['email_booking_updates'],
                                'b' => $values['email_messages'],
                                'c' => $values['email_promos'],
                                'd' => $values['email_reviews'],
                                'e' => $values['push_booking_updates'],
                                'f' => $values['push_messages'],
                            ]
                        );
                    } else {
                        $this->db->executeStatement(
                            "INSERT INTO notification_preferences
                                (user_id, email_booking_updates, email_messages, email_promos,
                                 email_reviews, push_booking_updates, push_messages)
                             VALUES (:u, :a, :b, :c, :d, :e, :f)",
                            [
                                'u' => $userId,
                                'a' => $values['email_booking_updates'],
                                'b' => $values['email_messages'],
                                'c' => $values['email_promos'],
                                'd' => $values['email_reviews'],
                                'e' => $values['push_booking_updates'],
                                'f' => $values['push_messages'],
                            ]
                        );
                    }

                    $session->set('rh_flash', [
                        'type'    => 'success',
                        'message' => 'Notification preferences saved.',
                    ]);
                } catch (\Throwable $e) {
                    /* Legacy catch is silent (no error_log) — kept as-is */
                    $session->set('rh_flash', [
                        'type'    => 'error',
                        'message' => 'Could not save your preferences.',
                    ]);
                }
            }

            /* PRG — back to this page (legacy hardcoded the page URL) */
            return $this->redirectToRoute('app_host_notification_settings');
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

        /* =========================================================
           LOAD CURRENT VALUES — default ON if no row yet; existing
           columns overlay the defaults (legacy $prefs).
        ========================================================= */
        $prefs = array_fill_keys(array_keys($prefKeys), 1);
        try {
            $row = $this->db->fetchAssociative(
                'SELECT * FROM notification_preferences WHERE user_id = :u LIMIT 1',
                ['u' => $userId]
            );
            if ($row) {
                foreach ($prefKeys as $key => $meta) {
                    if (array_key_exists($key, $row)) {
                        $prefs[$key] = (int) $row[$key];
                    }
                }
            }
        } catch (\Throwable $e) { /* defaults apply */ }

        return $this->render('host/notification_settings.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'prefs'         => $prefs,
            'prefs_meta'    => $prefKeys,
            'hn_csrf'       => $csrfToken,
            'session_flash' => $sessionFlash,
        ]);
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostBookingsController
     *  / HostPayoutsController (best-effort: DB hiccups count 0).
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
     *  HostEditProfileController / HostPayoutsController.
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
