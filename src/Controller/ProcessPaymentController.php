<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — PROCESS PAYMENT (Inquiry Step 2 submit target).
 *
 *   POST /booking/process-payment   → 1:1 port of legacy
 *                                     booking/process-payment.php
 *                                     (route name: app_payment_process).
 *                                     This is the exact URL the hidden
 *                                     fields of listings/payment.html.twig
 *                                     already POST to (that form was
 *                                     delivered with this TODO documented
 *                                     in PaymentController — this class
 *                                     fills it).
 *
 * Flow A (new inquiry):  detail → /listings/{id}/payment → HERE(stage=review)
 *                        → mock screen → HERE(stage=confirm) → receipt
 * Flow B (pay balance):  payments history → /listings/{id}/payment?pay_balance=N
 *                        → HERE(stage=review) → mock screen → HERE(stage=confirm)
 *                        → receipt
 * Flow C (owner "List Now"): detail → /listings/{id}/payment → HERE(review)
 *                        → mock screen → HERE(confirm) → PUBLISHED (listed=1)
 *
 * === MONTHLY RENTAL + LONG TERM ===
 * - 50% RESERVE REMOVED (product change): every reservation pays
 *   the FULL total up front (payment_status = 'paid' on insert).
 * - LONG TERM (long_term=1): open-ended month-to-month; duration forced to
 *   1 → total = ONE month's price, paid in full. checkout_date is stored
 *   NULL, which makes the space unavailable to everyone else (open-ended
 *   occupancy).
 *
 * === WALLET PAYMENT METHOD (port of pp_wallet_charge) ===
 * Legacy debited a `wallets` table; THIS PORT uses users.wallet_balance —
 * the same column PaymentController::walletCharge() and WalletController
 * already use (documented port decision, no schema change). The debit is a
 * guarded atomic UPDATE (…WHERE wallet_balance >= :amt) executed INSIDE the
 * same transaction as the booking write — double-spend safe, full rollback
 * on failure. Every successful debit also writes a 'spend' row to
 * wallet_transactions (best-effort: a ledger failure never fails a valid
 * payment).
 *
 * === STAGES (legacy $_POST['stage']) ===
 * - stage=review  (default — listings/payment.html.twig posts no stage):
 *   validate + recompute pricing server-side, then render the mock screen
 *   for the chosen method (payment/process.html.twig).
 * - stage=confirm: validate the method fields again, re-check the wallet
 *   server-side, then run the transactional branch (balance / owner /
 *   reservation). Field validation failure re-renders the mock screen with the
 *   legacy error strings and sticky inputs (legacy: $stage='review').
 *
 * Redirect mapping (legacy → this port, URL-for-URL where the target page
 * exists, matching PaymentController's documented table):
 *
 *   auth/loginform.php                        → app_login
 *   booking/listingpayment.php?…              → app_listing_payment
 *                                               (same query string legacy
 *                                               rebuilt for "change method",
 *                                               +&wallet_error=1 after a
 *                                               failed wallet debit)
 *   Listings/listing.php                      → app_listings
 *   Listings/listing-detail.php?id&flag       → app_listing_detail
 *                                               (?alreadylisted=1
 *                                               ?unavailable=1
 *                                               ?incompletedates=1 — all
 *                                               three already render in
 *                                               detail.html.twig;
 *                                               ?listed=1 renders no banner
 *                                               yet — detail.php port gap,
 *                                               harmless)
 *   booking/userbookings.php                  → app_payments
 *   booking/booking-details.php?id=N          → /booking/details/N (TODO URL
 *                                               — 404s until that file is
 *                                               ported, same convention as
 *                                               PaymentController)
 *   booking/payment-confirmation.php?id&…     → app_payment_confirmation
 *                                               (query: paid, balance, full,
 *                                               pm, amt — the receipt action
 *                                               reads balance/pm/amt and
 *                                               ignores the rest)
 *   user/wallet.php (mock-screen top-up link) → app_wallet
 *
 * Icon mapping (legacy /webprogg/images/* → public/images/*, same table
 * PaymentController's $paymentMethods uses):
 *   RoomHiveLogos.png        → asset('images/RoomHiveLogos.png')
 *   GCashIcon.png            → /images/GCashIcon.png
 *   MayaIcon.png             → /images/paymayaicon.png
 *   CardIcon.png             → /images/paymentsicon-userprofile.png
 *   ListingPlaceholder.png   → /images/ListingPlaceholder.png
 *
 * Port record #11 — booking/process-payment.php (this file).
 * Host notifications keep the legacy copy verbatim (accept within 24
 * hours / auto-declined wording included) with links remapped to
 * /host/bookings and /host/pending-tenants.
 */
class ProcessPaymentController extends AbstractController
{
    private const VALID_METHODS = ['wallet', 'gcash', 'maya', 'card'];
    private const GUEST_OPTIONS = ['1', '2', '3', '4+'];

    public function __construct(private readonly Connection $db)
    {
    }

    /* =====================================================================
     |  POST /booking/process-payment — both stages
     | ================================================================== */

    #[Route('/booking/process-payment', name: 'app_payment_process', methods: ['POST'], priority: 2)]
    public function process(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $post = $request->request->all();

        /* -----------------------------------------------------
           INBOUND DATA — same whitelist/validation as legacy
        ----------------------------------------------------- */
        $listingId = isset($post['listing_id']) && is_numeric($post['listing_id'])
            ? (int)$post['listing_id'] : 0;
        $guests        = (string)($post['guests'] ?? '1');
        $paymentMethod = (string)($post['payment_method'] ?? '');
        $stage         = (string)($post['stage'] ?? 'review');
        $paymentPurpose = (($post['payment_purpose'] ?? 'reservation') === 'balance')
            ? 'balance' : 'reservation';
        $bookingId = isset($post['booking_id']) && is_numeric($post['booking_id'])
            ? (int)$post['booking_id'] : null;

        /* 50% RESERVE REMOVED — no payment_purpose_full flag anymore;
           every reservation charges the full total. */

        /* MONTHLY RENTAL — duration recomputed server-side (1-12) */
        $allowedDurations = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        $durationMonths = (int)($post['months'] ?? 1);
        if (!in_array($durationMonths, $allowedDurations, true)) {
            $durationMonths = 1;
        }

        $longTerm = ($post['long_term'] ?? '0') === '1';

        /* LONG TERM — month-to-month: pay is ONE month at a time and
           there is no move-out date, so force duration to 1 month. */
        if ($longTerm) {
            $durationMonths = 1;
        }

        $checkin  = $this->validDate((string)($post['checkin_date'] ?? '')) ?? '';
        $checkout = (!$longTerm)
            ? ($this->validDate((string)($post['checkout_date'] ?? '')) ?? '')
            : '';

        if (!in_array($guests, self::GUEST_OPTIONS, true)) {
            $guests = '1';
        }

        $validMethods = self::VALID_METHODS;
        if (!in_array($paymentMethod, $validMethods, true)) {
            return $this->redirectToRoute('app_listing_payment', ['id' => $listingId]);
        }

        $errors = [];

        /* -----------------------------------------------------
           RELOAD THE LISTING (server-side source of truth)
        ----------------------------------------------------- */
        $listing = $this->db->fetchAssociative(
            "SELECT l.id, l.title, l.price, l.location, l.user_id AS host_id, l.status,
                    p.photo_path AS cover_photo
               FROM listings l
               LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.photo_type = 'cover'
              WHERE l.id = :id
              LIMIT 1",
            ['id' => $listingId]
        );

        if ($listing === false) {
            return $this->redirectToRoute('app_listings');
        }

        /* =========================================================
           MONTHLY PRICING — total = monthly price x months.
           50% RESERVE REMOVED — the full total is due now.
        ========================================================== */
        $isListingOwner = ((int)$listing['host_id'] === $userId);
        $listingStatus  = (string)($listing['status'] ?? '');

        $listingPrice      = (float)$listing['price'];
        $discountedMonthly = $listingPrice;

        /* MONTHLY RENTAL — total rent = monthly x duration
           (Long Term: duration was forced to 1 above, so this is
           exactly ONE month's price). */
        $discountedTotal = round($discountedMonthly * $durationMonths, 2);

        $durationLabel = $longTerm
            ? 'long-term'
            : ($durationMonths === 1 ? '1-month' : $durationMonths . '-month');

        /* -----------------------------------------------------
           LISTING STATUS + DATE GUARDS (reservation flow only)
        ----------------------------------------------------- */
        if ($paymentPurpose === 'reservation') {

            if ($isListingOwner && $listingStatus === 'approved') {
                return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'alreadylisted' => 1]);
            }

            if (!$isListingOwner && $listingStatus !== 'approved') {
                return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'unavailable' => 1]);
            }

            /* VERIFICATION GATE - the detail-page lock can't be
               walked around by posting straight here: an unverified
               renter's reservation POST bounces back to the detail
               page with ?needverify=1 (the notice there explains
               it). Owners are exempt; balance mode never enters
               this block, so paying a remaining balance stays
               open. */
            if (!$isListingOwner && !$this->isUserVerified($userId)) {
                return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'needverify' => 1]);
            }

            if (!$isListingOwner) {
                if ($checkin === '' || (!$longTerm && $checkout === '')) {
                    return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'incompletedates' => 1]);
                }
                if (!$longTerm && $checkout !== '' && $checkout < $checkin) {
                    return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'incompletedates' => 1]);
                }
            }
        }

        /* -----------------------------------------------------
           BALANCE MODE — load + validate the existing booking
           (duration ignored here; b.total already carries it)
        ----------------------------------------------------- */
        $balanceBooking = null;
        $balanceDueNow  = null;

        if ($paymentPurpose === 'balance') {

            if ($bookingId === null) {
                return $this->redirectToRoute('app_payments');
            }

            $balanceBooking = $this->db->fetchAssociative(
                'SELECT id, user_id, listing_id, total, amount_paid, status,
                        checkin_date, checkout_date, guests
                   FROM bookings
                  WHERE id = :id
                  LIMIT 1',
                ['id' => $bookingId]
            );

            $balanceIsValid = $balanceBooking !== false
                && (int)$balanceBooking['user_id'] === $userId
                && (int)$balanceBooking['listing_id'] === $listingId
                && in_array($balanceBooking['status'], ['pending', 'confirmed'], true);

            if (!$balanceIsValid) {
                /* legacy → booking/userbookings.php */
                return $this->redirectToRoute('app_payments');
            }

            $balanceDueNow = round(
                (float)$balanceBooking['total'] - (float)$balanceBooking['amount_paid'],
                2
            );

            if ($balanceDueNow <= 0.005) {
                /* legacy → booking/booking-details.php?id=N — TODO URL,
                   404s until that file is ported */
                return new RedirectResponse('/booking/details/' . $bookingId);
            }

            $checkin  = $this->validDate((string)($balanceBooking['checkin_date'] ?? '')) ?? '';
            $checkout = $this->validDate((string)($balanceBooking['checkout_date'] ?? '')) ?? '';
            $longTerm = ($checkin !== '' && $checkout === '');
            if (in_array((string)($balanceBooking['guests'] ?? ''), self::GUEST_OPTIONS, true)) {
                $guests = (string)$balanceBooking['guests'];
            }
        }

        /* Amount charged on the mock screens */
        $amountDue = $paymentPurpose === 'balance'
            ? $balanceDueNow
            : $discountedTotal;

        /* -----------------------------------------------------
           ROOMHIVE WALLET — read the balance for display + the
           confirm-stage guard. (The authoritative debit happens
           inside the payment transaction via a guarded UPDATE.)
           This port reads users.wallet_balance — same column
           PaymentController / WalletController use.
        ----------------------------------------------------- */
        $walletBalance   = 0.0;
        $walletAvailable = false;

        if ($paymentMethod === 'wallet') {
            try {
                $col = $this->db->fetchOne(
                    'SELECT wallet_balance FROM users WHERE id = :u LIMIT 1',
                    ['u' => $userId]
                );
                $walletBalance   = ($col === false) ? 0.0 : (float)$col;
                $walletAvailable = true;
            } catch (\Throwable $e) {
                error_log('wallet balance read failed: ' . $e->getMessage());
            }
        }

        /* 0.005 absorbs float noise */
        $walletShort = ($paymentMethod === 'wallet')
            && (!$walletAvailable || ($walletBalance + 0.005 < $amountDue));

        /* -----------------------------------------------------
           CHANGE-METHOD URL — computed BEFORE stage 2 so the
           wallet-failure redirects inside the transactions can
           reuse it (user is sent back to the method chooser with
           &wallet_error=1). Same query string legacy rebuilt.
        ----------------------------------------------------- */
        $changeMethodUrl = $this->changeMethodUrl(
            $listingId,
            $paymentPurpose,
            $bookingId,
            $checkin,
            $checkout,
            $guests,
            $durationMonths,
            $longTerm
        );

        /* =========================================================
           STAGE 2 — CONFIRM
        ========================================================== */
        if ($stage === 'confirm') {

            if ($paymentMethod === 'gcash' || $paymentMethod === 'maya') {
                $mobileNumber = trim((string)($post['mobile_number'] ?? ''));
                if (!preg_match('/^09\d{9}$/', $mobileNumber)) {
                    $errors[] = 'Enter a valid 11-digit mobile number starting with 09.';
                }
            }

            if ($paymentMethod === 'card') {
                $cardNumber = preg_replace('/\s+/', '', (string)($post['card_number'] ?? ''));
                $cardName   = trim((string)($post['card_name'] ?? ''));
                $cardExpiry = trim((string)($post['card_expiry'] ?? ''));
                $cardCvv    = trim((string)($post['card_cvv'] ?? ''));

                if (!preg_match('/^\d{16}$/', (string)$cardNumber)) {
                    $errors[] = 'Enter a valid 16-digit card number.';
                }
                if ($cardName === '') {
                    $errors[] = 'Enter the name on the card.';
                }
                if (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $cardExpiry)) {
                    $errors[] = 'Enter expiry as MM/YY.';
                }
                if (!preg_match('/^\d{3,4}$/', $cardCvv)) {
                    $errors[] = 'Enter a valid CVV.';
                }
            }

            /* ROOMHIVE WALLET — no fields to validate, but the
               balance is re-checked server-side right before
               charging. The double-spend-safe check still happens
               inside the payment transaction itself. */
            if ($paymentMethod === 'wallet') {
                if (!$walletAvailable) {
                    $errors[] = 'Your wallet is temporarily unavailable — please choose another payment method.';
                } elseif ($walletBalance + 0.005 < $amountDue) {
                    $errors[] = 'Your wallet balance (₱' . number_format($walletBalance, 2)
                        . ') is not enough for this payment (₱' . number_format($amountDue, 2)
                        . '). Top up your wallet or choose another payment method.';
                }
            }

            if (empty($errors)) {

                if ($paymentPurpose === 'balance') {

                    /* ---------------------------------------------
                       PAY REMAINING BALANCE — b.total already
                       carries the price. months is ignored in
                       this mode.

                       WALLET: debited inside this same
                       transaction — if the debit fails, the
                       balance payment rolls back entirely.
                    --------------------------------------------- */
                    $this->db->beginTransaction();

                    $lockStmt = $this->db->fetchAssociative(
                        'SELECT id, user_id, listing_id, total, amount_paid, status
                           FROM bookings
                          WHERE id = :id
                          FOR UPDATE',
                        ['id' => $bookingId]
                    );

                    $stillValid = $lockStmt !== false
                        && (int)$lockStmt['user_id'] === $userId
                        && (int)$lockStmt['listing_id'] === $listingId
                        && in_array($lockStmt['status'], ['pending', 'confirmed'], true);

                    if (!$stillValid) {
                        $this->db->rollBack();
                        return $this->redirectToRoute('app_payments');
                    }

                    $remainingNow = round(
                        (float)$lockStmt['total'] - (float)$lockStmt['amount_paid'],
                        2
                    );

                    if ($remainingNow <= 0.005) {
                        $this->db->rollBack();
                        return new RedirectResponse('/booking/details/' . $bookingId . '?paid=1');
                    }

                    /* WALLET — guarded atomic debit inside the transaction */
                    if ($paymentMethod === 'wallet') {
                        if (!$this->walletCharge(
                            $userId,
                            $remainingNow,
                            'Booking #' . $bookingId . ' — remaining balance payment'
                        )) {
                            $this->db->rollBack();
                            return new RedirectResponse($changeMethodUrl . '&wallet_error=1');
                        }
                    }

                    $this->db->executeStatement(
                        "UPDATE bookings
                            SET amount_paid = amount_paid + :amount,
                                payment_status = 'paid'
                          WHERE id = :id",
                        ['amount' => $remainingNow, 'id' => $bookingId]
                    );

                    $this->db->commit();

                    /* ===== NOTIFY THE HOST: balance paid ===== */
                    try {
                        $guestName = $this->fetchUserName($userId);

                        $hostInfo = $this->db->fetchAssociative(
                            'SELECT l.user_id AS host_id, l.title
                               FROM listings l
                               JOIN bookings b ON b.listing_id = l.id
                              WHERE b.id = :id
                              LIMIT 1',
                            ['id' => $bookingId]
                        );

                        if ($hostInfo) {
                            $this->notifyHost(
                                (int)$hostInfo['host_id'],
                                $guestName . ' paid the remaining balance (₱'
                                    . number_format($remainingNow, 2) . ') for "'
                                    . $hostInfo['title'] . '". The booking is now fully paid.',
                                '/host/bookings'
                            );
                        }
                    } catch (\Throwable $e) {
                        error_log('balance host notification failed: ' . $e->getMessage());
                    }

                    return $this->redirectToRoute('app_payment_confirmation', [
                        'booking' => $bookingId,
                        'paid'    => 1,
                        'balance' => 1,
                        'pm'      => $paymentMethod,
                        'amt'     => number_format($remainingNow, 2, '.', ''),
                    ]);
                }

                if ($isListingOwner) {

                    /* ---------------------------------------------
                       OWNER "LIST NOW" FLOW

                       WALLET: the listing fee is debited inside
                       this transaction — if the approval update
                       fails, the debit rolls back.
                    --------------------------------------------- */
                    $this->db->beginTransaction();

                    if ($paymentMethod === 'wallet') {
                        if (!$this->walletCharge(
                            $userId,
                            $amountDue,
                            'Listing fee — "' . $listing['title'] . '"'
                        )) {
                            $this->db->rollBack();
                            return new RedirectResponse($changeMethodUrl . '&wallet_error=1');
                        }
                    }

                    $this->db->executeStatement(
                        "UPDATE listings
                            SET status = 'approved'
                          WHERE id = :id AND user_id = :user_id",
                        ['id' => $listingId, 'user_id' => $userId]
                    );

                    $this->db->commit();

                    return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'listed' => 1]);
                }

                /* ---------------------------------------------
                   NEW RESERVATION — race-safe.
                   Lock the listing row, then insert with
                   bookings.total = monthly x months
                   (ONE month for Long Term).

                   50% RESERVE REMOVED: amount_paid = the FULL
                   total (payment_status 'paid' on insert).

                   LONG TERM: checkout_date is inserted NULL —
                   the open-ended row is what makes the space
                   disappear for everyone else.

                   WALLET: debited inside this same
                   transaction, AFTER the conflict checks and
                   BEFORE the insert — if the debit fails,
                   nothing is written and the user is sent
                   back to the method chooser.
                --------------------------------------------- */
                $this->db->beginTransaction();

                $discountedTotal = round($listingPrice * $durationMonths, 2);

                /* FULL PAYMENT — charge the whole total now. */
                $amountPaidNow = $discountedTotal;

                /* Lock the listing row — serializes concurrent reservations */
                $lockedListing = $this->db->fetchAssociative(
                    'SELECT id, status FROM listings WHERE id = :id FOR UPDATE',
                    ['id' => $listingId]
                );

                if (!$lockedListing || $lockedListing['status'] !== 'approved') {
                    $this->db->rollBack();
                    return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'unavailable' => 1]);
                }

                /* Conflict check — open-ended stays (NULL checkout,
                   i.e. Long Term) are treated as ending 9999-12-31,
                   so they block any move-in from their start onward. */
                $conflict = $this->db->fetchOne(
                    "SELECT 1
                       FROM bookings
                      WHERE listing_id = :listing_id
                        AND status IN ('pending', 'confirmed')
                        AND checkin_date IS NOT NULL
                        AND :checkin <= COALESCE(checkout_date, '9999-12-31')
                        AND :range_end >= checkin_date
                      LIMIT 1",
                    [
                        'listing_id' => $listingId,
                        'checkin'    => $checkin,
                        'range_end'  => $checkout !== '' ? $checkout : '9999-12-31',
                    ]
                );

                if ($conflict !== false) {
                    $this->db->rollBack();
                    return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'unavailable' => 1]);
                }

                /* ONE ACTIVE APPLICATION PER TENANT - a renter with a
                   still-pending booking request anywhere cannot start
                   another one (same rule as payment/confirm, and the
                   accept flow auto-declines the tenant's others when
                   one is accepted). Runs INSIDE the transaction,
                   BEFORE any money moves. */
                $ownPending = $this->db->fetchOne(
                    "SELECT id FROM bookings
                      WHERE user_id = :user_id AND status = 'pending'
                      LIMIT 1",
                    ['user_id' => $userId]
                );
                if ($ownPending !== false) {
                    $this->db->rollBack();
                    return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'oneactive' => 1]);
                }

                /* WALLET — guarded atomic debit inside the transaction */
                if ($paymentMethod === 'wallet') {
                    if (!$this->walletCharge(
                        $userId,
                        $amountPaidNow,
                        'Reservation for "' . $listing['title'] . '" — full payment'
                    )) {
                        $this->db->rollBack();
                        return new RedirectResponse($changeMethodUrl . '&wallet_error=1');
                    }
                }

                $roomTotal = $discountedTotal; /* total stores the price */

                $this->db->executeStatement(
                    "INSERT INTO bookings
                        (listing_id, user_id, total, amount_paid, status, booked_at,
                         payment_status, checkin_date, checkout_date, guests)
                     VALUES
                        (:listing_id, :user_id, :total, :amount_paid, 'pending', NOW(),
                         'paid', :checkin_date, :checkout_date, :guests)",
                    [
                        'listing_id'    => $listingId,
                        'user_id'       => $userId,
                        'total'         => $roomTotal,
                        'amount_paid'   => $amountPaidNow,
                        'checkin_date'  => $checkin ?: null,
                        'checkout_date' => $checkout ?: null, /* NULL for Long Term */
                        'guests'        => $guests,
                    ]
                );

                $newBookingId = (int)$this->db->lastInsertId();

                $this->db->commit();

                /* ===== NOTIFY THE HOST: new full-payment reservation ===== */
                try {
                    $guestName = $this->fetchUserName($userId);

                    $this->notifyHost(
                        (int)$listing['host_id'],
                        $guestName . ' applied for "' . $listing['title']
                            . '" (' . $durationLabel . ' stay) and '
                            . 'paid in full'
                            . ' (₱' . number_format($amountPaidNow, 2) . ').'
                            . ' Accept within 24 hours or it is auto-declined.',
                        '/host/pending-tenants'
                    );
                } catch (\Throwable $e) {
                    error_log('new booking host notification failed: ' . $e->getMessage());
                }

                return $this->redirectToRoute('app_payment_confirmation', [
                    'booking' => $newBookingId,
                    'paid'    => 1,
                    'pm'      => $paymentMethod,
                    'amt'     => number_format($amountPaidNow, 2, '.', ''),
                ]);
            }

            /* Validation failed — re-render Stage 1 with errors */
            $stage = 'review';
        }

        /* -----------------------------------------------------
           MOCK SCREEN CONTEXT (legacy's top-of-page variables)
        ----------------------------------------------------- */
        if ($isListingOwner && $paymentPurpose === 'reservation') {
            $summaryMeta = 'One-time listing fee &middot; publishes your space';
        } elseif ($checkin !== '' && $checkout !== '') {
            $summaryMeta = date('M j, Y', strtotime($checkin)) . ' &rarr; ' . date('M j, Y', strtotime($checkout))
                . ' &middot; ' . htmlspecialchars($durationLabel, ENT_QUOTES, 'UTF-8') . ' stay'
                . ' &middot; ' . htmlspecialchars($guests, ENT_QUOTES, 'UTF-8') . ' guest' . ($guests === '1' ? '' : 's');
        } elseif ($checkin !== '' && $longTerm) {
            $summaryMeta = 'Move-in ' . date('M j, Y', strtotime($checkin)) . ' &middot; Long Term (month-to-month)';
        } else {
            $summaryMeta = htmlspecialchars((string)$listing['location'], ENT_QUOTES, 'UTF-8');
        }

        if ($paymentPurpose === 'balance') {
            $chargeLabel = 'Balance to Pay';
        } elseif ($isListingOwner) {
            $chargeLabel = 'Listing Fee';
        } elseif ($longTerm) {
            $chargeLabel = 'First Month — month-to-month';
        } else {
            $chargeLabel = 'Full Payment — ' . $durationLabel . ' stay';
        }

        /* Mock-screen hint under the GCash / Maya mobile field — the long
           conditional legacy echoed inline. Wallet/card screens show no
           hint (wallet has its own pp-hint copy, card has none). */
        $mockHint = '';
        if ($paymentMethod === 'gcash' || $paymentMethod === 'maya') {
            $mockHint = $longTerm
                ? 'This pays your ENTIRE first month now. The host still has 24 hours to accept — if declined, the full amount is refunded to your RoomHive wallet. Succeeding months are settled with your host.'
                : 'This full payment settles your entire ' . $durationLabel . ' stay now. The host still has 24 hours to accept — if declined, the full amount is refunded to your RoomHive wallet.';
        }

        /* Sticky card/mobile values (legacy echoed h($_POST[...])) — Twig
           autoescapes these in the template. */
        $sticky = [
            'mobile_number' => (string)($post['mobile_number'] ?? ''),
            'card_number'   => (string)($post['card_number'] ?? ''),
            'card_name'     => (string)($post['card_name'] ?? ''),
            'card_expiry'   => (string)($post['card_expiry'] ?? ''),
            'card_cvv'      => (string)($post['card_cvv'] ?? ''),
        ];

        return $this->render('payment/process.html.twig', [
            'listing'            => $listing,
            'cover_photo'        => $this->resolvePhoto(
                (string)($listing['cover_photo'] ?? ''),
                '/images/ListingPlaceholder.png'
            ),
            'summary_meta_html'  => $summaryMeta,
            'charge_label'       => $chargeLabel,
            'mock_hint'          => $mockHint,
            'errors'             => $errors,
            'amount_due'         => $amountDue,
            'is_listing_owner'   => $isListingOwner,
            'payment_purpose'    => $paymentPurpose,
            'payment_method'     => $paymentMethod,
            'booking_id'         => $bookingId ?? 0,
            'checkin'            => $checkin,
            'checkout'           => $checkout,
            'guests'             => $guests,
            'months'             => $durationMonths,
            'long_term'          => $longTerm,
            'wallet_balance'     => $walletBalance,
            'wallet_after'       => round(max(0, $walletBalance - $amountDue), 2),
            'wallet_short'       => $walletShort,
            'change_method_url'  => $changeMethodUrl,
            'sticky'             => $sticky,
        ]);
    }

    /* =====================================================================
     |  PRIVATE HELPERS
     | ================================================================== */

    /** Strict Y-m-d validation (legacy pp_valid_date) — normalized value or null. */
    private function validDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }

    /** Legacy is_user_verified(): at least one 'verified' (or
        legacy 'approved') row in user_id_documents - the same
        rule ProfileController uses for the Verified badge on the
        profile page. try/catch: a missing table = unverified. */
    private function isUserVerified(int $userId): bool
    {
        try {
            return (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM user_id_documents
                  WHERE user_id = :u AND status IN ('verified', 'approved')",
                ['u' => $userId]
            ) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * ROOMHIVE WALLET — charge helper (port of pp_wallet_charge).
     * MUST be called inside an active transaction. The debit is a single
     * guarded UPDATE on users.wallet_balance: it only succeeds when the
     * balance still covers the amount, making it double-spend safe without
     * a separate SELECT ... FOR UPDATE round-trip. Returns true on success,
     * false on insufficient funds (or if the column is unavailable — the
     * caller rolls back). Every successful debit also writes a 'spend' row
     * to wallet_transactions (best-effort: a ledger failure never fails an
     * otherwise valid payment — same columns WalletController writes).
     */
    private function walletCharge(int $userId, float $amount, string $description): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return false;
        }

        try {
            /* Atomic guarded debit */
            $updated = $this->db->executeStatement(
                'UPDATE users
                    SET wallet_balance = wallet_balance - :amt
                  WHERE id = :u AND wallet_balance >= :amt',
                ['amt' => $amount, 'u' => $userId]
            );

            if ($updated === 0) {
                return false; /* short on funds (or no wallet row) */
            }

            $balance = (float)$this->db->fetchOne(
                'SELECT wallet_balance FROM users WHERE id = :u',
                ['u' => $userId]
            );
        } catch (\Throwable $e) {
            error_log('wallet debit failed: ' . $e->getMessage());
            return false;
        }

        /* Ledger entry — best-effort, never fails the payment */
        try {
            $this->db->insert('wallet_transactions', [
                'user_id'       => $userId,
                'reference'     => 'WH' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2))),
                'type'          => 'spend',
                'amount'        => $amount,
                'balance_after' => $balance,
                'description'   => mb_substr((string)$description, 0, 240),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('wallet transaction log failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Host notification (port of pp_notify_host). try/catch around the
     * insert — a notifications problem never fails an otherwise valid
     * payment. Legacy link /webprogg/host/hostbookings.php → /host/bookings,
     * /webprogg/booking/pendingtenants.php → /host/pending-tenants.
     */
    private function notifyHost(int $hostId, string $message, string $link): bool
    {
        try {
            if ($hostId <= 0) {
                return false;
            }
            $this->db->insert('notifications', [
                'user_id'    => $hostId,
                'message'    => mb_substr($message, 0, 240),
                'link'       => $link,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('pp_notify_host failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Guest display name for the host notifications (legacy $_SESSION['user_name']). */
    private function fetchUserName(int $userId): string
    {
        try {
            $name = $this->db->fetchOne('SELECT name FROM users WHERE id = :id', ['id' => $userId]);
            return ($name === false || (string)$name === '') ? 'A guest' : (string)$name;
        } catch (\Throwable) {
            return 'A guest';
        }
    }

    /**
     * Change-method URL — rebuilt like legacy (50% reserve removed,
     * so there is no &pay_full=1 variant anymore):
     *   balance  → /listings/{id}/payment?pay_balance={bookingId}
     *   else     → /listings/{id}/payment?checkin_date=…&checkout_date=…&guests=…&months=N
     *              [&long_term=1]
     * (listingpayment.php remapped onto app_listing_payment; keep legacy's
     * param names — the chooser page reads exactly these.)
     */
    private function changeMethodUrl(
        int $listingId,
        string $paymentPurpose,
        ?int $bookingId,
        string $checkin,
        string $checkout,
        string $guests,
        int $durationMonths,
        bool $longTerm
    ): string {
        $base = $this->generateUrl('app_listing_payment', ['id' => $listingId]);

        if ($paymentPurpose === 'balance') {
            return $base
                . '?pay_balance=' . rawurlencode((string)$bookingId);
        }

        /* Keep duration + long-term when switching method. */
        return $base
            . '?checkin_date=' . rawurlencode($checkin)
            . '&checkout_date=' . rawurlencode($checkout)
            . '&guests=' . rawurlencode($guests)
            . '&months=' . rawurlencode((string)$durationMonths)
            . ($longTerm ? '&long_term=1' : '');
    }

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same mapping PaymentController uses
     *  (http(s) stays as-is, legacy 'webprogg/' prefixes are stripped and
     *  bare filenames land under public/uploads/listing_photos/).
     * ================================================================= */

    private function resolvePhoto(string $path, string $fallback = '/images/ListingPlaceholder.png'): string
    {
        $path = trim($path);

        if ($path === '') {
            return $fallback;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path; /* external URL — works as-is */
        }

        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        /* Strip legacy project prefixes — the files now live
           directly under public/uploads/ */
        if (stripos($path, 'webprogg/') === 0) {
            $path = substr($path, strlen('webprogg/'));
        }
        if (stripos($path, 'host/') === 0) {
            $path = substr($path, strlen('host/'));
        }

        /* Whatever remains is either uploads/... or a bare filename */
        if (stripos($path, 'uploads/') === 0) {
            return '/' . $path;
        }
        if (stripos($path, 'cover_') === 0) {
            return '/uploads/listing_photos/cover/' . basename($path);
        }

        return '/uploads/listing_photos/' . $path;
    }
}
