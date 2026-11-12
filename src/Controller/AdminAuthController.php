<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin Auth (login + logout).
 * Spirit-of-1:1 port of legacy auth/adminlogin.php + auth/logout.php
 * (admin side).
 *
 *   GET      /admin/login   → renders templates/auth/login.html.twig —
 *     the SAME dark "night hive" AJAX login page /login uses (the
 *     template's form POSTs to path('app_login'), so signing in here
 *     flows through the /login controller, which performs the admin
 *     + DB login and hands the admin session to the dashboard).
 *   POST     /admin/login   → still answers direct POSTs itself
 *     (allowlist + bcrypt + CSRF) for robustness — the AJAX form
 *     normally bypasses it via /login.
 *   GET      /admin/logout  → destroys the WHOLE session (no one
 *     is logged in afterwards — the admin keys, any co-existing
 *     user keys, CSRF tokens and flashes are all gone) and lands
 *     on the public RoomHive home / as a guest. (The dashboard's
 *     Log Out link targets this.)
 *
 * ADMIN ONLY — two gates, both must pass:
 *   1. ADMIN_EMAILS allowlist below (one email — the single admin
 *      account). Edit this one line to change the admin email.
 *   2. The user row must exist, be status='active', and
 *      password_verify() must pass against its bcrypt hash.
 *
 * The separate admin session keys (admin_logged_in / admin_name /
 * admin_email / admin_csrf) are the ONLY thing the dashboard trusts
 * — user-flow sessions never grant admin access.
 *
 * REUSES AUTH LOGIN PAGE — no templates/admin/login.html.twig is
 * needed (creating it is harmless but nothing renders it anymore).
 * The template receives its full documented contract (error,
 * success, old_email, redirect_param, csrf, google_login_url,
 * apple_login_url) so it renders identically no matter which
 * controller renders it.
 *
 * REMOVE the old dev-login helper (src/Controller/
 * AdminDevLoginController.php) — it seeds an admin session with no
 * password check and defeats this whole gate.
 */
class AdminAuthController extends AbstractController
{
    /**
     * The single email allowed to sign in to the admin panel.
     * To change the admin email, edit this one line.
     */
    private const ADMIN_EMAILS = [
        'admin1@roomhive.com',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/login', name: 'app_admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $session = $request->getSession();

        /* Already signed in as admin? Straight to the dashboard. */
        if ($session->get('admin_logged_in')) {
            return $this->redirect('/admin');
        }

        /* Per-session CSRF — same key family the dashboard uses.
           Seeded here and handed to the login page as its `csrf`
           variable, so even a direct POST to this route carries a
           token that validates. */
        $adminCsrf = (string) $session->get('admin_csrf');
        if ($adminCsrf === '') {
            $adminCsrf = bin2hex(random_bytes(32));
            $session->set('admin_csrf', $adminCsrf);
        }

        $error    = '';
        $oldEmail = '';

        if ($request->isMethod('POST')) {
            $oldEmail    = trim((string) $request->request->get('email', ''));
            $password    = (string) $request->request->get('password', '');
            $postedToken = (string) $request->request->get(
                '_token',
                $request->request->get('csrf_token', '')
            );

            if (!hash_equals($adminCsrf, $postedToken)) {
                $error = 'Your session expired. Please try again.';
            } elseif ($oldEmail === '' || $password === '') {
                $error = 'Please enter your admin email and password.';
            } elseif (!in_array(strtolower($oldEmail), self::ADMIN_EMAILS, true)) {
                /* Gate 1 — allowlist. Same message as a wrong password
                   so non-admins learn nothing about which emails exist. */
                $error = 'Incorrect admin email or password.';
            } else {
                /* Gate 2 — real credential check against users. */
                $user = $this->db->fetchAssociative(
                    "SELECT id, name, email, password, status
                     FROM users WHERE email = :email LIMIT 1",
                    ['email' => $oldEmail]
                );

                if (
                    !$user
                    || ($user['status'] ?? 'active') !== 'active'
                    || !password_verify($password, (string) $user['password'])
                ) {
                    $error = 'Incorrect admin email or password.';
                } else {
                    /* Success — seed the admin session (the keys the
                       dashboard checks) and rotate the CSRF token. */
                    $session->set('admin_logged_in', true);
                    $session->set('admin_name', (string) $user['name']);
                    $session->set('admin_email', (string) $user['email']);
                    $session->set('admin_role', 'admin');
                    $session->set('admin_csrf', bin2hex(random_bytes(32)));

                    return $this->redirect('/admin');
                }
            }
        }

        /* REUSES AUTH LOGIN PAGE — render templates/auth/login.html.twig
           (the page /login renders), passing its full documented
           variable contract so it can never crash with a
           "Variable ... does not exist" RuntimeError regardless of
           twig strict_variables:

             error            → '' (no server-side error on a fresh GET;
                                 the AJAX flow shows submit errors itself)
             success          → '' (no "account created" banner here)
             old_email        → re-fill the email input after a failed
                                 direct POST (same job /login's
                                 ?email= query does after signup)
             redirect_param   → '' (no ?redirect= passthrough from here;
                                 the template's guarded OAuth block
                                 therefore stays hidden, same as /login
                                 renders it)
             csrf             → the admin_csrf token, so the form's
                                 _token input carries a real value
             google_login_url → '' (no OAuth wired yet)
             apple_login_url  → '' (no OAuth wired yet)

           The form then POSTs to path('app_login') — the /login
           controller — which performs the admin + DB login exactly
           like a sign-in from /login itself. */
        return $this->render('auth/login.html.twig', [
            'error'            => $error,
            'success'          => '',
            'old_email'        => $oldEmail,
            'redirect_param'   => '',
            'csrf'             => $adminCsrf,
            'google_login_url' => '',
            'apple_login_url'  => '',
        ]);
    }

    #[Route('/admin/logout', name: 'app_admin_logout', methods: ['GET'])]
    public function logout(Request $request): Response
    {
        $session = $request->getSession();

        /* LOG OUT = NO ONE LOGGED IN — invalidate() destroys the
           ENTIRE session and rotates the session ID: the admin
           keys (admin_logged_in / admin_name / admin_email /
           admin_csrf), any co-existing user keys (logged_in /
           user_id / is_host / avatar_path), the CSRF token bag and
           the flash bag all die together. Nothing survives on this
           browser, so the next page can only render as a pure
           guest — and the AdminHomeRedirectSubscriber stops
           firing, because its admin_logged_in key died with the
           session (GET / can no longer bounce to /admin). */
        $session->invalidate();

        /* HOME OF ROOMHIVE — land on the public site root as a
           guest instead of the admin login screen. Signing back
           in is always a manual choice: /admin/login (which now
           shows the auth/login.html.twig page) or /login. */
        return $this->redirect('/');
    }
}
