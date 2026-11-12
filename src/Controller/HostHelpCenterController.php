<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Help Center.
 * 1:1 port of legacy host/helpcenter.php.
 *
 *   GET /host/help   name app_host_help
 *
 *   (URL + sidebar key 'helpcenter' were pre-reserved by
 *    host/base.html.twig's footer sidebar link — "Help Center".)
 *
 * FAQ + search + popular guides + contact support. A READ-ONLY
 * page: legacy has NO POST handler, NO CSRF token and NO flash —
 * the port is a pure GET controller (methods: ['GET']).
 *
 * SHELL FIX (legacy note): host_navbar.php / host_footer.php were
 *   retired with the shell migration; legacy now includes the
 *   shared navbar + notification dropdown + the real
 *   /host/host_sidebar.php + footer. The ported shell
 *   (host/base.html.twig) ships all four for free: shared navbar
 *   with bell + dropdown, collapsible host sidebar (active_nav =
 *   'helpcenter' — the footer "Help Center" link highlights), no
 *   manual includes left.
 *
 * HOST GUARD (legacy "GUARD — was missing" note, added in the same
 *   legacy revision): non-hosts bounce to Become a Host — the
 *   STANDARD host-page target (/host/become-a-host, legacy
 *   becomeahost.php), exactly like every other host-page port
 *   (HostSecurityController, HostEditProfileController, ...).
 *   NOTE: this page uses the standard target — unlike Quit Hosting,
 *   which intentionally goes to /profile instead.
 *
 * BADGE COUNTS: legacy computed the unread count ONCE and shared
 *   it with the sidebar ($hostNotifUnread = $notification_count),
 *   plus the pending-tenants count for the sidebar badge. The port
 *   keeps that single-query contract (the shell reuses
 *   notification_count for both bell and sidebar badge) — same
 *   private methods as every host-page controller.
 *
 * FAQ CONTENT (legacy "FAQ CONTENT FIX"): the old "What is Hive
 *   Club?" entry was REPLACED site-wide — Hive Club was deleted,
 *   and a FAQ advertising a deleted feature sent hosts looking for
 *   a page that 404s. Replaced with a "How do I quit hosting?"
 *   entry pointing at the real quithosting.php flow. The 8 entries
 *   below are that fixed list, verbatim. PHP owns the data; the
 *   template loops it into <details class="hp-faq" data-faq>
 *   accordions exactly like legacy (and the page-local filter
 *   script matches against the rendered text).
 *
 * ICONS: ONE icon on the whole page — the search glyph in the FAQ
 *   search box (legacy <i data-lucide="search">; the legacy note
 *   says the onerror-style PNG fallback is gone — the SVG renders
 *   or nothing does). Rendered via the shared macro
 *   ui.icon('search') and sized by the page-local .hp-search-wrap
 *   svg rule (verbatim legacy values). No page-level lucide loader
 *   — the shared navbar ships the guarded loader.
 *
 * CSS NOTE: hp-page-header / hp-article-* / hp-faq-* / hp-grow-* /
 *   hp-btn-primary came from legacy hostprofile.css, which the
 *   Symfony shell does not ship — redeclared page-locally (same
 *   approach as every ported host page). The .hp-search-wrap rules
 *   are the page's OWN legacy <style> block verbatim (plus the
 *   input rules reused from the bookings port — same hostprofile.css
 *   source); the classes with no surviving values are approximated
 *   to the shell's design tokens and marked in the template.
 *
 * LINK MAP (legacy → ported; TODO links 404 until their file is
 *   pasted and ported — same policy as the shell's sidebar map):
 *     Popular guide "Hosting 101"      howitworks.php  → /how-it-works
 *       (the shared navbar's HOW IT WORKS link — one page serves
 *        hosts and users; partials/navbar.html.twig already points
 *        there, so the host guide card follows the same URL)
 *     Popular guide "Create a Listing" becomeahost.php → /host/become-a-host
 *       (same mapping every host guard uses)
 *     Popular guide "Getting Paid"     payouts.php     → /host/payouts  ✓ ported
 *     CONTACT SUPPORT button           contacts.php    → /contacts
 *       (navbar partial's CONTACTS mapping)
 *
 * Auth: logged_in + user_id (else app_login); missing user row →
 *   session clear + login (same as HostQuitHostingController).
 */
class HostHelpCenterController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/host/help', name: 'app_host_help', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostQuitHostingController) ---- */
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

        /* ---- HOST GUARD (legacy "was missing" note) — non-hosts
           go to Become a Host, the standard host-page target. ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* =========================================================
           BADGE COUNTS — navbar bell + sidebar badges. Legacy
           computed the unread count ONCE and shared it with the
           sidebar ($hostNotifUnread = $notification_count); the
           port keeps that single-query contract.
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
           FAQ CONTENT — the "What is Hive Club?" entry was REPLACED
           in legacy (Hive Club was deleted site-wide; the old entry
           sent hosts to a dead page). These 8 entries are the fixed
           list, verbatim.
        ========================================================= */
        $faqs = [
            ['How do payouts work?', 'Go to Payouts, request any amount from your available balance (min ₱500), and it will be sent to your default payout method within 1–3 business days. Set a default method under Payout Methods first.'],
            ['How do I accept or reject a tenant?', 'Open Pending Tenants (or the 3-dot menu on a listing in Overview) and choose Accept or Reject. Only one active application per listing is shown at a time.'],
            ['When do my listings show as "Active"?', 'New listings start as Pending until the RoomHive team approves them. Once approved, they appear in public search results immediately.'],
            ['How is my occupancy rate calculated?', "It's the number of nights booked in the last 30 days divided by the total available nights across all your listings."],
            ['How do I delete a listing?', 'Overview → My Listings card → 3-dot menu on the listing → Delete Listing. This is permanent and also removes its photos.'],
            ['How do I get the "Verified Host" badge?', 'Complete the items on the Verification page. Your government ID status comes from your Become a Host application.'],
            ['How do I quit hosting?', 'Go to Quit Hosting in the sidebar. Your account, booking history, and reviews are kept — your listings are simply unlisted, and you can become a host again anytime.'],
            ['How do I change my password?', 'Go to Security in the sidebar. You will need your current password, and the new one must be at least 8 characters.'],
        ];

        return $this->render('host/help_center.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'faqs' => $faqs,
        ]);
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostQuitHostingController /
     *  HostSecurityController / HostPayoutsController /
     *  HostNotificationSettingsController (best-effort: DB hiccups count 0).
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
     *  PHOTO PATH RESOLVER — same as HostQuitHostingController /
     *  HostProfileController / HostEditProfileController /
     *  HostPayoutsController / HostNotificationSettingsController /
     *  HostSecurityController.
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
