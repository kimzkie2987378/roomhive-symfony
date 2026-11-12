<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — ACCEPT BOOKING (host side).
 *
 * 1:1 port of legacy booking/accept-booking.php.
 *
 * Called via fetch() from host/pending-tenants.html.twig (sign-to-accept
 * flow), host/listings.html.twig and host/profile.html.twig when a host
 * clicks "Accept" on a tenant's application — AND from the official
 * receipt's on-page signing pad (payment/confirmation.html.twig) when
 * the host signs a still-pending application ("Sign & Accept").
 * Moves bookings.status from 'pending' to 'confirmed' AND pays out
 * whatever the tenant has paid into this booking so far
 * (bookings.amount_paid), split 97% to the host / 3% platform fee.
 *
 * TWO ROUTES, ONE ACTION (same legacy endpoint served at both URLs —
 * the host pages and the receipt pad each carry their own historical
 * URL contract):
 *   POST /host/bookings/{id}/accept   name app_host_booking_accept
 *                                     (route shape all the host
 *                                     templates already fetch — id
 *                                     from the URL)
 *   POST /booking/accept-booking      name app_booking_accept
 *                                     (exact legacy URL — id from the
 *                                     POST body; the receipt pad's
 *                                     JS posts here)
 * Both callers also send booking_id in the body; the route {id} (when
 * present) wins, otherwise the body value is used — exactly like
 * legacy, which only ever read the body.
 *
 * Responds JSON: { success: bool, message?: string }
 * HTTP codes mirror legacy exactly:
 *   401 not logged in · 400 bad id · 404 booking missing ·
 *   403 not the listing's host · 500 transaction failure ·
 *   "already decided" → HTTP 200 with success=false (legacy sent no
 *   status header for it, and both JS callers treat it as a soft fail).
 *
 * REQUIRES (present in the RoomHive schema — see roomhive_dump.sql):
 *   users.wallet_balance,
 *   bookings.host_payout_amount / platform_fee_amount / payout_at
 *
 * FIXED (upstream, kept): the ownership SELECT was missing b.user_id in
 * an earlier revision, so the guest notification read an undefined key
 * and notified user 0. The guest's id is selected as tenant_id and used
 * everywhere.
 *
 * GUARDS (all inside one transaction, FOR UPDATE row lock):
 *   - double-click or a race with a reject click cannot both go through;
 *   - only the host who owns the listing behind this booking may accept;
 *   - only while the booking is still pending, so accepting twice can't
 *     double-pay-out (belt: status check; braces: WHERE status='pending'
 *     + rowCount check on the UPDATE);
 *   - SIGNATURE GATE (user-requested rule, new vs legacy): an
 *     application can only be accepted once the host's signature is ON
 *     its official receipt. SignReceiptController writes
 *     public/uploads/signatures/booking-{id}.png the moment the host
 *     signs (Sign & Accept on the receipt pad / the Pending Tenants
 *     pad) — so no file = not signed yet = the booking must stay
 *     pending on the guest's payments waiting list ("Waiting for host
 *     to sign"). Accepting the application and recording the signed
 *     receipt are the same act now: sign ⇒ accept ⇒ receipt recorded;
 *     don't sign ⇒ the booking stays in the waiting list. The response
 *     carries needs_signature so callers can route the host to the
 *     receipt's Sign & Accept pad instead of just alerting.
 *   - OCCUPANCY: a listing with a 'confirmed' booking (someone
 *     currently living there) cannot take a new tenant. The host
 *     pages already hide/disable Accept while occupied (the
 *     is_occupied flag from HostListingsController); this re-check
 *     enforces the rule server-side even against a hand-crafted
 *     POST ("cannot accept applying users if the space is occupied").
 *
 * GUEST NOTIFICATION runs AFTER the payout COMMIT — a failure there can
 * never affect the acceptance above (same rule as
 * ProcessPaymentController::notifyHost). Link is the TODO booking-
 * details URL (/booking/details/{id}) — legacy
 * booking/booking-details.php, not ported yet.
 *
 * Wallet: legacy credits ONLY the users.wallet_balance row (no
 * wallet_transactions ledger entry) — matched 1:1.
 *
 * ONE ACTIVE APPLICATION PER TENANT (user-requested rule, new vs
 * legacy): the moment this accept commits, every OTHER still-pending
 * booking of the SAME tenant — on any listing, anywhere — is
 * automatically declined inside the SAME transaction, each with a
 * FULL refund of whatever that booking collected (same refund shape
 * as RejectBookingController: refunded_amount/refunded_at stamped,
 * payment_status untouched, wallet row only). That is what makes the
 * tenant's second application card disappear from Pending Tenants
 * (and from every other host's page) the moment the first one is
 * accepted — a renter can hold exactly one live application. The
 * post-commit guest notification mentions the auto-declines and the
 * refunded total, and each affected host is told their applicant
 * was accepted elsewhere. Confirmed / cancelled / rejected /
 * completed bookings are never touched.
 *
 * SAME-LISTING RIVALS (user-requested rule, new vs legacy): every
 * OTHER user's still-pending application for THIS listing is
 * declined in full inside the same transaction too — two
 * applications for one house, the host accepts one, the other must
 * disappear. Those applicants get their money back (full refund,
 * wallet row) and a notification explaining that another guest was
 * confirmed; their listing becomes visible to them again with the
 * confirmed dates disabled, so they can look elsewhere or re-apply
 * for free dates later.
 *
 * Port record — booking/accept-booking.php (this file).
 */
final class AcceptBookingController extends AbstractController
{
    /** Platform split — 97% to the host, 3% platform fee. */
    private const HOST_PAYOUT_RATE = 0.97;

    public function __construct(private readonly Connection $db) {}

    /* =====================================================================
     |  POST /host/bookings/{id}/accept   ·   POST /booking/accept-booking
     | ================================================================== */
    #[Route('/host/bookings/{id}/accept', name: 'app_host_booking_accept', methods: ['POST'])]
    #[Route('/booking/accept-booking', name: 'app_booking_accept', methods: ['POST'])]
    public function accept(Request $request, int $id = 0): Response
    {
        $session = $request->getSession();
        $userId  = (int) $session->get('user_id', 0);

        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(['success' => false, 'message' => 'You must be logged in.'], 401);
        }

        /* Route {id} (host-page contract) wins; body booking_id
           (legacy contract — the receipt pad) is the fallback.
           is_numeric + int cast exactly like legacy. */
        $rawId = $id > 0 ? (string) $id : (string) $request->request->get('booking_id', '');
        $bookingId = is_numeric($rawId) ? (int) $rawId : 0;

        if ($bookingId <= 0) {
            return $this->json(['success' => false, 'message' => 'Missing or invalid booking id.'], 400);
        }

        /* -----------------------------------------------------
           TRANSACTION — FOR UPDATE row lock + payout + commit
        ----------------------------------------------------- */
        $booking = null;
        $autoDeclined = 0;
        $autoRefund   = 0.0;
        $autoHosts    = [];
        $rivalDeclined = 0;
        $rivalNotices  = [];

        $this->db->beginTransaction();

        try {
            /* FOR UPDATE locks this row — a double-click or a race with
               a reject click on the same booking can't both go through. */
            $booking = $this->db->executeQuery(
                "SELECT b.id, b.status, b.amount_paid, b.listing_id,
                        b.user_id AS tenant_id,
                        l.user_id AS host_id, l.title AS listing_title
                   FROM bookings b
                   JOIN listings l ON l.id = b.listing_id
                  WHERE b.id = :booking_id
                    FOR UPDATE",
                ['booking_id' => $bookingId]
            )->fetchAssociative();

            if ($booking === false) {
                $this->db->rollBack();
                return $this->json(['success' => false, 'message' => 'Application not found.'], 404);
            }

            /* Only the host who owns the listing behind this booking
               may accept it. */
            if ((int) $booking['host_id'] !== $userId) {
                $this->db->rollBack();
                return $this->json(['success' => false, 'message' => 'You do not have permission to update this application.'], 403);
            }

            /* ...and only while it's still pending, so accepting twice
               can't double-pay-out. */
            if ((string) $booking['status'] !== 'pending') {
                $this->db->rollBack();
                return $this->json(['success' => false, 'message' => 'This application has already been decided.']);
            }

            /* SIGNATURE GATE — the FILE is the truth, same convention
               as PaymentConfirmationController / PaymentController
               ::index: no booking-{id}.png = the host has not signed
               the official receipt yet. The application then CANNOT
               be accepted — it stays pending, which is exactly what
               keeps it on the guest's payments waiting list (the
               /payments Receipts card lists pending/confirmed
               bookings without a signature file under "Waiting for
               host to sign"). Checked INSIDE the transaction right
               after the status guard, so a signature written a split
               second earlier is still honoured. Both pad flows (the
               receipt's and Pending Tenants') sign FIRST and never
               hit this; it catches the raw-accept paths (host
               profile menu, hand-crafted POSTs). */
            $sigGateFile = ((string) $this->getParameter('kernel.project_dir'))
                . '/public/uploads/signatures/booking-' . $bookingId . '.png';

            if (!is_file($sigGateFile)) {
                $this->db->rollBack();
                return $this->json([
                    'success'         => false,
                    'needs_signature' => true,
                    'message'         => 'Sign the official receipt first — your signature is the acceptance. The booking stays on the waiting list until it is signed.',
                ]);
            }

            /* OCCUPANCY GUARD — a space someone is currently living
               in (a 'confirmed' booking on this listing) cannot take
               a new tenant. The host pages refuse to offer Accept
               while occupied (is_occupied); this re-check makes the
               rule real server-side. */
            $occupiedCount = (int) $this->db->fetchOne(
                "SELECT COUNT(*)
                   FROM bookings
                  WHERE listing_id = :listing_id
                    AND status = 'confirmed'
                    AND id <> :booking_id",
                [
                    'listing_id' => (int) $booking['listing_id'],
                    'booking_id' => $bookingId,
                ]
            );

            if ($occupiedCount > 0) {
                $this->db->rollBack();
                return $this->json([
                    'success' => false,
                    'message' => 'This space is currently occupied. You cannot accept a new tenant while someone is still staying here.',
                ]);
            }

            /* Split whatever has actually been paid into this booking so
               far. Round the host's share first, then give the platform
               whatever's left over. */
            $amountPaid    = (float) $booking['amount_paid'];
            $hostShare     = round($amountPaid * self::HOST_PAYOUT_RATE, 2);
            $platformShare = round($amountPaid - $hostShare, 2);

            $affected = $this->db->executeStatement(
                "UPDATE bookings
                    SET status = 'confirmed',
                        host_payout_amount = :host_share,
                        platform_fee_amount = :platform_share,
                        payout_at = NOW()
                  WHERE id = :booking_id AND status = 'pending'",
                [
                    'host_share'     => $hostShare,
                    'platform_share' => $platformShare,
                    'booking_id'     => $bookingId,
                ]
            );

            if ($affected === 0) {
                $this->db->rollBack();
                return $this->json(['success' => false, 'message' => 'This application has already been decided.']);
            }

            /* Credit the host's wallet with their 97% share. */
            if ($hostShare > 0) {
                $this->db->executeStatement(
                    'UPDATE users SET wallet_balance = wallet_balance + :amount WHERE id = :host_id',
                    [
                        'amount'  => $hostShare,
                        'host_id' => (int) $booking['host_id'],
                    ]
                );
            }

            /* ONE ACTIVE APPLICATION PER TENANT — decline every OTHER
               still-pending booking this tenant has (on any listing).
               Runs INSIDE the same transaction: a failure here rolls
               the whole accept back — never a half state. Each
               declined booking refunds in FULL what it collected
               (same shape as RejectBookingController), and its host
               is collected for a post-commit notification. */
            $otherPending = $this->db->fetchAllAssociative(
                "SELECT b.id, b.amount_paid,
                        l.title AS listing_title,
                        l.user_id AS other_host_id
                   FROM bookings b
                   JOIN listings l ON l.id = b.listing_id
                  WHERE b.user_id = :tenant_id
                    AND b.status = 'pending'
                    AND b.id <> :booking_id
                    FOR UPDATE",
                [
                    'tenant_id'  => (int) $booking['tenant_id'],
                    'booking_id' => $bookingId,
                ]
            );

            foreach ($otherPending as $other) {
                $otherId     = (int) $other['id'];
                $otherPaid   = (float) $other['amount_paid'];
                $otherRefund = $otherPaid > 0.005 ? round($otherPaid, 2) : 0.0;

                $this->db->executeStatement(
                    "UPDATE bookings
                        SET status = 'rejected',
                            refunded_amount = :refund,
                            refunded_at = :refunded_at
                      WHERE id = :booking_id AND status = 'pending'",
                    [
                        'refund'      => $otherRefund,
                        'refunded_at' => $otherRefund > 0 ? date('Y-m-d H:i:s') : null,
                        'booking_id'  => $otherId,
                    ]
                );

                if ($otherRefund > 0) {
                    $this->db->executeStatement(
                        'UPDATE users SET wallet_balance = wallet_balance + :amount WHERE id = :user_id',
                        [
                            'amount'  => $otherRefund,
                            'user_id' => (int) $booking['tenant_id'],
                        ]
                    );
                }

                $autoDeclined++;
                $autoRefund = round($autoRefund + $otherRefund, 2);
                $autoHosts[] = [
                    'host_id' => (int) $other['other_host_id'],
                    'title'   => (string) $other['listing_title'],
                ];
            }

            /* SAME-LISTING RIVALS — every OTHER user's still-pending
               application for THIS listing is declined in full too:
               the space has just been promised to the accepted tenant
               (two applications for the same house, host accepts one
               — the other must disappear). Same refund shape as
               RejectBookingController, INSIDE the same transaction:
               a failure here rolls the whole accept back — never a
               half state. Each affected applicant is collected for
               a post-commit notification. The accepted tenant is
               excluded (b.user_id <> :tenant_id) so no booking can
               ever be touched by both auto-decline blocks, and the
               UPDATE's status='pending' guard double-protects. */
            $rivalPending = $this->db->fetchAllAssociative(
                "SELECT b.id, b.amount_paid, b.user_id AS rival_tenant_id
                   FROM bookings b
                  WHERE b.listing_id = :listing_id
                    AND b.status = 'pending'
                    AND b.user_id <> :tenant_id
                    AND b.id <> :booking_id
                    FOR UPDATE",
                [
                    'listing_id' => (int) $booking['listing_id'],
                    'tenant_id'  => (int) $booking['tenant_id'],
                    'booking_id' => $bookingId,
                ]
            );

            foreach ($rivalPending as $rival) {
                $rivalId     = (int) $rival['id'];
                $rivalPaid   = (float) $rival['amount_paid'];
                $rivalRefund = $rivalPaid > 0.005 ? round($rivalPaid, 2) : 0.0;

                $this->db->executeStatement(
                    "UPDATE bookings
                        SET status = 'rejected',
                            refunded_amount = :refund,
                            refunded_at = :refunded_at
                      WHERE id = :booking_id AND status = 'pending'",
                    [
                        'refund'      => $rivalRefund,
                        'refunded_at' => $rivalRefund > 0 ? date('Y-m-d H:i:s') : null,
                        'booking_id'  => $rivalId,
                    ]
                );

                if ($rivalRefund > 0) {
                    $this->db->executeStatement(
                        'UPDATE users SET wallet_balance = wallet_balance + :amount WHERE id = :user_id',
                        [
                            'amount'  => $rivalRefund,
                            'user_id' => (int) $rival['rival_tenant_id'],
                        ]
                    );
                }

                $rivalDeclined++;
                $rivalNotices[] = [
                    'user_id' => (int) $rival['rival_tenant_id'],
                    'refund'  => $rivalRefund,
                    'booking' => $rivalId,
                ];
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log('accept-booking: ' . $e->getMessage());
            return $this->json(['success' => false, 'message' => 'Could not accept this application. Please try again.'], 500);
        }

        /* -----------------------------------------------------
           NOTIFY THE GUEST (after the payout COMMIT; failure here
           can never affect the acceptance above). Reads tenant_id,
           which is guaranteed to exist in $booking.
        ----------------------------------------------------- */
        try {
            $guestId      = (int) ($booking['tenant_id'] ?? 0);
            $hostName     = $this->fetchUserName($userId);
            $listingTitle = (string) ($booking['listing_title'] ?? '');

            $message = $hostName . ' accepted your booking for "' . $listingTitle . '".' .
                ' The host has signed your official receipt.';
            if ($autoDeclined > 0) {
                /* ONE ACTIVE APPLICATION PER TENANT — tell the guest
                   their other applications were auto-declined (real ₱
                   character, per the dump's notification rows). */
                $message .= ' Your other pending application' .
                    ($autoDeclined > 1 ? 's were' : ' was') .
                    ' automatically declined' .
                    ($autoRefund > 0.005
                        ? ' and ₱' . number_format($autoRefund, 2) . ' refunded to your wallet'
                        : '') . '.';
            }
            $link    = '/booking/details/' . $bookingId;

            if ($guestId <= 0) {
                throw new \RuntimeException('accept-booking: tenant_id missing for booking ' . $bookingId);
            }

            $this->db->insert('notifications', [
                'user_id'    => $guestId,
                'message'    => mb_substr($message, 0, 240),
                'link'       => $link,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('accept-booking guest notification failed: ' . $e->getMessage());
        }

        /* -----------------------------------------------------
           NOTIFY EACH AFFECTED HOST (after the COMMIT; isolated the
           same way). Their applicant was accepted for another stay,
           so the application disappeared from their Pending Tenants
           page — tell them why instead of going silent.
        ----------------------------------------------------- */
        try {
            if ($autoHosts !== []) {
                $guestName = $this->fetchUserName((int) ($booking['tenant_id'] ?? 0));

                foreach ($autoHosts as $affected) {
                    if ((int) $affected['host_id'] <= 0) {
                        continue;
                    }
                    $hostMessage = $guestName . ' was accepted for another stay, so their application for "' .
                        $affected['title'] . '" was automatically declined.';

                    $this->db->insert('notifications', [
                        'user_id'    => (int) $affected['host_id'],
                        'message'    => mb_substr($hostMessage, 0, 240),
                        'link'       => '/host/pending-tenants',
                        'is_read'    => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('accept-booking auto-decline host notification failed: ' . $e->getMessage());
        }

        /* -----------------------------------------------------
           NOTIFY EACH DECLINED SAME-LISTING RIVAL (after the
           COMMIT; isolated the same way). Their application for
           this space was declined in full because another guest
           was confirmed for it — tell them why and that their
           refund is already in their wallet. The listing becomes
           visible to them again (rejected bookings never hide it)
           with the confirmed dates disabled for everyone.
        ----------------------------------------------------- */
        try {
            if ($rivalNotices !== []) {
                $rivalHostName = $this->fetchUserName($userId);
                $rivalTitle    = (string) ($booking['listing_title'] ?? '');

                foreach ($rivalNotices as $notice) {
                    if ((int) $notice['user_id'] <= 0) {
                        continue;
                    }
                    $rivalMessage = $rivalHostName . ' accepted another guest for "' .
                        $rivalTitle . '", so your booking request was automatically declined.';
                    if ((float) $notice['refund'] > 0.005) {
                        /* Real ₱ character, per the dump's notification rows. */
                        $rivalMessage .= ' Your ₱' . number_format((float) $notice['refund'], 2) .
                            ' refund has been credited to your wallet.';
                    }

                    $this->db->insert('notifications', [
                        'user_id'    => (int) $notice['user_id'],
                        'message'    => mb_substr($rivalMessage, 0, 240),
                        'link'       => '/booking/details/' . (int) $notice['booking'],
                        'is_read'    => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('accept-booking rival notification failed: ' . $e->getMessage());
        }

        return $this->json(['success' => true]);
    }

    /**
     * Host display name for the guest notification — the DB is the
     * source of truth (legacy read $_SESSION['user_name'], which the
     * port doesn't seed at login; same fallback rule as
     * ProcessPaymentController::fetchUserName).
     */
    private function fetchUserName(int $userId): string
    {
        try {
            $name = $this->db->fetchOne('SELECT name FROM users WHERE id = :id', ['id' => $userId]);
            return ($name === false || (string) $name === '') ? 'The host' : (string) $name;
        } catch (\Throwable) {
            return 'The host';
        }
    }
}
