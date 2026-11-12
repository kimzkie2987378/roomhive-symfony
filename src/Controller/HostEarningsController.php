<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Earnings + Full Transaction Ledger.
 * 1:1 port of legacy host/hostearnings.php.
 *
 *   GET /host/earnings  → every booking on the host's listings
 *     (any status) as a transaction ledger:
 *
 *   EARNINGS MATH (same as legacy):
 *     • Totals / chart / avg = confirmed+completed, non-refunded
 *       rows only — PLUS the 2% cancellation fee the host keeps
 *       from every refunded booking (guest gets 98% back).
 *     • HP_REFUND_FEE_RATE = 0.02 (class const below, same
 *       adjustability as the legacy $refundFeeRate).
 *     • Pending bookings are LISTED but not counted in earnings
 *       until confirmed/completed ("counts when confirmed" note).
 *
 *   PAYMENT STATUS DERIVED IN PHP (same thresholds):
 *       refunded_at set OR refunded_amount > 0  → refunded
 *       amount_paid >= total                    → paid (full)
 *       0 < amount_paid < total                 → partial
 *       amount_paid = 0                         → pending
 *
 *   SELF-HEALING SCHEMA DETECTION (same as legacy): SHOW COLUMNS
 *     on bookings + users, the SELECT is built only from columns
 *     that exist; falls back to a minimal query if the main one
 *     fails, then to a visible DB-error card (never a silent
 *     empty page).
 *
 *   INPUTS:
 *     ?filter=all|paid|partial|pending|refunded  (whitelisted)
 *     ?q=        search — listing title, guest name, #ref
 *     ?page=N    pagination (20 per page)
 *     ?debug=1   detection/row-count panel
 *     ?export=csv  CSV download of ALL matching records (same
 *                  headers/rows as legacy, BOM + Payout column =
 *                  the 2% fee on refunded rows). Runs before HTML
 *                  exactly like legacy's inline export block.
 *
 *   CHARTS (native SVG/CSS, no libraries — same as legacy):
 *     • SVG area/line trend of the last 6 months (points
 *       pre-built in the controller, IntersectionObserver draw-in
 *       animation in the template JS).
 *     • Monthly comparison bars (hp-bars, same CSS values as
 *       host/profile.html.twig).
 *
 *   Auth: same guard as HostListingsController / HostBookingsController
 *     — logged_in + user_id (else app_login), then is_host = 1
 *     (else /host/become-a-host).
 */
class HostEarningsController extends AbstractController
{
    /** Host keeps 2% of every refunded booking (guest refunds 98%). */
    private const REFUND_FEE_RATE = 0.02;

    private const PER_PAGE = 20;

    private const ALLOWED_FILTERS = ['all', 'paid', 'partial', 'pending', 'refunded'];

    public function __construct(private readonly Connection $db) {}

    #[Route('/host/earnings', name: 'app_host_earnings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostListingsController) ---- */
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

        /* ---- HOST GUARD — non-hosts go to Become a Host ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* =========================================================
           BADGE COUNTS — navbar bell + sidebar pending tenants.
        ========================================================= */
        $notificationCount   = $this->countUnreadNotifications($userId);
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
           INPUT: filter / search / pagination / debug.
           $filter can ONLY be a whitelisted key or 'all'.
        ========================================================= */
        $rawFilter = is_string($request->query->get('filter')) ? trim((string) $request->query->get('filter')) : '';
        $filter    = in_array($rawFilter, self::ALLOWED_FILTERS, true) ? $rawFilter : 'all';

        $search = trim((string) ($request->query->get('q', '')));
        $page   = max(1, (int) ($request->query->get('page', 1)));
        $debug  = $request->query->get('debug') === '1';

        /* =========================================================
           SCHEMA DETECTION — build the SELECT from real columns.
           Same SHOW COLUMNS approach as legacy (DBAL equivalent),
           same fallback lists.
        ========================================================= */
        $bookingCols = [];
        $userCols    = [];
        try {
            $bookingCols = array_map(
                fn (array $r) => (string) $r['Field'],
                $this->db->fetchAllAssociative('SHOW COLUMNS FROM bookings')
            );
            $userCols = array_map(
                fn (array $r) => (string) $r['Field'],
                $this->db->fetchAllAssociative('SHOW COLUMNS FROM users')
            );
        } catch (\Throwable $e) {
            /* Same fallback lists as legacy */
            $bookingCols = [
                'id', 'total', 'amount_paid', 'host_payout_amount', 'status',
                'created_at', 'checkin_date', 'payout_at', 'refunded_at',
            ];
            $userCols = ['id', 'name'];
        }

        $hasB = fn (string $c): bool => in_array($c, $bookingCols, true);
        $hasU = fn (string $c): bool => in_array($c, $userCols, true);

        $sel = [];
        $sel[] = 'b.id';
        $sel[] = 'l.title AS listing_title';
        $sel[] = $hasB('total')               ? 'COALESCE(b.total, 0) AS total'               : '0 AS total';
        $sel[] = $hasB('amount_paid')         ? 'COALESCE(b.amount_paid, 0) AS amount_paid'   : '0 AS amount_paid';
        $sel[] = $hasB('host_payout_amount')  ? 'b.host_payout_amount'                        : 'NULL AS host_payout_amount';
        $sel[] = $hasB('platform_fee_amount') ? 'b.platform_fee_amount'                       : 'NULL AS platform_fee_amount';
        $sel[] = $hasB('refunded_amount')     ? 'COALESCE(b.refunded_amount, 0) AS refunded_amount' : '0 AS refunded_amount';
        $sel[] = 'b.status';
        $sel[] = 'b.created_at';
        $sel[] = $hasB('checkin_date')        ? 'b.checkin_date'                              : 'NULL AS checkin_date';
        $sel[] = $hasB('checkout_date')       ? 'b.checkout_date'                             : 'NULL AS checkout_date';
        $sel[] = $hasB('payout_at')           ? 'b.payout_at'                                 : 'NULL AS payout_at';
        $sel[] = $hasB('refunded_at')         ? 'b.refunded_at'                               : 'NULL AS refunded_at';

        $canGuestJoin = $hasU('name') || $hasU('email');
        if ($hasU('name')) {
            $sel[] = 'g.name AS guest_name';
        } elseif ($hasU('email')) {
            $sel[] = 'g.email AS guest_name';
        } else {
            $sel[] = "'' AS guest_name";
        }

        $sql = "SELECT " . implode(",\n       ", $sel) . "
             FROM bookings b
             JOIN listings l ON l.id = b.listing_id"
            . ($canGuestJoin ? "\n     LEFT JOIN users g ON g.id = b.user_id" : "") . "
             WHERE l.user_id = :id
             ORDER BY COALESCE(b.checkin_date, DATE(b.created_at)) DESC, b.id DESC";

        /* =========================================================
           RUN THE QUERY — main attempt, then a minimal fallback,
           then a visible error (never a silent empty page)
        ========================================================= */
        $allBookings = [];
        $dbError     = null;
        try {
            $allBookings = $this->db->fetchAllAssociative($sql, ['id' => $userId]);
        } catch (\Throwable $e) {
            try {
                /* Minimal query using only the columns the original page used */
                $allBookings = $this->db->fetchAllAssociative(
                    "SELECT b.id,
                            COALESCE(b.total, 0) AS total,
                            b.amount_paid, b.host_payout_amount, b.status,
                            b.created_at, b.checkin_date, b.payout_at, b.refunded_at,
                            0 AS refunded_amount,
                            l.title AS listing_title
                     FROM bookings b
                     JOIN listings l ON l.id = b.listing_id
                     WHERE l.user_id = :id
                     ORDER BY COALESCE(b.checkin_date, DATE(b.created_at)) DESC",
                    ['id' => $userId]
                );
            } catch (\Throwable $e2) {
                $dbError = $e2->getMessage();
            }
        }

        /* =========================================================
           DERIVE PAYMENT STATUS IN PHP (same thresholds as legacy)
        ========================================================= */
        $derivePayment = function (array $b): string {
            $refundedAmount = (float) ($b['refunded_amount'] ?? 0);
            if (!empty($b['refunded_at']) || $refundedAmount > 0.005) {
                return 'refunded';
            }
            $paid  = (float) ($b['amount_paid'] ?? 0);
            $total = (float) ($b['total'] ?? 0);
            if ($paid > 0.005) {
                return ($total > 0 && $paid >= $total - 0.004) ? 'paid' : 'partial';
            }
            return 'pending';
        };

        foreach ($allBookings as $k => $b) {
            $allBookings[$k]['payment_status'] = $derivePayment($b);
        }

        /* =========================================================
           MONEY HELPERS — 1:1 with the legacy closures
        ========================================================= */

        /* What the booking pays the host (payout falls back to what the guest paid) */
        $bookingAmount = function (array $b): float {
            $payout = $b['host_payout_amount'] ?? null;
            return (float) ($payout !== null ? $payout : ($b['amount_paid'] ?? 0));
        };

        /* What the host keeps from a refunded booking (2%) */
        $refundFeeFn = function (array $b) use ($bookingAmount): float {
            return round($bookingAmount($b) * self::REFUND_FEE_RATE, 2);
        };

        $bookingTotalFn = fn (array $b): float => (float) ($b['total'] ?? 0);

        $bookingBalanceFn = fn (array $b): float =>
            max(0, round((float) ($b['total'] ?? 0) - (float) ($b['amount_paid'] ?? 0), 2));

        /* Earnings-eligible = confirmed/completed and not refunded
           (refunded rows earn their 2% fee separately) */
        $isEarningRow = function (array $b): bool {
            return in_array($b['status'], ['confirmed', 'completed'], true)
                && $b['payment_status'] !== 'refunded';
        };

        /* Search: listing title, guest name, #ref, zero-padded ref */
        $matchSearch = function (array $b) use ($search): bool {
            if ($search === '') {
                return true;
            }
            $hay = strtolower(
                ($b['listing_title'] ?? '') . ' ' . ($b['guest_name'] ?? '') . ' #' . $b['id'] . ' '
                . str_pad((string) $b['id'], 6, '0', STR_PAD_LEFT)
            );
            return strpos($hay, strtolower($search)) !== false;
        };

        /* =========================================================
           CSV EXPORT — all matching records (runs before HTML).
           Same headers + row values as legacy, BOM included.
        ========================================================= */
        if ($request->query->get('export') === 'csv') {
            $exportRows = array_values(array_filter($allBookings, function (array $b) use ($filter, $matchSearch) {
                if ($filter !== 'all' && $b['payment_status'] !== $filter) {
                    return false;
                }
                return $matchSearch($b);
            }));

            $response = new StreamedResponse(function () use ($exportRows, $bookingAmount, $refundFeeFn, $bookingTotalFn, $bookingBalanceFn) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [
                    'Booking Ref',
                    'Booking ID',
                    'Check-in',
                    'Check-out',
                    'Listing',
                    'Guest',
                    'Booking Status',
                    'Payment Status',
                    'Total',
                    'Amount Paid',
                    'Balance',
                    'Platform Fee',
                    'Host Payout',
                    'Booked At',
                    'Payout At',
                    'Refunded At',
                ]);
                foreach ($exportRows as $b) {
                    /* Refunded rows: payout column = the 2% fee kept */
                    $hostPayout = $b['payment_status'] === 'refunded'
                        ? $refundFeeFn($b)
                        : $bookingAmount($b);

                    fputcsv($out, [
                        '#' . str_pad((string) $b['id'], 6, '0', STR_PAD_LEFT),
                        $b['id'],
                        ($b['checkin_date'] ?? '') ?: '',
                        ($b['checkout_date'] ?? '') ?: '',
                        $b['listing_title'] ?? '',
                        ($b['guest_name'] ?? '') ?: '—',
                        $b['status'],
                        $b['payment_status'],
                        number_format($bookingTotalFn($b), 2, '.', ''),
                        number_format((float) ($b['amount_paid'] ?? 0), 2, '.', ''),
                        number_format($bookingBalanceFn($b), 2, '.', ''),
                        number_format((float) ($b['platform_fee_amount'] ?? 0), 2, '.', ''),
                        number_format($hostPayout, 2, '.', ''),
                        substr((string) ($b['created_at'] ?? ''), 0, 16),
                        !empty($b['payout_at']) ? substr((string) $b['payout_at'], 0, 16) : '',
                        !empty($b['refunded_at']) ? substr((string) $b['refunded_at'], 0, 16) : '',
                    ]);
                }
                fclose($out);
            });

            $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $response->headers->set('Content-Disposition',
                'attachment; filename="roomhive-earnings-' . date('Y-m-d') . '.csv"');
            $response->headers->set('Pragma', 'no-cache');

            return $response;
        }

        /* =========================================================
           TOTALS — confirmed/completed rows at full value, refunded
           rows at the 2% cancellation fee
        ========================================================= */
        $totalEarnings    = 0.0;
        $thisMonth        = 0.0;
        $refundedTotal    = 0.0;
        $refundedCount    = 0;
        $refundFeesEarned = 0.0;
        $earningCount     = 0;
        $monthMap         = [];
        $currentMonth     = date('Y-m');

        foreach ($allBookings as $b) {
            $amt = $bookingAmount($b);

            if ($b['payment_status'] === 'refunded') {
                $refundedTotal += $amt;
                $refundedCount++;

                $fee = $refundFeeFn($b);
                $totalEarnings    += $fee;
                $refundFeesEarned += $fee;
                $earningCount++;

                $stayDate = ($b['checkin_date'] ?? '') ?: substr((string) ($b['created_at'] ?? 'now'), 0, 10);
                $key = date('Y-m', strtotime($stayDate));
                $monthMap[$key] = ($monthMap[$key] ?? 0) + $fee;

                if ($key === $currentMonth) {
                    $thisMonth += $fee;
                }
                continue;
            }

            if (!$isEarningRow($b)) {
                continue; /* pending / declined / cancelled — listed but not earned */
            }

            $totalEarnings += $amt;
            $earningCount++;

            $stayDate = ($b['checkin_date'] ?? '') ?: substr((string) ($b['created_at'] ?? 'now'), 0, 10);
            $key = date('Y-m', strtotime($stayDate));
            $monthMap[$key] = ($monthMap[$key] ?? 0) + $amt;

            if ($key === $currentMonth) {
                $thisMonth += $amt;
            }
        }

        /* ---- 6-month chart buckets (same loop as legacy) ---- */
        $chart = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts  = strtotime("first day of -{$i} months");
            $key = date('Y-m', $ts);
            $chart[] = ['label' => date('M', $ts), 'value' => $monthMap[$key] ?? 0.0];
        }

        $maxMonth = 1.0;
        foreach ($chart as $c) {
            if ($c['value'] > $maxMonth) {
                $maxMonth = $c['value'];
            }
        }

        $avgBooking = $earningCount > 0 ? $totalEarnings / $earningCount : 0.0;

        /* =========================================================
           SVG AREA/LINE TREND (6-month data, native SVG)
           Points pre-built here so the markup stays static — same
           geometry as legacy (viewBox 600x160, x 8→592, y 18→150).
           Each point also carries x/y for the hover circles.
        ========================================================= */
        $chartSum = 0.0;
        foreach ($chart as $c) {
            $chartSum += $c['value'];
        }

        $chartMax = max($maxMonth, 0.01);

        $trPoints = [];
        foreach ($chart as $i => $c) {
            $x = 8 + ($i / max(1, count($chart) - 1)) * 584;
            $y = 18 + (1 - ($c['value'] / $chartMax)) * 132;
            $chart[$i]['x'] = round($x, 1);
            $chart[$i]['y'] = round($y, 1);
            $trPoints[]     = round($x, 1) . ',' . round($y, 1);
        }
        $trPointsStr = implode(' ', $trPoints);
        $trAreaPath  = 'M8,150 L' . implode(' L', $trPoints) . ' L592,150 Z';

        /* =========================================================
           FILTER + TAB COUNTS + PAGINATION (over ALL records)
        ========================================================= */
        $tabCounts = ['all' => 0, 'paid' => 0, 'partial' => 0, 'pending' => 0, 'refunded' => 0];
        foreach ($allBookings as $b) {
            if (!$matchSearch($b)) {
                continue;
            }
            $tabCounts['all']++;
            $ps = $b['payment_status'];
            if (isset($tabCounts[$ps])) {
                $tabCounts[$ps]++;
            }
        }

        $filteredBookings = array_values(array_filter($allBookings, function (array $b) use ($filter, $matchSearch) {
            if ($filter !== 'all' && $b['payment_status'] !== $filter) {
                return false;
            }
            return $matchSearch($b);
        }));

        $totalRecords = count($filteredBookings);
        $totalPages   = max(1, (int) ceil($totalRecords / self::PER_PAGE));
        $page         = min($page, $totalPages);
        $offset       = ($page - 1) * self::PER_PAGE;
        $pageRecords  = array_slice($filteredBookings, $offset, self::PER_PAGE);

        $viewEarningsSum = 0.0;
        foreach ($filteredBookings as $b) {
            if ($isEarningRow($b)) {
                $viewEarningsSum += $bookingAmount($b);
            } elseif ($b['payment_status'] === 'refunded') {
                $viewEarningsSum += $refundFeeFn($b);
            }
        }

        /* =========================================================
           NORMALIZE PAGE ROWS FOR THE TEMPLATE — pre-computed
           display strings + pill classes, same rendering rules
           as legacy's foreach loop.
        ========================================================= */
        $paymentMeta = [
            'paid'     => ['Fully Paid', 'hp-status-active'],
            'partial'  => ['Partial',    'hp-status-partial'],
            'pending'  => ['Pending',    'hp-status-pending'],
            'refunded' => ['Refunded',   'hp-status-refunded'],
        ];

        $rows = [];
        foreach ($pageRecords as $t) {
            $payLabel = $paymentMeta[$t['payment_status']][0] ?? ucfirst((string) $t['payment_status']);
            $payClass = $paymentMeta[$t['payment_status']][1] ?? 'hp-status-pending';
            $balance  = $bookingBalanceFn($t);

            $bookedAtRaw = (string) ($t['created_at'] ?? '');

            if (in_array($t['status'], ['confirmed', 'completed'], true)) {
                $bookClass = 'hp-status-active';
            } elseif ($t['status'] === 'pending') {
                $bookClass = 'hp-status-pending';
            } else {
                $bookClass = 'hp-status-refunded';
            }

            $refunded = $t['payment_status'] === 'refunded';

            $rows[] = [
                'id'             => (int) $t['id'],
                'ref'            => '#' . str_pad((string) $t['id'], 6, '0', STR_PAD_LEFT),
                'booked_display' => $bookedAtRaw !== '' ? date('M j, Y', strtotime($bookedAtRaw)) : '—',
                'checkin_display' => !empty($t['checkin_date'])
                    ? date('M j, Y', strtotime((string) $t['checkin_date']))
                    : ($bookedAtRaw !== '' ? date('M j, Y', strtotime($bookedAtRaw)) : '—'),
                'checkout_display' => !empty($t['checkout_date'])
                    ? date('M j, Y', strtotime((string) $t['checkout_date']))
                    : '',
                'listing_title'  => (string) ($t['listing_title'] ?? ''),
                'guest_name'     => (($t['guest_name'] ?? '') !== '') ? (string) $t['guest_name'] : '—',
                'pay_label'      => $payLabel,
                'pay_class'      => $payClass,
                'payment_status' => (string) $t['payment_status'],
                'balance'        => $balance,
                'refunded_at_display' => !empty($t['refunded_at'])
                    ? date('M j, Y', strtotime((string) $t['refunded_at']))
                    : '',
                'status_label'   => ucfirst((string) $t['status']),
                'book_class'     => $bookClass,
                'payout_at_display' => !empty($t['payout_at'])
                    ? date('M j, Y', strtotime((string) $t['payout_at']))
                    : '',
                'counts_when_confirmed' => !$isEarningRow($t) && !$refunded,
                'payout_display' => $refunded
                    ? number_format($refundFeeFn($t), 2)
                    : number_format($bookingAmount($t), 2),
                'is_refunded'    => $refunded,
                'refund_fee_display' => number_format($refundFeeFn($t), 2),
                'refund_fee_pct' => number_format(self::REFUND_FEE_RATE * 100, 0),
                'refund_base_display' => number_format($bookingAmount($t), 2),
                'total_display'  => number_format($bookingTotalFn($t), 2),
                'has_total'      => $bookingTotalFn($t) > 0,
            ];
        }

        /* =========================================================
           URL BUILDER — same behavior as legacy's $buildUrl:
           drops filter=all, empty q, page<=1; never returns ''
           (a bare '?' is a real reset).
        ========================================================= */
        $buildUrl = function (array $overrides = []) use ($request, $filter, $search, $page): string {
            $params = ['filter' => $filter, 'q' => $search, 'page' => $page];
            foreach ($overrides as $k => $v) {
                if ($v === null) {
                    unset($params[$k]);
                } else {
                    $params[$k] = $v;
                }
            }
            if (($params['filter'] ?? '') === 'all') {
                unset($params['filter']);
            }
            if (($params['q'] ?? '') === '') {
                unset($params['q']);
            }
            if ((int) ($params['page'] ?? 1) <= 1) {
                unset($params['page']);
            }
            $qs = http_build_query($params);

            /* Never return '' — an empty href reloads the CURRENT url
               WITH its stale query params. A bare '?' is a real reset. */
            return $qs !== '' ? '?' . $qs : '?';
        };

        /* ---- Pre-built URLs for tabs / search-clear / export ---- */
        $urls = [
            'tabs' => [
                'all'      => $buildUrl(['filter' => 'all',      'page' => null]),
                'paid'     => $buildUrl(['filter' => 'paid',     'page' => null]),
                'partial'  => $buildUrl(['filter' => 'partial',  'page' => null]),
                'pending'  => $buildUrl(['filter' => 'pending',  'page' => null]),
                'refunded' => $buildUrl(['filter' => 'refunded', 'page' => null]),
            ],
            'search_clear' => $buildUrl(['q' => null, 'page' => null]),
            'export_csv'   => $buildUrl(['export' => 'csv', 'page' => null]),
            'clear_filters' => '?',
        ];

        /* ---- Pagination window (same 5-page window as legacy) ---- */
        $winStart = max(1, $page - 2);
        $winEnd   = min($totalPages, $winStart + 4);

        $pagination = [
            'show_first'      => $winStart > 1,
            'show_first_dots' => $winStart > 2,
            'show_last'       => $winEnd < $totalPages,
            'show_last_dots'  => $winEnd < $totalPages - 1,
            'first_url'       => $buildUrl(['page' => 1]),
            'last_url'        => $buildUrl(['page' => $totalPages]),
            'last_page'       => $totalPages,
            'prev_url'        => $buildUrl(['page' => max(1, $page - 1)]),
            'next_url'        => $buildUrl(['page' => min($totalPages, $page + 1)]),
            'prev_disabled'   => $page <= 1,
            'next_disabled'   => $page >= $totalPages,
            'pages'           => [],
        ];
        for ($p = $winStart; $p <= $winEnd; $p++) {
            $pagination['pages'][] = [
                'p'      => $p,
                'url'    => $buildUrl(['page' => $p]),
                'active' => $p === $page,
            ];
        }

        return $this->render('host/earnings.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Constants */
            'refund_fee_rate'       => self::REFUND_FEE_RATE,
            'refund_fee_pct'        => number_format(self::REFUND_FEE_RATE * 100, 0),
            'per_page'              => self::PER_PAGE,

            /* State */
            'db_error'              => $dbError,
            'debug'                 => $debug,
            'filter'                => $filter,
            'search'                => $search,
            'page'                  => $page,
            'total_pages'           => $totalPages,
            'total_records'         => $totalRecords,
            'offset'                => $offset,
            'page_records_count'    => count($pageRecords),
            'all_count'             => count($allBookings),
            'tab_counts'            => $tabCounts,
            'urls'                  => $urls,
            'pagination'            => $pagination,

            /* Stat pills */
            'total_earnings'        => $totalEarnings,
            'this_month'            => $thisMonth,

            /* Charts */
            'chart'                 => $chart,
            'chart_max'             => $chartMax,
            'max_month'             => $maxMonth,
            'chart_sum'             => $chartSum,
            'tr_points_str'         => $trPointsStr,
            'tr_area_path'          => $trAreaPath,

            /* Ledger */
            'avg_booking'           => $avgBooking,
            'refunded_total'        => $refundedTotal,
            'refunded_count'        => $refundedCount,
            'refund_fees_earned'    => $refundFeesEarned,
            'earning_count'         => $earningCount,
            'view_earnings_sum'     => $viewEarningsSum,
            'rows'                  => $rows,

            /* Debug panel (when ?debug=1) */
            'detected'              => [
                'total'               => $hasB('total'),
                'amount_paid'         => $hasB('amount_paid'),
                'host_payout_amount'  => $hasB('host_payout_amount'),
                'platform_fee_amount' => $hasB('platform_fee_amount'),
                'refunded_amount'     => $hasB('refunded_amount'),
            ],
        ]);
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostListingsController.
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
     *  PHOTO PATH RESOLVER — same as HostListingsController /
     *  HostBookingsController / HostProfileController.
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
