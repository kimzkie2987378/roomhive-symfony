<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RoomHive — Admin From User Login.
 *
 * Lets the admin sign in through the REGULAR USER login form at
 * /login (the "Welcome Back!" page) — no need to visit /admin/login
 * separately. After the user's /login controller successfully seeds
 * the regular user session (logged_in=true, user_id=...), this
 * subscriber inspects the response.
 *
 * Two response shapes are supported (your /login controller uses
 * the JSON one — see login.html.twig's fetch() handler):
 *
 *   1. JsonResponse (AJAX login) — the typical case. The JSON body
 *      already contains { success, redirect, name, admin, error }.
 *      If the just-signed-in user's email is on the admin allowlist,
 *      this subscriber seeds the admin session keys AND rewrites
 *      the JSON body so `redirect` becomes `/admin` and `admin`
 *      becomes true. The JS then calls
 *      window.location.replace('/admin') and the welcome animation
 *      shows "Taking you to the admin dashboard…".
 *
 *   2. RedirectResponse (non-AJAX fallback) — if you ever switch
 *      the login form back to a regular POST submit. The redirect
 *      target is rewritten from / or /profile to /admin.
 *
 *   Drop into:  src/EventSubscriber/AdminFromUserLoginSubscriber.php
 *   Auto-registered by Symfony's standard "App\" namespace autoload
 *   + kernel.event_subscriber auto-tag — no services.yaml edit
 *   needed. MUST be in src/EventSubscriber/ — NOT src/Controller/
 *   (PSR-4 requires the file path to match the namespace).
 *
 *   Requires:  ADMIN_EMAILS const below to MIRROR
 *   AdminAuthController::ADMIN_EMAILS — keep them in sync. Both
 *   currently list only admin1@roomhive.com.
 *
 *   After dropping in:  php bin/console cache:clear
 */
class AdminFromUserLoginSubscriber implements EventSubscriberInterface
{
    /**
     * Must match AdminAuthController::ADMIN_EMAILS.
     * Edit this list — and the one in AdminAuthController — together.
     */
    private const ADMIN_EMAILS = [
        'admin1@roomhive.com',
    ];

    public function __construct(
        private readonly Connection $db,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Run AFTER the /login controller has fully built its
            // response (redirect/JSON set, session written). Priority
            // 0 is well before Symfony's SaveSessionListener
            // (priority -1000) so the new admin session keys we set
            // here ARE persisted to storage.
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Sub-requests (Twig {% render %}, fragment paths, etc.)
        // must never be hijacked — only the main browser request.
        if (!$event->isMainRequest()) {
            return;
        }

        $request  = $event->getRequest();
        $response = $event->getResponse();

        // Only POST /login. GET /login renders the form — we leave
        // that alone. Failed logins render the form with an error
        // (or return a JSON error body) — we leave those alone too,
        // because logged_in won't be true (checked below).
        if (
            $request->getMethod() !== 'POST'
            || $request->getPathInfo() !== '/login'
        ) {
            return;
        }

        $session = $request->getSession();

        // Did the user-login controller actually sign them in?
        // If the user's regular login didn't succeed, leave the
        // response untouched.
        if ($session->get('logged_in') !== true) {
            return;
        }

        $userId = (int) $session->get('user_id', 0);
        if ($userId <= 0) {
            return;
        }

        // Look up the user's email & role — only happens once per POST
        // /login, so the cost is negligible.
        try {
            $user = $this->db->fetchAssociative(
                'SELECT name, email, role FROM users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            );
        } catch (\Throwable $e) {
            $user = $this->db->fetchAssociative(
                'SELECT name, email FROM users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            );
        }
        if (!$user) {
            return;
        }

        $email   = strtolower((string) $user['email']);
        $isAdmin = in_array($email, self::ADMIN_EMAILS, true);
        $isStaff = (string) ($user['role'] ?? '') === 'staff';

        if (!$isAdmin && !$isStaff) {
            // Regular user — let the user login's response stand
            // unchanged (JSON redirect to /, /profile, or wherever
            // it was going). No admin session keys get seeded.
            return;
        }

        // ────────────────────────────────────────────────────────
        //  ADMIN OR STAFF DETECTED — promote this session.
        //  Seed the admin session keys recognised by dashboard gates.
        // ────────────────────────────────────────────────────────
        $dest = $isAdmin ? '/admin' : '/staff';

        $session->set('admin_logged_in', true);
        $session->set('admin_name',      (string) $user['name']);
        $session->set('admin_email',     (string) $user['email']);
        $session->set('admin_role',      $isAdmin ? 'admin' : 'staff');
        $session->set('admin_csrf',      bin2hex(random_bytes(32)));

        // Rewrite the response so they land on /admin or /staff.
        // Two shapes supported (see class docblock).
        if ($response instanceof JsonResponse) {
            $this->rewriteJsonRedirect($response, $dest, $isAdmin, $isStaff);
        } elseif ($response instanceof RedirectResponse) {
            $response->setTargetUrl($dest);
        }
    }

    /**
     * Mutate the JSON body returned by the AJAX /login controller:
     *   - force `redirect` to '/admin' or '/staff'
     *   - set `admin` / `staff` flags for the welcome animation
     * Any other keys the controller set (success, name, error, …)
     * are preserved untouched.
     */
    private function rewriteJsonRedirect(JsonResponse $response, string $dest = '/admin', bool $isAdmin = true, bool $isStaff = false): void
    {
        $data = json_decode((string) $response->getContent(), true);

        // If the controller returned malformed JSON, don't touch
        // it — better to let the original response reach the
        // browser than to ship a half-broken one.
        if (!is_array($data)) {
            return;
        }

        $data['redirect'] = $dest;
        $data['admin']    = $isAdmin;
        $data['staff']    = $isStaff;

        $response->setData($data);
    }
}
