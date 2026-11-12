<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Delete Listing.
 * Port of legacy delete-listing.php — the endpoint behind the
 * "Delete" button on Host › My Listings (host/listings.html.twig).
 * The HostListingsController docblock reserved this exact URL in
 * its action-endpoint map: POST /host/listings/{id}/delete.
 *
 *   POST /host/listings/{id}/delete  → permanently deletes the
 *     host's OWN listing and every child row that hangs off it.
 *
 * FEEDBACK — GET-flag redirect (the same convention the listing
 *   detail page uses: /listings/{id}?unavailable=1 etc.). The
 *   browser lands back on /host/listings with:
 *     ?deleted=1                 → green "Listing deleted" banner
 *     ?delete_error=occupied     → reserved or occupied space
 *     ?delete_error=csrf         → session expired, retry
 *     ?delete_error=notfound     → missing / not owned by caller
 *     ?delete_error=failed       → DB rollback, retry
 *   (Legacy answered fetch()-JSON {success}|{message}; this port
 *   renders the result as banners instead — same outcome, no JS
 *   contract to keep alive.)
 *
 * GUARDS (all server-side, in order):
 *   1. Auth — logged_in + user_id (else /login), user row exists
 *      (else /login), is_host = 1 (else /host/become-a-host; the
 *      same convention as every host controller).
 *   2. CSRF — per-session token under _rh_csrf_tokens['host_
 *      listing_delete'] (same store + helpers as
 *      HostEditListingController; accepts csrf_token or _token
 *      field names). The My Listings page seeds and prints it.
 *   3. OWNERSHIP — the listing row's user_id must equal the
 *      session user_id. The final DELETE re-checks user_id in
 *      its WHERE, so even a raced request can't remove someone
 *      else's row.
 *   4. THE "SOMEONE ON THE SPACE" LOCK — a listing with any
 *      ACTIVE booking (status 'pending' = paid in full, awaiting
 *      decision, or 'confirmed' = occupied) is price/availability
 *      LOCKED in legacy terms, and locked listings cannot be
 *      deleted. Same rule AcceptBookingController enforces on the
 *      accept path. Only a listing with zero pending/confirmed
 *      bookings can be deleted (completed/rejected/cancelled
 *      history does NOT block deletion).
 *
 * DELETION CASCADE — executed INSIDE one transaction, children
 *   first, listing last (defensive: explicit child deletes make
 *   this work on ANY FK configuration, with or without ON DELETE
 *   CASCADE, and leave zero orphan rows):
 *     conversations  → listing_id = NULL  (RESTRICT FK; column is
 *                       nullable — the chat history survives, it
 *                       just loses its listing attachment)
 *     reviews        → DELETE (listing ratings die with it)
 *     wishlist       → DELETE (saved listings die with it)
 *     bookings       → DELETE (dump FK is CASCADE; explicit for
 *                       safety on older dumps)
 *     listing_photos → DELETE (dump FK is CASCADE; explicit for
 *                       safety)
 *     listings       → DELETE WHERE id = :id AND user_id = :uid
 *   wallet_transactions / payouts / notifications are user-scoped
 *   (no listing_id) — nothing to clean there.
 *
 * Drop into: src/Controller/HostDeleteListingController.php
 * After dropping in: php bin/console cache:clear
 */
class HostDeleteListingController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route(
        '/host/listings/{id}/delete',
        name: 'app_host_listing_delete',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
        priority: 2
    )]
    public function delete(Request $request, int $id): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostListingsController) ---- */
        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $dbUser = $this->db->fetchAssociative(
            'SELECT id, is_host FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if (!$dbUser) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* ---- Back to the manager page (with a result flag) ---- */
        $back = fn(string $flag) => $this->redirect('/host/listings' . $flag);

        /* ---- CSRF (same store + helpers as the edit controller) ---- */
        if (!$this->csrfValid($session, 'host_listing_delete', $this->postedToken($request))) {
            return $back('?delete_error=csrf');
        }

        /* ---- OWNERSHIP — the row must exist and be the caller's ---- */
        $listing = $this->db->fetchAssociative(
            'SELECT id, user_id, title FROM listings WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
        if (!$listing || (int) $listing['user_id'] !== $userId) {
            return $back('?delete_error=notfound');
        }

        /* ---- THE LOCK — reserved (pending) or occupied (confirmed)
           spaces cannot be deleted, legacy "price and availability
           lock while booked or reserving" semantics. Completed or
           rejected booking history does NOT block deletion. ---- */
        $activeBookings = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM bookings
             WHERE listing_id = :id AND status IN ('pending', 'confirmed')",
            ['id' => $id]
        );
        if ($activeBookings > 0) {
            return $back('?delete_error=occupied');
        }

        /* ---- THE DELETION — one transaction, children first ---- */
        $this->db->beginTransaction();
        try {
            /* Chat history survives — it just loses its listing. */
            $this->db->executeStatement(
                'UPDATE conversations SET listing_id = NULL WHERE listing_id = :id',
                ['id' => $id]
            );

            $this->db->executeStatement(
                'DELETE FROM reviews WHERE listing_id = :id',
                ['id' => $id]
            );
            $this->db->executeStatement(
                'DELETE FROM wishlist WHERE listing_id = :id',
                ['id' => $id]
            );
            $this->db->executeStatement(
                'DELETE FROM bookings WHERE listing_id = :id',
                ['id' => $id]
            );
            $this->db->executeStatement(
                'DELETE FROM listing_photos WHERE listing_id = :id',
                ['id' => $id]
            );

            /* The listing itself — user_id re-checks ownership one
               last time inside the same transaction. */
            $this->db->executeStatement(
                'DELETE FROM listings WHERE id = :id AND user_id = :uid',
                ['id' => $id, 'uid' => $userId]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('delete listing: rollback for listing ' . $id . ': ' . $e->getMessage());
            return $back('?delete_error=failed');
        }

        return $back('?deleted=1');
    }

    /* =====================================================================
     *  CSRF HELPERS — same _rh_csrf_tokens session store as
     *  HostEditListingController. (The token itself is seeded by
     *  HostListingsController when it renders the Delete button;
     *  this controller only VALIDATES it.)
     * ================================================================= */

    private function csrfValid(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, string $key, ?string $token): bool
    {
        $tokens = $session->get('_rh_csrf_tokens', []);
        return isset($tokens[$key]) && is_string($token) && hash_equals((string) $tokens[$key], $token);
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
}
