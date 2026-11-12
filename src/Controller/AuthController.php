<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    /* =========================================================
       ADMIN — bootstrap workflow (see .env notes):
         1. Put ADMIN_SETUP_PASSWORD=<plaintext> in .env
         2. Load /login once → hash written to var/
         3. REMOVE the line from .env
       Later: delete var/roomhive_admin_hash.php, repeat.
       Permanent alternative: ADMIN_PASSWORD_HASH in .env
       (escape each $ as $$).
    ========================================================== */
    private const ADMIN_NAME  = 'Admin User';
    private const ADMIN_EMAIL = 'admin@roomhive.com';

    /* Post-login destinations — route NAME, or "/path"
       (starting with "/") if the target isn't routed yet. */
    private const USER_REDIRECT  = '/';       // ← your home / usershome route
    private const ADMIN_REDIRECT = '/admin';  // ← your admin dashboard route

    /* Whitelisted ?redirect= values (open-redirect guard). */
    private const ALLOWED_REDIRECTS = [
        // '/membership',
    ];

    private const CSRF_ID  = 'login';
    private const CSRF_REG = 'register';
    private const CSRF_FP  = 'forgot_password';

    public function __construct(
        private readonly Connection $db,
    ) {}

    /* =========================================================
       LOGIN
    ========================================================== */
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $session = $request->getSession();
        $isAjax  = strtolower($request->headers->get('X-Requested-With', ''))
            === 'xmlhttprequest';

        $redirectParam = (string) ($request->query->get('redirect')
            ?? $request->request->get('redirect', ''));

        /* ---- BACK-BUTTON GUARD (GET and POST) ---- */
        if ($session->get('admin_logged_in') === true) {
            return $this->safeRedirect(self::ADMIN_REDIRECT);
        }

        if ($session->get('logged_in') === true) {
            return $this->safeRedirect(
                in_array($redirectParam, self::ALLOWED_REDIRECTS, true)
                    ? $redirectParam
                    : self::USER_REDIRECT
            );
        }

        /* ---- Google OAuth error translation ---- */
        $googleErrorCodes = [
            'config'    => 'Google sign-in is not configured yet. Please use your email and password for now.',
            'cancelled' => 'Google sign-in was cancelled. You can try again anytime.',
            'state'     => 'Your Google sign-in session expired. Please try again.',
            'code'      => 'Google did not return an authorization code. Please try again.',
            'token'     => 'We could not verify your Google account. Please try again.',
            'profile'   => 'Your Google account email is not verified. Verify it at Google and try again.',
            'taken'     => 'This Google account is already linked to another RoomHive account.',
            'create'    => 'We could not create your account from Google. Please sign up with email instead.',
            'exception' => 'Something went wrong with Google sign-in. Please try again or use your email.',
        ];

        $error   = '';
        $success = '';
        $oldEmail = '';

        $googleError = (string) $request->query->get('google_error', '');
        if ($googleError !== '' && isset($googleErrorCodes[$googleError])) {
            $error = $googleErrorCodes[$googleError];
        }

        /* ---- ?created=1&email=... from register() —
                show the success message and prefill the email ---- */
        if ((string) $request->query->get('created') === '1') {
            $success  = 'Account created! Log in with your new credentials.';
            $oldEmail = trim((string) $request->query->get('email', ''));
        }

        $csrf = $this->ensureCsrfToken($request, self::CSRF_ID);

        /* ---- POST — validate + log in ---- */
        if ($request->isMethod('POST')) {

            $email    = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $oldEmail = $email;

            if (!$this->csrfValid($request, self::CSRF_ID, (string) $request->request->get('_token', ''))) {
                $error = 'Your session expired. Please try again.';
            } elseif ($email === '' || $password === '') {
                $error = 'Please enter your email address and password.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {

                /* ---- ADMIN CHECK — before the DB login ---- */
                if (
                    hash_equals(strtolower(self::ADMIN_EMAIL), strtolower($email))
                    && password_verify($password, $this->adminPasswordHash())
                ) {

                    $session->migrate(true);

                    $session->set('admin_name', self::ADMIN_NAME);
                    $session->set('admin_email', self::ADMIN_EMAIL);
                    $session->set('admin_logged_in', true);

                    $redirectTo = self::ADMIN_REDIRECT;

                    if ($isAjax) {
                        return new JsonResponse([
                            'success'  => true,
                            'redirect' => $redirectTo,
                            'name'     => self::ADMIN_NAME,
                            'admin'    => true,
                        ]);
                    }

                    return $this->safeRedirect($redirectTo);
                }

                /* ---- DATABASE LOGIN — bcrypt ---- */
                $user = $this->db->fetchAssociative(
                    "SELECT id, name, email, password, avatar_path, is_host
                     FROM users WHERE email = :email LIMIT 1",
                    ['email' => $email]
                );

                if ($user && password_verify($password, (string) $user['password'])) {

                    $session->migrate(true);

                    $session->set('user_id', (int) $user['id']);
                    $session->set('user_name', $user['name']);
                    $session->set('user_email', $user['email']);
                    $session->set('logged_in', true);
                    $session->set('avatar_path', $user['avatar_path'] ?? null);
                    $session->set('is_host', (int) ($user['is_host'] ?? 0) === 1);

                    $redirectTo = in_array($redirectParam, self::ALLOWED_REDIRECTS, true)
                        ? $redirectParam
                        : self::USER_REDIRECT;

                    if ($isAjax) {
                        return new JsonResponse([
                            'success'  => true,
                            'redirect' => $redirectTo,
                            'name'     => $user['name'],
                            'admin'    => false,
                        ]);
                    }

                    return $this->safeRedirect($redirectTo);
                }

                $error = 'Incorrect email address or password.';
            }

            if ($isAjax && $error !== '') {
                return new JsonResponse([
                    'success' => false,
                    'error'   => $error,
                ]);
            }
        }

        return $this->render('auth/login.html.twig', [
            'error'            => $error,
            'success'          => $success,
            'old_email'        => $oldEmail,
            'redirect_param'   => $redirectParam,
            'csrf'             => $csrf,
            'google_login_url' => '',
            'apple_login_url'  => '',
        ]);
    }

    /* =========================================================
       REGISTER — port of createaccount.php.
       SPLIT NAME FIELDS: the template posts first_name + last_name
       (two separate inputs side-by-side). This method combines
       them into the single `name` column ("First Last"). NO DB
       CHANGE — every other page keeps reading `name` untouched.

       Validation chain: required fields → email format →
       password ≥ 8 → confirm match → terms → duplicate-email
       guard → bcrypt INSERT. On success we do NOT log the user
       in — redirect to the login form with ?created=1&email=...
       (which login() honors with a success banner + prefilled
       email).
    ========================================================== */
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- BACK-BUTTON GUARD — logged-in users never see
                the signup form (same as legacy) ---- */
        if ($session->get('admin_logged_in') === true) {
            return $this->safeRedirect(self::ADMIN_REDIRECT);
        }

        if ($session->get('logged_in') === true) {
            return $this->safeRedirect(self::USER_REDIRECT);
        }

        $error = '';
        /* The template reads old.first_name / old.last_name / old.email —
           keys must match the field names exactly. */
        $old = ['first_name' => '', 'last_name' => '', 'email' => ''];

        $csrf = $this->ensureCsrfToken($request, self::CSRF_REG);

        if ($request->isMethod('POST')) {

            $old['first_name'] = trim((string) $request->request->get('first_name', ''));
            $old['last_name']  = trim((string) $request->request->get('last_name', ''));
            $old['email']      = trim((string) $request->request->get('email', ''));
            $password          = (string) $request->request->get('password', '');
            $confirmPassword   = (string) $request->request->get('confirm_password', '');
            $terms             = $request->request->getBoolean('terms');

            /* ---- Validation — same order/messages as legacy ---- */
            if (
                $old['first_name'] === '' || $old['last_name'] === ''
                || $old['email'] === '' || $password === '' || $confirmPassword === ''
            ) {
                $error = 'Please fill in all required fields.';
            } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($password !== $confirmPassword) {
                $error = 'Passwords do not match.';
            } elseif (!$terms) {
                $error = 'Please agree to the Terms of Service and Privacy Policy.';
            } else {

                /* ---- Duplicate email check ---- */
                $exists = $this->db->fetchOne(
                    'SELECT id FROM users WHERE email = :email LIMIT 1',
                    ['email' => $old['email']]
                );

                if ($exists) {
                    $error = 'An account with that email already exists.';
                } else {

                    /* ---- Combine First + Last into the single `name`
                            column. NO DB CHANGE — every page that reads
                            `name` keeps working untouched. ---- */
                    $fullName = $old['first_name'] . ' ' . $old['last_name'];

                    /* ---- Create the account (bcrypt) ---- */
                    $this->db->insert('users', [
                        'name'     => $fullName,
                        'email'    => $old['email'],
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                    ]);

                    /* ---- ACCOUNT CREATED → login form.
                            Do NOT log in here — the user signs in
                            with the credentials they just made. ---- */
                    return $this->redirectToRoute('app_login', [
                        'created' => 1,
                        'email'   => $old['email'],
                    ]);
                }
            }
        }

        return $this->render('auth/register.html.twig', [
            'error'             => $error,
            'old'               => $old,
            'csrf'              => $csrf,
            /* Social signups render only when a URL is set —
               point these at your OAuth entry points once ported. */
            'google_signup_url' => '',
            'apple_signup_url'  => '',
        ]);
    }

    /* =========================================================
       FORGOT PASSWORD — reset logic still a TODO (same as
       legacy); generic success message either way so responses
       never leak which emails are registered.
    ========================================================== */
    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        $session = $request->getSession();

        if ($session->get('logged_in') === true) {
            return $this->safeRedirect(self::USER_REDIRECT);
        }

        $error   = '';
        $success = '';
        $email   = '';

        $csrf = $this->ensureCsrfToken($request, self::CSRF_FP);

        if ($request->isMethod('POST')) {

            $email = trim((string) $request->request->get('email', ''));

            if (!$this->csrfValid($request, self::CSRF_FP, (string) $request->request->get('_token', ''))) {
                $error = 'Your session expired. Please try again.';
            } elseif ($email === '') {
                $error = 'Please enter your email address.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {

                /*
                 * PASSWORD RESET LOGIC WILL GO HERE.
                 *
                 * Later, this will:
                 * 1. Check if the email exists in MySQL.
                 * 2. Generate a secure reset token (hashed, with an
                 *    expiry — e.g. password_reset_tokens table).
                 * 3. Send the reset link to the user's email.
                 */

                $success = 'If an account exists with this email, a reset link has been sent.';
            }
        }

        return $this->render('auth/forgot_password.html.twig', [
            'error'   => $error,
            'success' => $success,
            'email'   => $email,
            'csrf'    => $csrf,
        ]);
    }

    /* =========================================================
       ADMIN PASSWORD HASH — .env hash → var cache file →
       bootstrap hash → lockout hash (never crashes).
    ========================================================== */
    private function adminPasswordHash(): string
    {
        $envHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? getenv('ADMIN_PASSWORD_HASH') ?: '';
        if (is_string($envHash) && strlen($envHash) > 20 && str_starts_with($envHash, '$2y$')) {
            return $envHash;
        }

        $cacheFile = $this->getParameter('kernel.project_dir') . '/var/roomhive_admin_hash.php';
        if (is_file($cacheFile)) {
            $cached = include $cacheFile;
            if (is_string($cached) && strlen($cached) > 20) {
                return $cached;
            }
        }

        $setup = $_ENV['ADMIN_SETUP_PASSWORD'] ?? getenv('ADMIN_SETUP_PASSWORD') ?: '';
        if (is_string($setup) && $setup !== '') {
            $hash = password_hash($setup, PASSWORD_BCRYPT);

            @file_put_contents(
                $cacheFile,
                "<?php\n// RoomHive admin password hash (auto-generated by AuthController).\n"
                    . "// Delete this file to reset the admin password via ADMIN_SETUP_PASSWORD.\n"
                    . 'return ' . var_export($hash, true) . ";\n"
            );

            return $hash;
        }

        return password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    }

    /* Redirect helper: "/path" → redirect(), else route name. */
    private function safeRedirect(string $target): Response
    {
        return str_starts_with($target, '/')
            ? $this->redirect($target)
            : $this->redirectToRoute($target);
    }

    /* =========================================================
       SELF-CONTAINED CSRF (no SecurityBundle) — one token per
       ID; login / register / forgot-password each get their own.
    ========================================================== */
    private function ensureCsrfToken(Request $request, string $id = self::CSRF_ID): string
    {
        $session = $request->getSession();
        $tokens  = $session->get('_rh_csrf_tokens', []);

        if (!isset($tokens[$id]) || !is_string($tokens[$id])) {
            $tokens[$id] = bin2hex(random_bytes(20));
            $session->set('_rh_csrf_tokens', $tokens);
        }

        return $tokens[$id];
    }

    private function csrfValid(Request $request, string $id, string $token): bool
    {
        $tokens = $request->getSession()->get('_rh_csrf_tokens', []);

        return isset($tokens[$id])
            && is_string($tokens[$id])
            && $token !== ''
            && hash_equals($tokens[$id], $token);
    }
}
