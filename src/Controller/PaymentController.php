<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    private const USER_REDIRECT = '/';

    private const CSRF_CHOOSE  = 'pay_choose';
    private const CSRF_CONFIRM = 'pay_confirm';

    private const VALID_METHODS = ['wallet', 'gcash', 'maya', 'card'];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /* =========================================================
       STAGE 1 — PAYMENT METHOD CHOOSER
       GET /listings/{id}/payment
       Reservation mode (default) AND balance mode
       (?pay_balance={bookingId} — Flow B).
    ========================================================== */
    #[Route('/listings/{id}/payment', name: 'app_listing_payment', methods: ['GET'])]
    public function choose(Request $request, int $id): Response
    {
        $session = $request->getSession();

        if ($session->get('logged_in') !== true) {
            return $this->redirectToRoute('app_login');
        }

        $userId = (int) $session->get('user_id', 0);

        /* =====================================================
           BALANCE MODE — Flow B (?pay_balance=...)
        ====================================================== */
        $payBalanceId = $request->query->get('pay_balance');
        if ($payBalanceId !== null && ctype_digit((string) $payBalanceId)) {
            return $this->chooseBalance($request, (int) $payBalanceId, (int) $id);
        }

        /* =====================================================
           RESERVATION MODE
        ====================================================== */
        $listing = $this->db->fetchAssociative(
            "SELECT id, title, price, location, user_id AS host_id, status
             FROM listings WHERE id = :id LIMIT 1",
            ['id' => $id]
        );

        if ($listing === false) {
            return $this->redirectToRoute('app_listings');
        }

        $isOwner = (int) $listing['host_id'] === $userId;

        if ($isOwner && $listing['status'] === 'approved') {
            return $this->redirectToRoute('app_listing_detail', ['id' => $id, 'alreadylisted' => 1]);
        }
        if (!$isOwner && $listing['status'] !== 'approved') {
            return $this->redirectToRoute('app_listing_detail', ['id' => $id, 'unavailable' => 1]);
        }

        $params = $this->bookingParams($request, $listing);
        if ($params['redirect'] !== null) {
            return $params['redirect'];
        }

        $pricing = $this->pricing($listing, $params, $isOwner, false);

        $walletBalance       = $this->walletBalance($userId);
        $walletCoversReserve = $walletBalance + 0.005 >= $pricing['reserve'];
        $walletCoversFull    = $walletBalance + 0.005 >= $pricing['total'];

        return $this->render('payment/choose.html.twig', [
            'is_logged_in'          => true,
            'nav_avatar'            => (string) ($session->get('avatar_path') ?? '') ?: '/images/default-avatar.png',
            'current_page'          => '/listings',
            'listing'               => [
                'id'       => (int) $listing['id'],
                'title'    => (string) $listing['title'],
                'location' => (string) $listing['location'],
            ],
            'params'                => $params['view'],
            'pricing'               => $pricing,
            'is_owner'              => $isOwner,
            'is_balance'            => false,
            'balance_booking_id'    => null,
            'wallet_balance'        => $walletBalance,
            'wallet_covers_reserve' => $walletCoversReserve,
            'wallet_covers_full'    => $walletCoversFull,
            'wallet_error'          => $request->query->getBoolean('wallet_error'),
            'csrf'                  => $this->ensureCsrfToken($request, self::CSRF_CHOOSE),
        ]);
    }

    /* =========================================================
       STAGE 2 — MOCK SCREEN (reservation OR balance)
    ========================================================== */
    #[Route('/payment/review', name: 'app_payment_review', methods: ['POST'])]
    public function review(Request $request): Response
    {
        $session = $request->getSession();

        if ($session->get('logged_in') !== true) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->csrfValid($request, self::CSRF_CHOOSE, (string) $request->request->get('_token', ''))) {
            return $this->redirectToRoute('app_listings');
        }

        $userId    = (int) $session->get('user_id', 0);
        $listingId = (int) $request->request->get('listing_id', 0);
        $isBalance = $request->request->get('payment_purpose', 'reservation') === 'balance';

        $listing = $this->db->fetchAssociative(
            "SELECT id, title, price, location, user_id AS host_id, status
             FROM listings WHERE id = :id LIMIT 1",
            ['id' => $listingId]
        );
        if ($listing === false) {
            return $this->redirectToRoute('app_listings');
        }

        $isOwner = (int) $listing['host_id'] === $userId;
        $payFull = false;

        /* ---- Balance mode: recompute the remaining server-side ---- */
        if ($isBalance) {
            $balanceData = $this->balanceContext($request, $userId);
            if ($balanceData === null) {
                return $this->redirectToRoute('app_listings');
            }
            $amountDue = $balanceData['remaining'];
            $params    = $balanceData['params'];
        } else {
            $params = $this->bookingParams($request, $listing, source: 'request');
            if ($params['redirect'] !== null) {
                return $params['redirect'];
            }
            $payFull   = $request->request->getBoolean('pay_full') && !$isOwner;
            $pricing   = $this->pricing($listing, $params, $isOwner, $payFull);
            $amountDue = $pricing['amount_due'];
        }

        $method = (string) $request->request->get('payment_method', '');
        if (!in_array($method, self::VALID_METHODS, true)) {
            return $this->redirectToRoute('app_listing_payment', ['id' => $listingId]);
        }

        $walletBalance = $this->walletBalance($userId);
        $walletShort   = $method === 'wallet' && ($walletBalance + 0.005 < $amountDue);

        $errors = [];
        if ($walletShort) {
            $errors[] = 'Your wallet balance (₱' . number_format($walletBalance, 2)
                . ') is not enough for this payment (₱' . number_format($amountDue, 2)
                . '). Choose another payment method.';
        }

        return $this->render('payment/review.html.twig', [
            'is_logged_in'   => true,
            'nav_avatar'     => (string) ($session->get('avatar_path') ?? '') ?: '/images/default-avatar.png',
            'current_page'   => '/listings',
            'listing'        => ['id' => (int) $listing['id'], 'title' => (string) $listing['title']],
            'params'         => $params,
            'is_balance'     => $isBalance,
            'balance_booking_id' => $isBalance ? ($balanceData['booking_id'] ?? null) : null,
            'pay_full'       => $payFull,
            'is_owner'       => $isOwner,
            'method'         => $method,
            'wallet_balance' => $walletBalance,
            'wallet_short'   => $walletShort,
            'errors'         => $errors,
            'csrf'           => $this->ensureCsrfToken($request, self::CSRF_CONFIRM),
            'choose_url'     => $isBalance
                ? $this->generateUrl('app_listing_payment', [
                    'id' => $listingId,
                    'pay_balance' => $balanceData['booking_id'],
                ])
                : $this->generateUrl('app_listing_payment', $this->chooseQuery($listingId, $params)),
        ]);
    }

    /* =========================================================
       STAGE 3 — CONFIRM (reservation OR balance)
    ========================================================== */
    #[Route('/payment/confirm', name: 'app_payment_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $session = $request->getSession();

        if ($session->get('logged_in') !== true) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->csrfValid($request, self::CSRF_CONFIRM, (string) $request->request->get('_token', ''))) {
            return $this->redirectToRoute('app_listings');
        }

        $userId    = (int) $session->get('user_id', 0);
        $listingId = (int) $request->request->get('listing_id', 0);
        $method    = (string) $request->request->get('payment_method', '');
        $isBalance = $request->request->get('payment_purpose', 'reservation') === 'balance';

        $listing = $this->db->fetchAssociative(
            "SELECT id, title, price, user_id AS host_id, status
             FROM listings WHERE id = :id LIMIT 1",
            ['id' => $listingId]
        );
        if ($listing === false || !in_array($method, self::VALID_METHODS, true)) {
            return $this->redirectToRoute('app_listings');
        }

        /* ---- Method field validation (server-side) ---- */
        $errors = $this->methodFieldErrors($request, $method);

        if ($errors !== []) {
            return $this->renderReviewWithError($request, $session, $listing, $method, $errors, $isBalance);
        }

        /* =====================================================
           BALANCE BRANCH — pay the remaining on an existing
           booking (Flow B). Booking locked FOR UPDATE; wallet
           debited inside the same transaction.
        ====================================================== */
        if ($isBalance) {
            return $this->confirmBalance($request, $session, $listing, $method);
        }

        /* =====================================================
           RESERVATION / OWNER BRANCH
        ====================================================== */
        $isOwner = (int) $listing['host_id'] === $userId;
        $params  = $this->bookingParams($request, $listing, source: 'request');
        if ($params['redirect'] !== null) {
            return $params['redirect'];
        }

        $payFull = $request->request->getBoolean('pay_full') && !$isOwner;
        $pricing = $this->pricing($listing, $params, $isOwner, $payFull);
        $amount  = $pricing['amount_due'];

        $pdo = $this->db;
        $pdo->beginTransaction();

        try {

            if ($isOwner) {
                $this->db->executeStatement(
                    "UPDATE listings SET status = 'approved'
                     WHERE id = :id AND user_id = :uid AND status != 'approved'",
                    ['id' => $listingId, 'uid' => $userId]
                );
            } else {

                $locked = $this->db->fetchAssociative(
                    "SELECT id, status FROM listings WHERE id = :id FOR UPDATE",
                    ['id' => $listingId]
                );
                if (!$locked || $locked['status'] !== 'approved') {
                    $pdo->rollBack();
                    return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'unavailable' => 1]);
                }

                $rangeEnd = $params['view']['long_term'] === 1 || $params['view']['checkout_date'] === ''
                    ? '9999-12-31'
                    : $params['view']['checkout_date'];

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
                        'checkin'    => $params['view']['checkin_date'],
                        'range_end'  => $rangeEnd,
                    ]
                );

                if ($conflict) {
                    $pdo->rollBack();
                    return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'unavailable' => 1]);
                }
            }

            if ($method === 'wallet') {
                if (!$this->walletCharge($userId, $amount, 'Reservation — "' . $listing['title'] . '"')) {
                    $pdo->rollBack();
                    return $this->redirectToRoute(
                        'app_listing_payment',
                        ['id' => $listingId, 'wallet_error' => 1] + $this->chooseQuery($listingId, $params['view'])
                    );
                }
            }

            $bookingId = null;
            if (!$isOwner) {
                $this->db->insert('bookings', [
                    'listing_id'     => $listingId,
                    'user_id'        => $userId,
                    'total'          => $pricing['total'],
                    'amount_paid'    => $amount,
                    'status'         => 'pending',
                    'payment_status' => 'paid',
                    'booked_at'      => date('Y-m-d H:i:s'),
                    'checkin_date'   => $params['view']['checkin_date'] !== '' ? $params['view']['checkin_date'] : null,
                    'checkout_date'  => $params['view']['checkout_date'] !== '' ? $params['view']['checkout_date'] : null,
                    'guests'         => $params['view']['guests'],
                ]);
                $bookingId = (int) $pdo->lastInsertId();
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('payment confirm failed: ' . $e->getMessage());
            return $this->redirectToRoute(
                'app_listing_payment',
                ['id' => $listingId] + $this->chooseQuery($listingId, $params['view'])
            );
        }

        /* ---- Notify the host ---- */
        if (!$isOwner) {
            try {
                $this->db->insert('notifications', [
                    'user_id'    => (int) $listing['host_id'],
                    'message'    => mb_substr(
                        (string) $session->get('user_name', 'A guest')
                            . ' applied for "' . $listing['title'] . '" (₱'
                            . number_format($amount, 2) . '). Accept within 24 hours or it is auto-declined.',
                        0,
                        240
                    ),
                    'link'       => '/host/bookings',
                    'is_read'    => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                /* non-fatal */
            }
        }

        if ($isOwner) {
            return $this->redirectToRoute('app_listing_detail', ['id' => $listingId, 'listed' => 1]);
        }

        return $this->redirectToRoute('app_payment_confirmation', ['booking' => $bookingId]);
    }

    /* =========================================================
       STAGE 4 — OFFICIAL RECEIPT
       Accessible by the booking's guest OR the listing's host.
    ========================================================== */
    #[Route('/payment/confirmation/{booking}', name: 'app_payment_confirmation', requirements: ['booking' => '\d+'])]
    public function confirmation(Request $request, int $booking): Response
    {
        $session = $request->getSession();

        if ($session->get('logged_in') !== true) {
            return $this->redirectToRoute('app_login');
        }

        $viewerId = (int) $session->get('user_id', 0);

        $row = $this->db->fetchAssociative(
            "SELECT b.id, b.user_id AS guest_id, b.total, b.amount_paid, b.status,
                    b.checkin_date, b.checkout_date, b.guests, b.booked_at,
                    l.title, l.location, l.exact_address,
                    l.user_id AS host_id,
                    u.name  AS host_name,
                    gu.name AS guest_name,
                    p.photo_path AS cover_photo
             FROM bookings b
             JOIN listings l ON l.id = b.listing_id
             JOIN users u    ON u.id = l.user_id
             JOIN users gu   ON gu.id = b.user_id
             LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.photo_type = 'cover'
             WHERE b.id = :id
             LIMIT 1",
            ['id' => $booking]
        );

        if ($row === false) {
            return $this->redirectToRoute('app_listings');
        }

        $isGuestViewer = (int) $row['guest_id'] === $viewerId;
        $isHostViewer  = (int) $row['host_id'] === $viewerId;

        if (!$isGuestViewer && !$isHostViewer) {
            return $this->redirectToRoute('app_listings');
        }

        $isHostOnlyView = $isHostViewer && !$isGuestViewer;

        /* ---- Figures ---- */
        $total      = (float) $row['total'];
        $amountPaid = (float) $row['amount_paid'];
        $remaining  = round($total - $amountPaid, 2);

        $justPaidRaw = $request->query->get('amt');
        $justPaid = (is_numeric($justPaidRaw))
            ? min(max(0.0, (float) $justPaidRaw), $amountPaid)
            : $amountPaid;

        $isFullyPaid      = $remaining <= 0.005;
        $hasAnyPayment    = $amountPaid > 0.005;
        $isAdvancePayment = !$isFullyPaid && $hasAnyPayment;

        $isLongTerm = !empty($row['checkin_date']) && empty($row['checkout_date']);
        $isBalancePayment = $request->query->getBoolean('balance');

        /* ---- Receipt meta ---- */
        $bookedTs  = strtotime((string) $row['booked_at']) ?: time();
        $receiptNo = 'RH-' . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT)
            . '-' . date('ymd', $bookedTs);

        $pmRaw   = (string) $request->query->get('pm', '');
        $pmLabels = [
            'gcash'  => 'GCash',
            'maya'   => 'Maya',
            'card'   => 'Credit / Debit Card',
            'wallet' => 'RoomHive Wallet',
        ];
        $paymentMethodLabel = $pmLabels[$pmRaw] ?? 'Online Payment';

        return $this->render('payment/confirmation.html.twig', [
            'is_logged_in'     => true,
            'nav_avatar'       => (string) ($session->get('avatar_path') ?? '') ?: '/images/default-avatar.png',
            'current_page'     => '/listings',

            'booking' => [
                'id'          => (int) $row['id'],
                'ref'         => str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT),
                'receipt_no'  => $receiptNo,
                'title'       => (string) $row['title'],
                'location'    => trim((string) ($row['exact_address'] ?? '') . ', ' . $row['location'], ', '),
                'total'       => $total,
                'amount_paid' => $amountPaid,
                'remaining'   => $remaining,
                'just_paid'   => $justPaid,
                'is_fully_paid'    => $isFullyPaid,
                'has_any_payment'  => $hasAnyPayment,
                'is_advance'       => $isAdvancePayment,
                'is_long_term'     => $isLongTerm,
                'is_balance'       => $isBalancePayment,
                'checkin'     => (string) ($row['checkin_date'] ?? ''),
                'checkout'    => (string) ($row['checkout_date'] ?? ''),
                'guests'      => (string) ($row['guests'] ?? '1'),
                'paid_date'   => date('M j, Y', $bookedTs),
                'paid_time'   => date('g:i A', $bookedTs),
                'status'      => (string) $row['status'],
            ],

            'payer_name'          => (string) ($row['guest_name'] ?? 'Guest'),
            'host_name'           => (string) $row['host_name'],
            'cover'               => $this->photoUrl((string) ($row['cover_photo'] ?? '')),
            'payment_method_label' => $paymentMethodLabel,
            'is_host_view'        => $isHostOnlyView,
        ]);
    }

    /* =========================================================
       PRIVATE HELPERS
    ====================================================== */

    /* ---- Balance-mode chooser render ---- */
    private function chooseBalance(Request $request, int $bookingId, int $listingId): Response
    {
        $session = $request->getSession();
        $userId  = (int) $session->get('user_id', 0);

        $ctx = $this->balanceContext($request, $userId, $bookingId);
        if ($ctx === null) {
            return $this->redirectToRoute('app_listings');
        }

        $walletBalance       = $this->walletBalance($userId);
        $walletCoversReserve = $walletBalance + 0.005 >= $ctx['remaining'];

        return $this->render('payment/choose.html.twig', [
            'is_logged_in'          => true,
            'nav_avatar'            => (string) ($session->get('avatar_path') ?? '') ?: '/images/default-avatar.png',
            'current_page'          => '/listings',
            'listing'               => [
                'id'       => (int) $ctx['listing']['id'],
                'title'    => (string) $ctx['listing']['title'],
                'location' => (string) $ctx['listing']['location'],
            ],
            'params'                => $ctx['params'],
            'pricing'               => [
                'total'      => (float) $ctx['booking']['total'],
                'reserve'    => $ctx['remaining'],
                'amount_due' => $ctx['remaining'],
                'label'      => 'Balance to Pay',
                'is_owner'   => false,
            ],
            'is_owner'              => false,
            'is_balance'            => true,
            'balance_booking_id'    => $bookingId,
            'wallet_balance'        => $walletBalance,
            'wallet_covers_reserve' => $walletCoversReserve,
            'wallet_covers_full'    => false,
            'wallet_error'          => $request->query->getBoolean('wallet_error'),
            'csrf'                  => $this->ensureCsrfToken($request, self::CSRF_CHOOSE),
        ]);
    }

    /**
     * Load + validate the balance booking. Returns
     * ['booking','listing','params','remaining','booking_id'] or null.
     */
    private function balanceContext(Request $request, int $userId, ?int $bookingId = null): ?array
    {
        $bookingId = $bookingId
            ?? ($request->request->get('booking_id') !== null && ctype_digit((string) $request->request->get('booking_id'))
                ? (int) $request->request->get('booking_id')
                : 0);

        if ($bookingId <= 0) {
            return null;
        }

        $booking = $this->db->fetchAssociative(
            "SELECT id, user_id, listing_id, total, amount_paid, status,
                    checkin_date, checkout_date, guests
             FROM bookings WHERE id = :id LIMIT 1",
            ['id' => $bookingId]
        );

        $valid = $booking !== false
            && (int) $booking['user_id'] === $userId
            && in_array($booking['status'], ['pending', 'confirmed'], true);

        if (!$valid) {
            return null;
        }

        $listingId = (int) $booking['listing_id'];

        $listing = $this->db->fetchAssociative(
            "SELECT id, title, price, location, user_id AS host_id, status
             FROM listings WHERE id = :id LIMIT 1",
            ['id' => $listingId]
        );
        if ($listing === false) {
            return null;
        }

        $remaining = round((float) $booking['total'] - (float) $booking['amount_paid'], 2);
        if ($remaining <= 0.005) {
            return null; /* nothing to pay — caller redirects */
        }

        $checkin  = $this->validDate((string) ($booking['checkin_date'] ?? ''));
        $checkout = $this->validDate((string) ($booking['checkout_date'] ?? ''));
        $guests   = in_array($booking['guests'] ?? '', ['1', '2', '3', '4+'], true)
            ? (string) $booking['guests'] : '1';

        return [
            'booking'    => $booking,
            'listing'    => $listing,
            'booking_id' => $bookingId,
            'remaining'  => $remaining,
            'params'     => [
                'listing_id'    => $listingId,
                'checkin_date'  => $checkin,
                'checkout_date' => $checkout,
                'months'        => 1,
                'long_term'     => ($checkin !== '' && $checkout === '') ? 1 : 0,
                'guests'        => $guests,
            ],
        ];
    }

    /* ---- Balance confirm (transactional) ---- */
    private function confirmBalance(Request $request, $session, array $listing, string $method): Response
    {
        $userId = (int) $session->get('user_id', 0);
        $ctx    = $this->balanceContext($request, $userId);

        if ($ctx === null) {
            return $this->redirectToRoute('app_listings');
        }

        $bookingId = $ctx['booking_id'];
        $pdo = $this->db;
        $pdo->beginTransaction();

        try {
            $locked = $this->db->fetchAssociative(
                "SELECT id, user_id, listing_id, total, amount_paid, status
                 FROM bookings WHERE id = :id FOR UPDATE",
                ['id' => $bookingId]
            );

            $stillValid = $locked !== false
                && (int) $locked['user_id'] === $userId
                && (int) $locked['listing_id'] === (int) $listing['id']
                && in_array($locked['status'], ['pending', 'confirmed'], true);

            if (!$stillValid) {
                $pdo->rollBack();
                return $this->redirectToRoute('app_listings');
            }

            $remainingNow = round((float) $locked['total'] - (float) $locked['amount_paid'], 2);

            if ($remainingNow <= 0.005) {
                $pdo->rollBack();
                return $this->redirectToRoute('app_payment_confirmation', ['booking' => $bookingId]);
            }

            if ($method === 'wallet') {
                if (!$this->walletCharge($userId, $remainingNow, 'Booking #' . $bookingId . ' — remaining balance payment')) {
                    $pdo->rollBack();
                    return $this->redirectToRoute('app_listing_payment', [
                        'id' => (int) $listing['id'],
                        'pay_balance' => $bookingId,
                        'wallet_error' => 1,
                    ]);
                }
            }

            $this->db->executeStatement(
                "UPDATE bookings
                 SET amount_paid = amount_paid + :amount,
                     payment_status = 'paid'
                 WHERE id = :id",
                ['amount' => $remainingNow, 'id' => $bookingId]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('balance confirm failed: ' . $e->getMessage());
            return $this->redirectToRoute('app_listing_payment', [
                'id' => (int) $listing['id'],
                'pay_balance' => $bookingId,
            ]);
        }

        /* ---- Notify the host ---- */
        try {
            $this->db->insert('notifications', [
                'user_id'    => (int) $listing['host_id'],
                'message'    => mb_substr(
                    (string) $session->get('user_name', 'A guest')
                        . ' paid the remaining balance (₱' . number_format($remainingNow, 2)
                        . ') for "' . $listing['title'] . '". The booking is now fully paid.',
                    0,
                    240
                ),
                'link'       => '/host/bookings',
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            /* non-fatal */
        }

        return $this->redirectToRoute('app_payment_confirmation', [
            'booking' => $bookingId,
            'balance' => 1,
            'pm'      => $method,
            'amt'     => number_format($remainingNow, 2, '.', ''),
        ]);
    }

    /* ---- Re-render review with validation errors ---- */
    private function renderReviewWithError(Request $request, $session, array $listing, string $method, array $errors, bool $isBalance): Response
    {
        $userId = (int) $session->get('user_id', 0);
        $payFull = false;
        $params  = [];
        $amountDue = 0.0;
        $balanceBookingId = null;

        if ($isBalance) {
            $ctx = $this->balanceContext($request, $userId);
            if ($ctx === null) {
                return $this->redirectToRoute('app_listings');
            }
            $params    = $ctx['params'];
            $amountDue = $ctx['remaining'];
            $balanceBookingId = $ctx['booking_id'];
        } else {
            $params = $this->bookingParams($request, $listing, source: 'request');
            if ($params['redirect'] !== null) {
                return $params['redirect'];
            }
            $isOwner = (int) $listing['host_id'] === $userId;
            $payFull = $request->request->getBoolean('pay_full') && !$isOwner;
            $amountDue = $this->pricing($listing, $params, $isOwner, $payFull)['amount_due'];
        }

        return $this->render('payment/review.html.twig', [
            'is_logged_in'   => true,
            'nav_avatar'     => (string) ($session->get('avatar_path') ?? '') ?: '/images/default-avatar.png',
            'current_page'   => '/listings',
            'listing'        => ['id' => (int) $listing['id'], 'title' => (string) $listing['title']],
            'params'         => $params,
            'is_balance'     => $isBalance,
            'balance_booking_id' => $balanceBookingId,
            'pay_full'       => $payFull,
            'is_owner'       => (int) $listing['host_id'] === $userId,
            'method'         => $method,
            'wallet_balance' => $this->walletBalance($userId),
            'wallet_short'   => false,
            'errors'         => $errors,
            'csrf'           => $this->ensureCsrfToken($request, self::CSRF_CONFIRM),
            'choose_url'     => $isBalance
                ? $this->generateUrl('app_listing_payment', ['id' => (int) $listing['id'], 'pay_balance' => $balanceBookingId])
                : $this->generateUrl('app_listing_payment', $this->chooseQuery((int) $listing['id'], $params)),
        ]);
    }

    private function methodFieldErrors(Request $request, string $method): array
    {
        $errors = [];

        if ($method === 'gcash' || $method === 'maya') {
            $mobile = trim((string) $request->request->get('mobile_number', ''));
            if (!preg_match('/^09\d{9}$/', $mobile)) {
                $errors[] = 'Enter a valid 11-digit mobile number starting with 09.';
            }
        }

        if ($method === 'card') {
            $cardNumber = preg_replace('/\s+/', '', (string) $request->request->get('card_number', ''));
            $cardName   = trim((string) $request->request->get('card_name', ''));
            $cardExpiry = trim((string) $request->request->get('card_expiry', ''));
            $cardCvv    = trim((string) $request->request->get('card_cvv', ''));

            if (!preg_match('/^\d{16}$/', $cardNumber)) {
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

        return $errors;
    }

    private function bookingParams(Request $request, array $listing, string $source = 'query'): array
    {
        $session = $request->getSession();
        $userId  = (int) $session->get('user_id', 0);
        $bag     = $source === 'query' ? $request->query : $request->request;
        $isOwner = (int) $listing['host_id'] === $userId;

        $checkin  = $this->validDate((string) $bag->get('checkin_date', ''));
        $checkout = $this->validDate((string) $bag->get('checkout_date', ''));
        $months   = (int) $bag->get('months', 1);
        $longTerm = $bag->get('long_term', '0') === '1';

        if (!in_array($months, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], true)) {
            $months = 1;
        }

        $guests = (string) $bag->get('guests', '1');
        if (!in_array($guests, ['1', '2', '3', '4+'], true)) {
            $guests = '1';
        }

        if ($longTerm) {
            $months = 1;
            $checkout = '';
        }

        if (!$isOwner) {
            if ($checkin === '' || (!$longTerm && $checkout === '')) {
                return ['view' => [], 'redirect' => $this->redirectToRoute('app_listing_detail', [
                    'id' => (int) $listing['id'],
                    'incompletedates' => 1,
                ])];
            }
            if ($checkout !== '' && $checkout < $checkin) {
                return ['view' => [], 'redirect' => $this->redirectToRoute('app_listing_detail', [
                    'id' => (int) $listing['id'],
                    'incompletedates' => 1,
                ])];
            }
        }

        return [
            'view' => [
                'listing_id'    => (int) $listing['id'],
                'checkin_date'  => $checkin,
                'checkout_date' => $checkout,
                'months'        => $months,
                'long_term'     => $longTerm ? 1 : 0,
                'guests'        => $guests,
            ],
            'redirect' => null,
        ];
    }

    private function pricing(array $listing, array $params, bool $isOwner, bool $payFull): array
    {
        if ($isOwner) {
            $fee = round((float) $listing['price'], 2);
            return [
                'total' => $fee,
                'reserve' => $fee,
                'amount_due' => $fee,
                'label' => 'Listing Fee',
                'is_owner' => true
            ];
        }

        $total   = round((float) $listing['price'] * $params['view']['months'], 2);
        $reserve = round($total * 0.5, 2);

        return [
            'total'      => $total,
            'reserve'    => $reserve,
            'amount_due' => $payFull ? $total : $reserve,
            'label'      => $params['view']['long_term'] === 1
                ? 'First Month (month-to-month)'
                : ($params['view']['months'] === 1 ? '1 Month' : $params['view']['months'] . ' Months'),
            'is_owner'   => false,
        ];
    }

    private function validDate(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : '';
    }

    private function photoUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/images/ListingPlaceholder.png';
        }
        if (preg_match('#^(https?://|/)#i', $path)) {
            return $path;
        }
        $path = str_replace('\\', '/', ltrim($path, '/'));
        if (stripos($path, 'webprogg/') === 0) {
            $path = substr($path, strlen('webprogg/'));
        }
        if (stripos($path, 'uploads/') === 0) {
            return '/' . $path;
        }
        return '/uploads/listing_photos/cover/' . basename($path);
    }

    private function walletBalance(int $userId): float
    {
        $bal = $this->db->fetchOne(
            'SELECT wallet_balance FROM users WHERE id = :id',
            ['id' => $userId]
        );
        return $bal === false ? 0.0 : (float) $bal;
    }

    private function walletCharge(int $userId, float $amount, string $description): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return false;
        }

        $upd = $this->db->executeStatement(
            "UPDATE users
             SET wallet_balance = wallet_balance - :amt
             WHERE id = :id AND wallet_balance >= :amt2",
            ['amt' => $amount, 'amt2' => $amount, 'id' => $userId]
        );

        if ($upd === 0) {
            return false;
        }

        $balanceAfter = (float) $this->db->fetchOne(
            'SELECT wallet_balance FROM users WHERE id = :id',
            ['id' => $userId]
        );

        try {
            $this->db->insert('wallet_transactions', [
                'user_id'        => $userId,
                'reference'      => 'WH' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4))),
                'type'           => 'spend',
                'amount'         => $amount,
                'balance_after'  => $balanceAfter,
                'description'    => mb_substr($description, 0, 240),
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('wallet ledger insert failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    private function chooseQuery(int $listingId, array $view): array
    {
        return array_filter([
            'checkin_date'  => $view['checkin_date']  ?? '',
            'checkout_date' => $view['checkout_date'] ?? '',
            'months'        => $view['months']        ?? 1,
            'long_term'     => $view['long_term']     ?? 0,
            'guests'        => $view['guests']        ?? '1',
        ], fn($v) => $v !== '' && $v !== null) + ['id' => $listingId];
    }

    private function ensureCsrfToken(Request $request, string $id): string
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
