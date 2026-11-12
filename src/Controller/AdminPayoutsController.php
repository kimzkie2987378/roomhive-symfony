<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Payouts.
 * 1:1 port of legacy admin/adminpayouts.php.
 *
 *   GET|POST /admin/payouts   → host withdrawal requests with inline
 *     status override (POST: payout_id + payout_status + csrf_token
 *     + ret_status).
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * Three stat cards at the top mirror the legacy page:
 *   - Platform Commission: SUM(platform_fee_amount) over
 *     bookings WHERE status IN ('confirmed','completed').
 *   - Host Earnings: SUM(COALESCE(host_payout_amount, amount_paid))
 *     over the same booking rows — gross of payouts.
 *   - Pending Payout Requests: SUM(amount) over payouts WHERE
 *     status IN ('pending','processing'). Caption shows the
 *     SUM(amount) over status='completed'.
 *
 * Filter tabs: All, Pending, Processing, Completed, Failed.
 * List: 100 most recent (ORDER BY requested_at DESC, no pagination —
 *   same as legacy LIMIT 100).
 *
 * PAYOUTS-TABLE GUARD: if the `payouts` table does not exist yet,
 *   the stat cards still render (platform_fee_amount + host_payout
 *   columns live on `bookings`), but the panel renders the legacy
 *   "run the CREATE TABLE SQL" empty-state. Same behavior as the
 *   legacy try/catch around $payoutTableExists.
 *
 * Status update on POST: when status flips to 'completed' the
 *   processed_at column is set to NOW(); any other status clears
 *   processed_at to NULL (matches the legacy $setProcessed logic).
 */
class AdminPayoutsController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    /** Statuses the admin can set via the per-row select. */
    private const VALID_STATUSES = ['pending', 'processing', 'completed', 'failed'];

    /** Statuses the filter tabs can show. 'all' is special. */
    private const VALID_FILTERS = ['all', 'pending', 'processing', 'completed', 'failed'];

    /** Map for the badge class on a status label. */
    private const BADGE_CLASS_MAP = [
        'Pending'    => 'badge-pending',
        'Processing' => 'badge-completed',
        'Completed' => 'badge-completed',
        'Failed'    => 'badge-rejected',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/payouts', name: 'app_admin_payouts', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->get('admin_logged_in')) {
            return $this->redirect('/admin/login');
        }

        $adminName  = (string) ($session->get('admin_name')  ?? 'Admin User');
        $adminEmail = (string) ($session->get('admin_email') ?? '');

        /* ---- CSRF token (per-session, separate from user-flow) ---- */
        $adminCsrf = (string) $session->get(self::CSRF_KEY);
        if ($adminCsrf === '') {
            $adminCsrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $adminCsrf);
        }

        /* =========================================================
           HANDLE ACTION (POST): update payout status
           CSRF-verified; status whitelisted via VALID_STATUSES.
           On 'completed' the processed_at column is set to NOW();
           any other status clears processed_at to NULL.
           ret_status is preserved through the redirect.
        ========================================================= */
        if (
            $request->isMethod('POST')
            && $request->request->has('payout_status')
            && $request->request->has('payout_id')
            && $request->request->has('csrf_token')
        ) {

            $postedToken  = (string) $request->request->get('csrf_token', '');
            $payoutId     = (int) $request->request->get('payout_id', 0);
            $payoutStatus = (string) $request->request->get('payout_status', '');
            $retStatus    = (string) $request->request->get('ret_status', 'all');
            if (!in_array($retStatus, self::VALID_FILTERS, true)) {
                $retStatus = 'all';
            }

            if (
                !hash_equals($adminCsrf, $postedToken)
                || $payoutId <= 0
                || !in_array($payoutStatus, self::VALID_STATUSES, true)
            ) {
                $this->addFlash('admin_payouts_flash', [
                    'type' => 'error',
                    'text' => 'That request could not be verified.',
                ]);
            } else {
                try {
                    $setProcessed = $payoutStatus === 'completed'
                        ? ', processed_at = NOW()'
                        : ', processed_at = NULL';

                    $this->db->executeStatement(
                        'UPDATE payouts SET status = :s' . $setProcessed . ' WHERE id = :id',
                        ['s' => $payoutStatus, 'id' => $payoutId]
                    );
                    $this->addFlash('admin_payouts_flash', [
                        'type' => 'success',
                        'text' => 'Payout updated.',
                    ]);
                } catch (\Throwable $e) {
                    error_log('adminpayouts: update failed: ' . $e->getMessage());
                    $this->addFlash('admin_payouts_flash', [
                        'type' => 'error',
                        'text' => 'Could not update that payout.',
                    ]);
                }
            }

            return $this->redirectToRoute('app_admin_payouts', ['status' => $retStatus]);
        }

        /* =========================================================
           PLATFORM EARNINGS (commission) — over confirmed/completed
           bookings. Matches the legacy SQL exactly.
        ========================================================= */
        $platformEarnings = 0.0;
        $hostEarnings     = 0.0;
        try {
            $platformEarnings = (float) $this->db->fetchOne(
                "SELECT COALESCE(SUM(platform_fee_amount), 0)
                 FROM bookings
                 WHERE status IN ('confirmed', 'completed')"
            );
            $hostEarnings = (float) $this->db->fetchOne(
                "SELECT COALESCE(SUM(COALESCE(host_payout_amount, amount_paid)), 0)
                 FROM bookings
                 WHERE status IN ('confirmed', 'completed')"
            );
        } catch (\Throwable $e) {
            /* legacy swallowed this silently; stat cards will show ₱0.00 */
            error_log('adminpayouts: earnings query failed: ' . $e->getMessage());
        }

        /* =========================================================
           PAYOUTS LIST — with filter tab
           Try/catch guards against the `payouts` table not existing
           yet (legacy $payoutTableExists flag).
        ========================================================= */
        $payoutTableExists = true;

        $status = (string) $request->query->get('status', 'all');
        if (!in_array($status, self::VALID_FILTERS, true)) {
            $status = 'all';
        }

        $payouts = [];
        $sums    = [];
        try {
            $sql = "SELECT p.id, p.user_id, p.amount, p.method, p.status,
                           p.requested_at, p.processed_at,
                           u.name AS host_name
                    FROM payouts p
                    JOIN users u ON u.id = p.user_id";
            $params = [];
            if ($status !== 'all') {
                $sql .= " WHERE p.status = :st";
                $params['st'] = $status;
            }
            $sql .= " ORDER BY p.requested_at DESC LIMIT 100";
            $payouts = $this->db->fetchAllAssociative($sql, $params);

            $sumRows = $this->db->fetchAllKeyValue(
                "SELECT status, COALESCE(SUM(amount), 0) AS s
                 FROM payouts
                 GROUP BY status"
            );
            foreach ($sumRows as $k => $v) {
                $sums[$k] = (float) $v;
            }
        } catch (\Throwable $e) {
            /* table missing or columns renamed — render the empty state */
            $payoutTableExists = false;
            $sums = [];
            error_log('adminpayouts: payouts query failed: ' . $e->getMessage());
        }

        /* ---- Normalise per-row fields for the template ---- */
        $payouts = array_map(function (array $r): array {
            return [
                'id'            => (int) $r['id'],
                'host_name'     => (string) $r['host_name'],
                'method'        => (string) $r['method'],
                'amount'        => (float) $r['amount'],
                'status'        => (string) $r['status'],
                'status_label'  => ucfirst((string) $r['status']),
                'requested_at'  => (string) $r['requested_at'],
                'processed_at'  => (string) ($r['processed_at'] ?? ''),
            ];
        }, $payouts);

        $pendingAmt   = (float) ($sums['pending']    ?? 0)
            + (float) ($sums['processing'] ?? 0);
        $completedAmt = (float) ($sums['completed']  ?? 0);

        /* =========================================================
           COUNTS PER FILTER (for filter-tab badges)
           When the payouts table is missing every count is 0.
        ========================================================= */
        if ($payoutTableExists) {
            try {
                $allCounts = $this->db->fetchAllKeyValue(
                    'SELECT status, COUNT(*) AS c FROM payouts GROUP BY status'
                );
            } catch (\Throwable $e) {
                $allCounts = [];
            }
        } else {
            $allCounts = [];
        }

        $counts = [
            'all'        => array_sum(array_map('intval', $allCounts)),
            'pending'    => (int) ($allCounts['pending']    ?? 0),
            'processing' => (int) ($allCounts['processing'] ?? 0),
            'completed'  => (int) ($allCounts['completed']  ?? 0),
            'failed'     => (int) ($allCounts['failed']     ?? 0),
        ];

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* ---- Flash from the session (after redirect) ---- */
        $flash = null;
        $flashBag = $request->getSession()->getFlashBag()->get('admin_payouts_flash');
        if (!empty($flashBag)) {
            $flash = $flashBag[0];
        }

        return $this->render('admin/payouts.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'platform_earnings'  => $platformEarnings,
            'host_earnings'      => $hostEarnings,
            'pending_amount'    => $pendingAmt,
            'completed_amount'   => $completedAmt,

            'payouts'            => $payouts,
            'payout_table_exists' => $payoutTableExists,

            'status'             => $status,
            'valid_filters'      => self::VALID_FILTERS,
            'valid_statuses'     => self::VALID_STATUSES,
            'counts'             => $counts,
            'badge_class_map'    => self::BADGE_CLASS_MAP,
            'flash'              => $flash,
        ]);
    }

    /* =====================================================================
     *  NOTIFICATION COUNT — same helper used by every admin page so the
     *  bell badge stays consistent. Pending host applications + pending
     *  listings.
     * ================================================================= */

    private function countPending(): int
    {
        try {
            $hostApps = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM host_applications WHERE status = 'pending'"
            );
            $listings = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM listings WHERE status = 'pending'"
            );
            return $hostApps + $listings;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
