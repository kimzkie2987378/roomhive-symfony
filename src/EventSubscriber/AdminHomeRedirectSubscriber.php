<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RoomHive — Admin Home Redirect.
 *
 * When an admin is signed in (admin_logged_in session key, set by
 * AdminAuthController::login), a GET to the site root "/" is
 * redirected to the admin dashboard at /admin.
 *
 * Normal visitors and regular users never trigger this — only the
 * admin session key does. Their traffic is untouched.
 *
 * Drop into: src/EventSubscriber/AdminHomeRedirectSubscriber.php
 * Symfony auto-registers EventSubscriberInterface classes via the
 * standard services.yaml "App\" resource autoload + the
 * kernel.event_subscriber auto-tag, so no config changes are needed.
 *
 * After dropping in: php bin/console cache:clear
 */
class AdminHomeRedirectSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 50 — runs after the session listener (priority
            // 60) so the session is already attached to the request,
            // and after the router hasn't yet fired (router is 32).
            // Setting a response on the event short-circuits the
            // controller entirely.
            KernelEvents::REQUEST => ['onKernelRequest', 50],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Sub-requests (Twig {% render %}, fragment paths, etc.)
        // must never be hijacked — only the main browser request.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only the exact root path, only GET. POST /, sub-paths, API
        // endpoints and asset requests are all left alone.
        if (
            $request->getMethod() !== 'GET'
            || $request->getPathInfo() !== '/'
        ) {
            return;
        }

        // Defensive: if for some reason the session isn't attached
        // yet, bail — we can't tell whether they're an admin.
        if (!$request->hasSession()) {
            return;
        }

        // Admin session set by AdminAuthController::login on
        // successful /admin/login. If it's not set, leave the user
        // landing page alone.
        if ($request->getSession()->get('admin_logged_in')) {
            $dest = $request->getSession()->get('admin_role') === 'staff' ? '/staff' : '/admin';
            $event->setResponse(new RedirectResponse($dest));
        }
    }
}
