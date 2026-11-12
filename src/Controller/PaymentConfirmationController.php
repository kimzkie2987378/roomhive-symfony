<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Payment Confirmation (Step 3 of the booking flow) — Official Receipt.
 *
 * 1:1 port of legacy booking/payment-confirmation.php.
 *
 *   GET  /payment/confirmation/{booking}  (route app_payment_confirmation,
 *                                          requirements booking = '\d+')
 *
 * Query string (display-only, never trusted for totals):
 *   ?amt=<float>   — amount just paid, clamped to [0, amount_paid].
 *                    Absent → defaults to the booking's amount_paid.
 *   ?pm=<gcash|maya|card|wallet|...>
 *                  — payment method label. Legacy maps only gcash/maya/card
 *                    to friendly names; everything else (including 'wallet')
 *                    falls through to 'Online Payment'.
 *   ?balance=<any> — presence test: marks this visit as a balance-payment
 *                    receipt (changes the success strip + item name).
 *
 * ACCESS: booking's guest (b.user_id) OR listing's host (l.user_id) only.
 * Everyone else is bounced to app_payments.
 *
 * STAMP LOGIC (matches the legacy):
 *   - amount_paid >= total          → "Fully Paid"     (green)
 *   - amount_paid > 0 but < total   → "Advance Paid"   (amber)
 *   - amount_paid == 0              → "Unpaid"          (amber, treated
 *                                       as a partial-style stamp by legacy)
 *
 * HOST SIGNATURE:
 *   - If public/uploads/signatures/booking-{id}.png exists, the receipt
 *     shows "Signed & Accepted by Host" + the .png + the date from the
 *     matching .json (signed_at field, falling back to file mtime).
 *   - If unsigned AND the viewer is the host AND the booking is pending
 *     or confirmed, an inline signing pad renders (host only) — its JS
 *     posts to two TODO endpoints (see below), 404s until those legacy
 *     files are ported (same convention as ProcessPaymentController).
 *
 * AUTO-REDIRECT: a 10s countdown takes guests to app_payments; host
 * viewers get no countdown (the success strip says "Guest Payment
 * Receipt" and the primary button becomes "Back to Pending Tenants").
 *
 * Endpoint contract for the signing pad JS:
 *     POST /booking/sign-receipt    — TODO URL (legacy
 *                                     booking/sign-receipt.php, not
 *                                     ported yet): saves the host's
 *                                     drawn signature PNG + JSON
 *                                     sidecar. 404s until ported.
 *     POST /booking/accept-booking  — PORTED (AcceptBookingController,
 *                                     route app_booking_accept):
 *                                     confirms the booking + pays out
 *                                     the host's 97% share; only called
 *                                     when the booking is still pending
 *                                     at sign time.
 *
 * TODO URL (plain string, 404s until ported — never a render-time 500,
 *   same convention as ProcessPaymentController documents):
 *     GET  /booking/details/{id}    — legacy booking/booking-details.php
 *                                     (the "View Booking Details" link)
 *
 * Branding: the receipt uses the real logo image
 *   {{ asset('images/RoomHiveLogos.png') }}
 * (navbar brand + receipt store header). The signature storage
 * path resolves at runtime via $this->getParameter('kernel.project_dir')
 * — scalar constructor args are NOT autowired by Symfony, so the
 * project dir is fetched in the action instead of injected.
 *
 * Caller contract — these redirects already point here:
 *   PaymentController::confirm()             → app_payment_confirmation
 *     [booking, amt, pm, balance?]              (balance mode)
 *     [booking, amt, pm]                        (booking/fee mode)
 *   ProcessPaymentController::process()       → app_payment_confirmation
 *     [booking, paid, balance, pm, amt]         (balance mode)
 *     [booking, paid, pm, full, amt]            (reservation mode)
 *   (The 'paid' and 'full' params are passed by callers but NOT consumed
 *    by this port — the legacy file ignores them too.)
 *
 * Port record — booking/payment-confirmation.php (this file).
 */
final class PaymentConfirmationController extends AbstractController
{
    /**
     * Map payment method slugs → friendly labels (1:1 with legacy).
     * Note: 'wallet' is intentionally NOT here — legacy falls through
     * to 'Online Payment' for it, and we match that exactly.
     */
    private const PAYMENT_METHOD_LABELS = [
        'gcash' => 'GCash',
        'maya'  => 'Maya',
        'card'  => 'Credit / Debit Card',
    ];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /* =====================================================================
     |  GET /payment/confirmation/{booking}  →  official receipt
     | ================================================================== */
    #[Route('/payment/confirmation/{booking}', name: 'app_payment_confirmation', methods: ['GET'], priority: 2, requirements: ['booking' => '\d+'])]
    public function __invoke(Request $request, int $booking): Response
    {
        $session = $request->getSession();
        $userId  = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* -----------------------------------------------------
           RESOLVE BOOKING (server-side source of truth)
        ----------------------------------------------------- */
        $bookingId = $booking;
        $row = $this->db->fetchAssociative(
            "SELECT b.id, b.user_id, b.total, b.amount_paid, b.status,
                    b.checkin_date, b.checkout_date, b.guests,
                    b.booked_at, b.paid_at,
                    l.id AS listing_id, l.title, l.location, l.exact_address,
                    l.property_type, l.user_id AS host_id,
                    u.name AS host_name, u.avatar_path AS host_avatar,
                    gu.name AS guest_name,
                    p.photo_path AS cover_photo
               FROM bookings b
               JOIN listings l ON l.id = b.listing_id
               JOIN users u ON u.id = l.user_id
               JOIN users gu ON gu.id = b.user_id
               LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.photo_type = 'cover'
              WHERE b.id = :id
              LIMIT 1",
            ['id' => $bookingId]
        );

        /* ACCESS: guest who owns the booking OR host who owns the listing. */
        $isGuestViewer = $row !== false && (int) ($row['user_id'] ?? 0) === $userId;
        $isHostViewer  = $row !== false && (int) ($row['host_id']  ?? 0) === $userId;

        if ($row === false || (!$isGuestViewer && !$isHostViewer)) {
            return $this->redirectToRoute('app_payments');
        }

        /* Host-only view = host opened the guest's receipt. */
        $isHostOnlyView = ($isHostViewer && !$isGuestViewer);

        /* -----------------------------------------------------
           QUERY STRING (display-only)
        ----------------------------------------------------- */
        $wasBalancePayment = $request->query->has('balance');

        $pmRaw  = (string) $request->query->get('pm', '');
        $pmLabel = self::PAYMENT_METHOD_LABELS[$pmRaw] ?? 'Online Payment';

        /* -----------------------------------------------------
           FIGURES
        ----------------------------------------------------- */
        $total      = (float) ($row['total']      ?? 0);
        $amountPaid = (float) ($row['amount_paid'] ?? 0);
        $remaining  = round($total - $amountPaid, 2);

        $justPaidRaw = $request->query->get('amt');
        $justPaidRaw = is_numeric($justPaidRaw) ? (float) $justPaidRaw : null;
        $justPaid = $justPaidRaw !== null
            ? min(max(0.0, $justPaidRaw), $amountPaid)
            : $amountPaid;

        $checkin    = $row['checkin_date']  ?? null;
        $checkout   = $row['checkout_date'] ?? null;
        $isLongTerm = ($checkin !== null && $checkout === null);

        $isFullyPaid      = ($remaining <= 0.005);
        $hasAnyPayment    = ($amountPaid > 0.005);
        $isAdvancePayment = (!$isFullyPaid && $hasAnyPayment);

        /* -----------------------------------------------------
           RECEIPT FIELDS
        ----------------------------------------------------- */
        $paidAt   = !empty($row['paid_at']) ? $row['paid_at'] : ($row['booked_at'] ?? '');
        $paidAtTs = $paidAt !== '' ? (strtotime($paidAt) ?: time()) : time();
        $receiptNo = 'RH-' . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT)
            . '-' . date('ymd', $paidAtTs);

        $paidDate = date('M j, Y', $paidAtTs);
        $paidTime = date('g:i A',  $paidAtTs);

        /* Payer from DB — correct even when the HOST is viewing. */
        $payerName = !empty($row['guest_name'])
            ? (string) $row['guest_name']
            : ((string) ($session->get('user_name') ?? 'Guest'));

        /* -----------------------------------------------------
           HOST SIGNATURE — saved by /booking/sign-receipt (TODO)
        ----------------------------------------------------- */
        $sigDir  = ((string) $this->getParameter('kernel.project_dir')) . '/public/uploads/signatures';
        $sigFile = $sigDir . '/booking-' . $bookingId . '.png';
        $sigJson = $sigDir . '/booking-' . $bookingId . '.json';

        $hostSigned = is_file($sigFile);
        $sigUrl     = '';
        $sigDate    = '';

        if ($hostSigned) {
            $sigMtime = (int) filemtime($sigFile);
            $sigTs    = $sigMtime;
            if (is_file($sigJson)) {
                $j = json_decode((string) file_get_contents($sigJson), true);
                if (!empty($j['signed_at'])) {
                    $t = strtotime((string) $j['signed_at']);
                    if ($t) {
                        $sigTs = $t;
                    }
                }
            }
            $sigUrl  = '/uploads/signatures/booking-' . $bookingId . '.png?v=' . $sigMtime;
            $sigDate = date('M j, Y \a\t g:i A', $sigTs);
        }

        /* Host can sign right on this receipt while it is unsigned. */
        $isPendingBooking = ((string) ($row['status'] ?? '')) === 'pending';
        $canHostSign = ($isHostViewer && !$hostSigned
            && in_array((string) ($row['status'] ?? ''), ['pending', 'confirmed'], true));

        /* -----------------------------------------------------
           PRE-COMPUTED DISPLAY STRINGS
        ----------------------------------------------------- */

        /* STAMP — "Fully Paid" (green) or "Advance Paid" (amber).
           Legacy also stamps "Unpaid" with the partial style when
           nothing has been paid at all — match that 1:1. */
        if ($isFullyPaid) {
            $stampText  = 'Fully Paid';
            $stampClass = '';
        } elseif ($hasAnyPayment) {
            $stampText  = 'Advance Paid';
            $stampClass = 'rc-stamp-partial';
        } else {
            $stampText  = 'Unpaid';
            $stampClass = 'rc-stamp-partial';
        }

        /* Caption under the stamp. */
        if ($isFullyPaid) {
            $paymentStatusLine = 'ALL &#8369;' . number_format($total, 2) . ' SETTLED';
        } elseif ($isAdvancePayment) {
            $paymentStatusLine = '&#8369;' . number_format($amountPaid, 2)
                . ' PAID &middot; &#8369;' . number_format($remaining, 2) . ' LEFT TO PAY';
        } else {
            $paymentStatusLine = 'NO PAYMENT YET &mdash; &#8369;' . number_format($remaining, 2) . ' LEFT';
        }

        /* Success strip. */
        if ($isHostOnlyView) {
            $successTitle = 'Guest Payment Receipt';
            $successText  = 'Official receipt issued to ' . $payerName . ' for this booking.';
        } elseif ($isFullyPaid) {
            $successTitle = $wasBalancePayment ? 'Balance Settled!' : 'Fully Paid!';
            $successText  = $wasBalancePayment
                ? 'The booking is now fully paid.'
                : 'This booking is fully paid.';
        } elseif ($isAdvancePayment) {
            $successTitle = 'Advance Payment Received!';
            $successText  = '&#8369;' . number_format($justPaid, 2)
                . ' advance payment received &mdash; balance left: &#8369;'
                . number_format($remaining, 2) . '.';
        } else {
            $successTitle = 'Payment Successful!';
            $successText  = 'Your official receipt is below.';
        }

        /* Payment Type row in the receipt meta. */
        if ($isFullyPaid) {
            $paymentTypeLabel = 'Fully Paid';
        } elseif ($isAdvancePayment) {
            $paymentTypeLabel = 'Advance Paid (Partial)';
        } else {
            $paymentTypeLabel = 'Not Paid Yet';
        }

        /* Line item (reservation vs balance). */
        if ($wasBalancePayment) {
            $itemName = 'Balance Payment';
            $itemDesc = 'Settlement of remaining balance for booking #'
                . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT);
            $nowLabel = 'Paid This Transaction';
        } else {
            $itemName = 'Reservation Fee';
            $itemDesc = 'Advance payment to lock in your booking inquiry';
            $nowLabel = 'Advance Payment (Now)';
        }

        /* Date range appended to the item description. */
        if ($checkin && $checkout) {
            $dateRangeText = ' &middot; ' . date('M j', strtotime((string) $checkin))
                . ' &rarr; ' . date('M j, Y', strtotime((string) $checkout));
        } elseif ($isLongTerm) {
            $dateRangeText = ' &middot; Move-in ' . date('M j, Y', strtotime((string) $checkin)) . ' &middot; Long Term';
        } else {
            $dateRangeText = '';
        }

        /* Footer thank-you line. */
        if ($isAdvancePayment) {
            $thanksLine = 'This receipt is proof of your advance payment &mdash; please show it to your host.';
        } else {
            $thanksLine = 'This receipt serves as proof of your payment.';
        }

        /* "Host will see" sentence (guest view only, may be empty). */
        if ($isHostOnlyView) {
            $hostSeeLine = '';
        } elseif ($isAdvancePayment) {
            $hostSeeLine = 'They will see your &#8369;' . number_format($amountPaid, 2) . ' advance payment on this booking.';
        } elseif ($isFullyPaid && $hasAnyPayment) {
            $hostSeeLine = "They'll see this booking is already fully paid.";
        } else {
            $hostSeeLine = '';
        }

        /* Stay line. */
        if ($checkin && $checkout) {
            $stayLine = 'Your stay runs ' . date('M j', strtotime((string) $checkin))
                . ' &rarr; ' . date('M j, Y', strtotime((string) $checkout)) . '.';
        } elseif ($isLongTerm) {
            $stayLine = 'Your long-term stay starts ' . date('l, F j, Y', strtotime((string) $checkin)) . '.';
        } else {
            $stayLine = 'Your dates are locked in once the host accepts.';
        }

        /* Visibility flags — passed through to Twig. */
        $showCountdown      = !$isHostOnlyView;
        $showNextSteps      = !$isHostOnlyView;
        $showDetailsLink    = !$isHostOnlyView;
        $showPreviouslyPaid = (($amountPaid - $justPaid) > 0.005);
        $showBalanceBox     = !$isFullyPaid;
        $showSettleStep     = (!$isFullyPaid && !$isHostOnlyView);
        $enjoyNum           = $isFullyPaid ? '2' : '3';

        /* Primary action button. */
        $primaryAction = $isHostOnlyView
            ? ['url' => $this->generateUrl('app_host_pending_tenants'), 'label' => 'Back to Pending Tenants']
            : ['url' => $this->generateUrl('app_payments'),              'label' => 'Go to My Bookings'];

        /* -----------------------------------------------------
           RENDER
        ----------------------------------------------------- */
        return $this->render('payment/confirmation.html.twig', [
            'booking' => [
                'id'           => (int) $row['id'],
                'title'        => (string) $row['title'],
                'location'     => (string) $row['location'],
                'guests'       => (string) ($row['guests'] ?? '1'),
                'host_name'    => (string) $row['host_name'],
                'guest_name'   => (string) $row['guest_name'],
                'status'       => (string) $row['status'],
            ],

            'receipt_no'    => $receiptNo,
            'paid_date'     => $paidDate,
            'paid_time'     => $paidTime,
            'pm_label'      => $pmLabel,
            'payment_type'  => $paymentTypeLabel,
            'payer_name'    => $payerName,

            'total'           => $total,
            'amount_paid'     => $amountPaid,
            'remaining'       => $remaining,
            'just_paid'       => $justPaid,
            'previously_paid' => $amountPaid - $justPaid,

            'is_long_term'        => $isLongTerm,
            'is_pending_booking'  => $isPendingBooking,

            'stamp_text'         => $stampText,
            'stamp_class'         => $stampClass,
            'payment_status_line' => $paymentStatusLine,

            'success_title' => $successTitle,
            'success_text'  => $successText,

            'item_name'    => $itemName,
            'item_desc'    => $itemDesc,
            'now_label'    => $nowLabel,
            'date_range'   => $dateRangeText,
            'thanks_line'  => $thanksLine,
            'host_see'     => $hostSeeLine,
            'stay_line'    => $stayLine,

            'show_countdown'      => $showCountdown,
            'show_next_steps'     => $showNextSteps,
            'show_details_link'   => $showDetailsLink,
            'show_previously_paid' => $showPreviouslyPaid,
            'show_balance_box'    => $showBalanceBox,
            'show_settle_step'    => $showSettleStep,
            'enjoy_num'           => $enjoyNum,

            'primary_action' => $primaryAction,

            /* Signature */
            'host_signed'   => $hostSigned,
            'sig_url'        => $sigUrl,
            'sig_date'       => $sigDate,
            'can_host_sign'  => $canHostSign,

            /* Booking-id echo for the signing pad JS. */
            'booking_id'     => $bookingId,
        ]);
    }
}
