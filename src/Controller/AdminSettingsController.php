<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Settings.
 * 1:1 port of legacy admin/adminsettings.php.
 *
 *   GET|POST /admin/settings   → platform-wide configuration
 *     (site name, support email, support phone, maintenance mode)
 *     + admin account info panel.
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * Settings are stored as key/value rows in the `platform_settings`
 *   table (INSERT ... ON DUPLICATE KEY UPDATE). Defaults are merged
 *   over the DB values so the form always has a value to show even
 *   when the table is empty or a key is missing.
 *
 * PAYOUTS-TABLE-STYLE GUARD: if `platform_settings` does not exist,
 *   the platform-settings panel renders an empty state ("run the
 *   CREATE TABLE SQL first"). Same pattern as AdminPayoutsController
 *   uses for the missing `payouts` table.
 *
 * Validation on POST (mirrors legacy exactly):
 *   - CSRF token verified with hash_equals
 *   - site_name cannot be empty
 *   - support_email must pass filter_var FILTER_VALIDATE_EMAIL
 *   - support_phone has no validation (legacy kept it optional)
 *   - maintenance_mode is a checkbox → '1' if set, '0' otherwise
 *
 * Admin account panel on the right shows the admin's name, email,
 *   role, and a Log Out button → /admin/logout.
 */
class AdminSettingsController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    /** Default settings — merged over DB values so the form is never blank. */
    private const DEFAULTS = [
        'site_name'        => 'RoomHive',
        'support_email'    => 'hello@roomhive.ph',
        'support_phone'    => '0927 569 3574',
        'maintenance_mode' => '0',
    ];

    /** Setting keys the form is allowed to write (whitelist). */
    private const SETTING_KEYS = ['site_name', 'support_email', 'support_phone', 'maintenance_mode'];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/settings', name: 'app_admin_settings', methods: ['GET', 'POST'])]
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
           LOAD SETTINGS (try/catch — table may not exist yet)
           Defaults are merged OVER the DB values so any missing
           key still has a value to display in the form.
        ========================================================= */
        $settings           = self::DEFAULTS;
        $settingsTableExists = true;
        try {
            $rows = $this->db->fetchAllKeyValue(
                'SELECT setting_key, setting_value FROM platform_settings'
            );
            foreach ($rows as $k => $v) {
                $settings[$k] = (string) $v;
            }
        } catch (\Throwable $e) {
            $settingsTableExists = false;
            error_log('adminsettings: load failed: ' . $e->getMessage());
        }

        /* =========================================================
           HANDLE SAVE (POST)
           CSRF-verified; validates site_name + support_email;
           writes via INSERT ... ON DUPLICATE KEY UPDATE so the
           first save creates the row and later saves update it.
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('save_settings')) {
            $postedToken = (string) $request->request->get('csrf_token', '');

            if (!hash_equals($adminCsrf, $postedToken)) {
                $this->addFlash('admin_settings_flash', [
                    'type' => 'error',
                    'text' => 'That request could not be verified.',
                ]);
            } elseif (!$settingsTableExists) {
                $this->addFlash('admin_settings_flash', [
                    'type' => 'error',
                    'text' => 'platform_settings table missing — run the CREATE TABLE SQL first.',
                ]);
            } else {
                $siteName = trim((string) $request->request->get('site_name', ''));
                $supEmail = trim((string) $request->request->get('support_email', ''));
                $supPhone = trim((string) $request->request->get('support_phone', ''));
                $maint    = $request->request->has('maintenance_mode') ? '1' : '0';

                if ($siteName === '') {
                    $this->addFlash('admin_settings_flash', [
                        'type' => 'error',
                        'text' => 'Site name cannot be empty.',
                    ]);
                } elseif (!filter_var($supEmail, FILTER_VALIDATE_EMAIL)) {
                    $this->addFlash('admin_settings_flash', [
                        'type' => 'error',
                        'text' => 'Support email must be a valid email address.',
                    ]);
                } else {
                    try {
                        $up = 'INSERT INTO platform_settings (setting_key, setting_value)
                               VALUES (:k, :v)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';

                        $this->db->executeStatement($up, ['k' => 'site_name',        'v' => $siteName]);
                        $this->db->executeStatement($up, ['k' => 'support_email',    'v' => $supEmail]);
                        $this->db->executeStatement($up, ['k' => 'support_phone',    'v' => $supPhone]);
                        $this->db->executeStatement($up, ['k' => 'maintenance_mode', 'v' => $maint]);

                        /* Reflect the saved values in the form below
                           (legacy re-read from DB — same effect here). */
                        $settings['site_name']        = $siteName;
                        $settings['support_email']    = $supEmail;
                        $settings['support_phone']    = $supPhone;
                        $settings['maintenance_mode'] = $maint;

                        $this->addFlash('admin_settings_flash', [
                            'type' => 'success',
                            'text' => 'Settings saved.',
                        ]);
                    } catch (\Throwable $e) {
                        error_log('adminsettings: save failed: ' . $e->getMessage());
                        $this->addFlash('admin_settings_flash', [
                            'type' => 'error',
                            'text' => 'Could not save settings.',
                        ]);
                    }
                }
            }

            return $this->redirectToRoute('app_admin_settings');
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* ---- Flash from the session (after redirect) ---- */
        $flash = null;
        $flashBag = $request->getSession()->getFlashBag()->get('admin_settings_flash');
        if (!empty($flashBag)) {
            $flash = $flashBag[0];
        }

        return $this->render('admin/settings.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'settings'             => $settings,
            'settings_table_exists'=> $settingsTableExists,
            'setting_keys'         => self::SETTING_KEYS,

            'flash'                => $flash,
        ]);
    }

    /* =====================================================================
     *  NOTIFICATION COUNT — same helper used by every admin page so the
     *  bell badge stays consistent. Pending host applications + pending
     *  listings.
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
}
