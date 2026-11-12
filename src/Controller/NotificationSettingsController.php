<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — My Account › Notification Settings.
 * 1:1 port of legacy user/usernotificationsettings.php.
 *
 *   GET|POST /settings/notifications   → six toggles: which events send
 *     an email, an in-app bell notification, or both. Persisted to the
 *     notification_settings table (one row per user, created lazily).
 *
 * Pref keys (column, default):
 *   email_bookings 1 · email_messages 1 · email_promotions 0
 *   push_bookings  1 · push_messages  1 · push_promotions  0
 *
 * Port notes:
 *   - CSRF key 'notif_settings' (per-key session token pair).
 *   - SELF-HEAL: the notification_settings table is created on first
 *     run (legacy told you to run the CREATE TABLE by hand — now it
 *     just happens, same pattern as the wallet port).
 *   - Sidebar key is 'notifications' (matching sidebar.php's map, the
 *     exact bug the legacy version fixed — notifcenter is the bell page).
 */
class NotificationSettingsController extends AbstractController
{
    private const CSRF_KEY = 'notif_settings';

    /** key => [column, default] */
    private const PREF_KEYS = [
        'email_bookings'   => ['email_bookings', 1],
        'email_messages'   => ['email_messages', 1],
        'email_promotions' => ['email_promotions', 0],
        'push_bookings'    => ['push_bookings', 1],
        'push_messages'    => ['push_messages', 1],
        'push_promotions'  => ['push_promotions', 0],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/settings/notifications', name: 'app_notification_settings', methods: ['GET', 'POST'], priority: 2)]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* ---- user row ---- */
        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if (!$user) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        $user['id'] = (int)$user['id'];

        if ((int)$user['is_host'] === 1) {
            return new RedirectResponse('/host/profile');
        }

        /* =========================================================
           SELF-HEAL — notification_settings table
        ========================================================== */
        try {
            $this->db->executeStatement("
                CREATE TABLE IF NOT EXISTS notification_settings (
                    user_id INT NOT NULL PRIMARY KEY,
                    email_bookings TINYINT(1) NOT NULL DEFAULT 1,
                    email_messages TINYINT(1) NOT NULL DEFAULT 1,
                    email_promotions TINYINT(1) NOT NULL DEFAULT 0,
                    push_bookings TINYINT(1) NOT NULL DEFAULT 1,
                    push_messages TINYINT(1) NOT NULL DEFAULT 1,
                    push_promotions TINYINT(1) NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NULL DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            error_log('notification settings: table self-heal failed: ' . $e->getMessage());
        }

        /* -----------------------------------------------------
           LOAD CURRENT PREFERENCES
        ----------------------------------------------------- */
        $prefs = [];
        foreach (self::PREF_KEYS as $key => [$column, $default]) {
            $prefs[$key] = $default;
        }

        try {
            $prefsRow = $this->db->fetchAssociative(
                'SELECT * FROM notification_settings WHERE user_id = :id LIMIT 1',
                ['id' => $userId]
            );
            if ($prefsRow) {
                foreach (self::PREF_KEYS as $key => [$column, $default]) {
                    $prefs[$key] = isset($prefsRow[$column]) ? (bool)$prefsRow[$column] : $default;
                }
            }
        } catch (\Throwable $e) {
            error_log('notification settings: load failed: ' . $e->getMessage());
        }

        /* -----------------------------------------------------
           SAVE — one UPSERT keyed on user_id
        ----------------------------------------------------- */
        $saved     = false;
        $saveError = '';

        if ($request->isMethod('POST')) {
            if (!$this->csrfValid($session, self::CSRF_KEY, $this->postedToken($request))) {
                $saveError = 'Your session expired. Please try again.';
            } else {
                try {
                    $this->db->executeStatement(
                        "INSERT INTO notification_settings
                            (user_id, email_bookings, email_messages, email_promotions,
                             push_bookings, push_messages, push_promotions, updated_at)
                         VALUES
                            (:user_id, :email_bookings, :email_messages, :email_promotions,
                             :push_bookings, :push_messages, :push_promotions, NOW())
                         ON DUPLICATE KEY UPDATE
                            email_bookings   = VALUES(email_bookings),
                            email_messages   = VALUES(email_messages),
                            email_promotions = VALUES(email_promotions),
                            push_bookings    = VALUES(push_bookings),
                            push_messages    = VALUES(push_messages),
                            push_promotions  = VALUES(push_promotions),
                            updated_at       = NOW()",
                        [
                            'user_id'          => $userId,
                            'email_bookings'   => $request->request->getBoolean('email_bookings') ? 1 : 0,
                            'email_messages'   => $request->request->getBoolean('email_messages') ? 1 : 0,
                            'email_promotions' => $request->request->getBoolean('email_promotions') ? 1 : 0,
                            'push_bookings'    => $request->request->getBoolean('push_bookings') ? 1 : 0,
                            'push_messages'    => $request->request->getBoolean('push_messages') ? 1 : 0,
                            'push_promotions'  => $request->request->getBoolean('push_promotions') ? 1 : 0,
                        ]
                    );

                    foreach (self::PREF_KEYS as $key => [$column, $default]) {
                        $prefs[$key] = $request->request->getBoolean($key);
                    }

                    $saved = true;
                } catch (\Throwable $e) {
                    $saveError = 'Something went wrong saving your preferences. Please try again.';
                    error_log('notification settings save failed: ' . $e->getMessage());
                }
            }
        }

        return $this->render('user/notification_settings.html.twig', [
            'user'         => $user,
            'prefs'        => $prefs,
            'saved'        => $saved,
            'save_error'   => $saveError,
            'notif_count'  => $this->notifCount($userId),
            'csrf_notifs'  => $this->ensureCsrfToken($session, self::CSRF_KEY),
        ]);
    }

    /* =====================================================================
     |  HELPERS — same implementations as WalletController (kept identical)
     | ================================================================== */

    private function ensureCsrfToken(SessionInterface $session, string $key): string
    {
        $tokens = $session->get('_rh_csrf_tokens', []);
        if (!isset($tokens[$key])) {
            $tokens[$key] = bin2hex(random_bytes(16));
            $session->set('_rh_csrf_tokens', $tokens);
        }
        return (string)$tokens[$key];
    }

    private function csrfValid(SessionInterface $session, string $key, ?string $token): bool
    {
        $tokens = $session->get('_rh_csrf_tokens', []);
        return isset($tokens[$key]) && is_string($token) && hash_equals((string)$tokens[$key], $token);
    }

    /** Accepts both csrf_token and _token form field names. */
    private function postedToken(Request $request): ?string
    {
        $t = $request->request->get('csrf_token');
        if (!is_string($t) || $t === '') {
            $t = $request->request->get('_token');
        }
        return is_string($t) ? $t : null;
    }

    private function notifCount(int $userId): int
    {
        try {
            return (int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0',
                ['id' => $userId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}