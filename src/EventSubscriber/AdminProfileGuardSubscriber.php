<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RoomHive — Admin Profile Guard.
 *
 * ADMIN SHOULD HAVE NO PROFILE — the admin account
 * (admin1@roomhive.com) exists in the users table only so
 * password_verify() can authenticate it. It must never present
 * itself as a site user: no account overview, no bookings, no
 * wallet, no messages — none of the user-area pages.
 *
 * Why a guard is needed: the AJAX /login flow logs the admin in
 * as a users-table row too (logged_in / user_id are seeded
 * alongside the admin keys), so the shared navbar happily offers
 * "My Profile" to the admin. When the admin then opens any
 * user-account page, THIS subscriber fires:
 *
 *   → the ENTIRE session is destroyed ($session->invalidate(),
 *     same "NO ONE LOGGED IN" semantics as AdminAuthController::
 *     logout — admin keys, user keys, CSRF tokens, flashes all
 *     die and the session ID rotates), and
 *   → the browser is redirected to the RoomHive home /
 *     as a pure guest.
 *
 * So an admin that touches the user profile area is
 * AUTOMATICALLY LOGGED OUT — there is no page to delete, the
 * profile simply can never render for the admin.
 *
 * Two gates (either one triggers the logout):
 *   Gate A — admin MODE: the session carries admin_logged_in
 *     (the admin panel sign-in). An admin in panel mode must
 *     never reach the user area.
 *   Gate B — admin ACCOUNT: the session's user_id resolves to a
 *     users row whose email is on the ADMIN_EMAILS allowlist.
 *     This catches the admin that signed in through the user
 *     /login flow and only carries user keys.
 *
 * GUARDED AREA (the user account shell — the pages behind the
 * navbar's "My Profile" and its sidebar): /profile (overview,
 * edit, avatar upload), /bookings, /wishlist (+ toggle),
 * /wallet, /payments, /reviews (+ remove), /messages (+ send,
 * start), /notifications (+ mark-read), /settings (account
 * settings + /settings/notifications). Sub-path matching is
 * segment-exact, so /payments guards /payments and
 * /payments/... but never collides with the booking flow's
 * /payment/review, /payment/confirm or /listings/{id}/payment
 * — those stay open (a guest or user mid-booking is not
 * interrupted).
 *
 * NOT guarded: / (home), /listings, /help, /become-a-host and
 * the rest of the public site, the /admin panel, the auth pages
 * (/login, /register) and the booking-payment flow. The admin
 * may still browse the public site and the panel freely — only
 * the user-account area ejects the session.
 *
 * Keep ADMIN_EMAILS in sync with AdminAuthController (the same
 * single-email allowlist).
 *
 * Drop into: src/EventSubscriber/AdminProfileGuardSubscriber.php
 * Symfony auto-registers EventSubscriberInterface classes via the
 * standard services.yaml "App\" resource autoload + the
 * kernel.event_subscriber auto-tag, so no config changes are
 * needed.
 *
 * After dropping in: php bin/console cache:clear
 */
class AdminProfileGuardSubscriber implements EventSubscriberInterface
{
    /**
     * The single admin email — the account that must never own
     * a user profile. Edit here AND in AdminAuthController to
     * change the admin email.
     */
    private const ADMIN_EMAILS = [
        'admin1@roomhive.com',
    ];

    /**
     * The user account area, by path prefix. Segment-exact:
     * a prefix matches the path itself or anything below it
     * ("/profile" also guards "/profile/edit"), and never a
     * longer path that merely starts with the same letters.
     */
    private const GUARDED_PREFIXES = [
        '/profile',
        '/bookings',
        '/wishlist',
        '/wallet',
        '/payments',
        '/reviews',
        '/messages',
        '/notifications',
        '/settings',
    ];

    public function __construct(private readonly Connection $db) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 45 — same family as AdminHomeRedirectSubscriber
            // (50): after the session listener (priority 60) so the
            // session is already attached, before the controllers.
            // Setting a response on the event short-circuits the
            // controller entirely — the profile page never runs.
            KernelEvents::REQUEST => ['onKernelRequest', 45],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Sub-requests (Twig {% render %}, fragments, ESI) must
        // never be hijacked — only the main browser request.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Defensive: if the session isn't attached yet, bail —
        // we can't tell who this is.
        if (!$request->hasSession()) {
            return;
        }

        /* Is this a user-account-area path? Segment-exact match. */
        $path = $request->getPathInfo();
        $guarded = false;
        foreach (self::GUARDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $guarded = true;
                break;
            }
        }
        if (!$guarded) {
            return;
        }

        $session = $request->getSession();

        /* Gate A — admin MODE. The panel sign-in's session key is
           present: this browser is the admin, and the admin has
           no profile. Kill the whole session, land on home. */
        if ($session->get('admin_logged_in')) {
            $session->invalidate();
            $event->setResponse(new RedirectResponse('/'));
            return;
        }

        /* Gate B — admin ACCOUNT via the user flow. No admin keys
           in the session, but the logged-in user_id may still be
           the admin's users row (the AJAX /login seeds logged_in +
           user_id for it). Resolve the account and compare its
           email with the allowlist. */
        $userId = (int) $session->get('user_id', 0);
        if ($userId <= 0) {
            // Plain guest — the user-area controllers handle their
            // own login redirect; nothing to guard here.
            return;
        }

        try {
            $row = $this->db->fetchAssociative(
                'SELECT email FROM users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            );
        } catch (\Throwable $e) {
            // DB hiccup — never log a real user out on a maybe.
            error_log('AdminProfileGuard: user lookup failed: ' . $e->getMessage());
            return;
        }

        $email = strtolower((string) ($row['email'] ?? ''));
        if ($email !== '' && in_array($email, self::ADMIN_EMAILS, true)) {
            // The admin account opened a user-account page:
            // AUTOMATIC LOGOUT — the entire session dies and the
            // browser lands on the RoomHive home as a pure guest.
            $session->invalidate();
            $event->setResponse(new RedirectResponse('/'));
        }
    }
}
