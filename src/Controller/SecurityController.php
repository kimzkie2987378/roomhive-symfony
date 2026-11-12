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
 * RoomHive — My Account › Settings (Security & preferences).
 * 1:1 port of legacy user/security.php.
 *
 *   GET|POST /settings   → three independent forms:
 *     form=password     change password (verify current, min 8, confirm)
 *     form=preferences  2FA toggle + language + currency
 *     form=deactivate   type DEACTIVATE + password → users.status =
 *                       'deactivated', session cleared, back to login
 *
 * Port notes:
 *   - csrf_verify()/csrf_field() become the per-key session token pair
 *     (key: 'settings' — one key for all three forms, like the legacy
 *     global session token).
 *   - SELF-HEAL: users.two_factor_enabled / language / currency / status
 *     are ensured on first run (same pattern as the wallet port).
 *   - Dark-mode is client-side only; the toggle drives the app-wide
 *     html.dark + localStorage roomhive_theme convention (legacy keyed
 *     rhTheme / body[data-theme] — migrated like payments/wallet).
 */
class SecurityController extends AbstractController
{
    private const CSRF_KEY = 'settings';

    public function __construct(private readonly Connection $db) {}

    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'], priority: 2)]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* =========================================================
           SELF-HEAL — preference columns first (legacy assumed they
           exist; the select below would throw without them)
        ========================================================== */
        foreach (
            [
                'two_factor_enabled' => 'ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0',
                'language'           => "ALTER TABLE users ADD COLUMN language VARCHAR(10) NOT NULL DEFAULT 'en'",
                'currency'           => "ALTER TABLE users ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'PHP'",
                'status'             => "ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'",
            ] as $column => $ddl
        ) {
            try {
                if ($this->db->fetchOne("SHOW COLUMNS FROM users LIKE '$column'") === false) {
                    $this->db->executeStatement($ddl);
                }
            } catch (\Throwable $e) {
                error_log('settings: column self-heal failed (' . $column . '): ' . $e->getMessage());
            }
        }

        /* ---- user row (legacy select) ---- */
        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, password, avatar_path, is_host,
                    two_factor_enabled, language, currency
               FROM users WHERE id = :id LIMIT 1',
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

        $twoFactorEnabled = (bool)($user['two_factor_enabled'] ?? false);
        $language         = (string)($user['language'] ?? 'en');
        $currency         = (string)($user['currency'] ?? 'PHP');

        $passwordErrors = [];
        $passwordSaved  = false;
        $prefsSaved     = false;

        /* =========================================================
           CHANGE PASSWORD
        ========================================================== */
        if ($request->isMethod('POST') && $request->request->get('form') === 'password') {
            if (!$this->csrfValid($session, self::CSRF_KEY, $this->postedToken($request))) {
                $passwordErrors[] = 'Your session expired. Please try again.';
            }

            $current = (string)$request->request->get('current_password', '');
            $new     = (string)$request->request->get('new_password', '');
            $confirm = (string)$request->request->get('confirm_password', '');

            if (empty($passwordErrors) && !password_verify($current, (string)$user['password'])) {
                $passwordErrors[] = 'Current password is incorrect.';
            }
            if (strlen($new) < 8) {
                $passwordErrors[] = 'New password must be at least 8 characters.';
            }
            if ($new !== $confirm) {
                $passwordErrors[] = 'New password and confirmation do not match.';
            }

            if (empty($passwordErrors)) {
                $this->db->update('users', ['password' => password_hash($new, PASSWORD_DEFAULT)], ['id' => $userId]);
                $passwordSaved = true;
            }
        }

        /* =========================================================
           2FA + LANGUAGE + CURRENCY
        ========================================================== */
        if (
            $request->isMethod('POST') && $request->request->get('form') === 'preferences'
            && $this->csrfValid($session, self::CSRF_KEY, $this->postedToken($request))
        ) {
            $twoFactorEnabled = $request->request->getBoolean('two_factor_enabled');
            $language         = (string)$request->request->get('language', 'en');
            $currency         = (string)$request->request->get('currency', 'PHP');

            $this->db->update('users', [
                'two_factor_enabled' => $twoFactorEnabled ? 1 : 0,
                'language'           => $language,
                'currency'           => $currency,
            ], ['id' => $userId]);

            $prefsSaved = true;
        }

        /* =========================================================
           DEACTIVATE ACCOUNT
        ========================================================== */
        $deactivateErrors = [];

        if ($request->isMethod('POST') && $request->request->get('form') === 'deactivate') {
            if (!$this->csrfValid($session, self::CSRF_KEY, $this->postedToken($request))) {
                $deactivateErrors[] = 'Your session expired. Please try again.';
            }

            $confirmWord = trim((string)$request->request->get('confirm_word', ''));
            $currentPw   = (string)$request->request->get('deactivate_password', '');

            if (empty($deactivateErrors) && !password_verify($currentPw, (string)$user['password'])) {
                $deactivateErrors[] = 'Password is incorrect.';
            }
            if (strtoupper($confirmWord) !== 'DEACTIVATE') {
                $deactivateErrors[] = 'Type DEACTIVATE to confirm.';
            }

            if (empty($deactivateErrors)) {
                $this->db->update('users', ['status' => 'deactivated'], ['id' => $userId]);

                $session->clear();
                return $this->redirectToRoute('app_login', ['deactivated' => 1]);
            }
        }

        return $this->render('user/security.html.twig', [
            'user'               => $user,
            'two_factor_enabled' => $twoFactorEnabled,
            'language'           => $language,
            'currency'           => $currency,
            'password_errors'    => $passwordErrors,
            'password_saved'     => $passwordSaved,
            'prefs_saved'        => $prefsSaved,
            'deactivate_errors'  => $deactivateErrors,
            'notif_count'        => $this->notifCount($userId),
            'csrf_settings'      => $this->ensureCsrfToken($session, self::CSRF_KEY),
        ]);
    }

    /* =====================================================================
     |  HELPERS — same implementations as WalletController (kept identical
     |  so both controllers behave exactly alike)
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
