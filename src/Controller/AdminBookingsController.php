<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Bookings.
 * 1:1 port of legacy admin/adminbookings.php.
 *
 *   GET|POST /admin/bookings   → bookings list (filter tabs + search +
 *     pagination) + status override (POST with booking_id +
 *     update_status + csrf_token).
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * NOTE: this is an admin override tool — flipping a PAID booking
 *   to cancelled/rejected here moves no money (no wallet refund,
 *   no host clawback). It's for fixing stuck rows, not for normal
 *   cancellations.
 *
 * Filter tabs: All, Pending, Confirmed, Completed, Cancelled,
 *   Rejected — 'rejected' was added in the legacy fix so bookings
 *   declined by hosts (via the unified reject flow) are visible
 *   here as a first-class status.
 *
 * Search: matches listing title, guest name, host name, location.
 *
 * Pagination: 12 per page, LIKE-clause-safe params.
 */
class AdminBookingsController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    private const PER_PAGE = 12;

    /** Statuses the admin can set via the per-row select. */
    private const VALID_STATUSES = ['pending', 'confirmed', 'completed', 'cancelled', 'rejected'];

    /** Statuses the filter tabs can show. 'all' is special. */
    private const VALID_FILTERS = ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'rejected'];

    /** Map for the badge class on a status label. */
    private const BADGE_CLASS_MAP = [
        'Pending'   => 'badge-pending',
        'Confirmed' => 'badge-confirmed',
        'Completed' => 'badge-completed',
        'Cancelled' => 'badge-cancelled',
        'Rejected'  => 'badge-rejected',
    ];

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/bookings', name: 'app_admin_bookings', methods: ['GET', 'POST'])]
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
           STATUS UPDATE (POST)
           CSRF-verified; whitelist-constrained; redirects back
           to the same page/filter/page the admin was viewing.
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('update_status')) {
            $postedToken = (string) $request->request->get('csrf_token', '');
            $bookingId   = (int) $request->request->get('booking_id', 0);
            $newStatus   = (string) $request->request->get('update_status', '');

            if (!hash_equals($adminCsrf, $postedToken)) {
                $this->addFlash('admin_bookings_flash', [
                    'type' => 'error',
                    'text' => 'That request could not be verified.',
                ]);
            } elseif ($bookingId <= 0 || !in_array($newStatus, self::VALID_STATUSES, true)) {
                $this->addFlash('admin_bookings_flash', [
                    'type' => 'error',
                    'text' => 'Invalid booking or status.',
                ]);
            } else {
                try {
                    $this->db->executeStatement(
                        'UPDATE bookings SET status = :s WHERE id = :id',
                        ['s' => $newStatus, 'id' => $bookingId]
                    );
                    $this->addFlash('admin_bookings_flash', [
                        'type' => 'success',
                        'text' => 'Booking #' . $bookingId . ' marked ' . ucfirst($newStatus) . '.',
                    ]);
                } catch (\Throwable $e) {
                    error_log('adminbookings: status update failed: ' . $e->getMessage());
                    $this->addFlash('admin_bookings_flash', [
                        'type' => 'error',
                        'text' => 'Could not update that booking. Please try again.',
                    ]);
                }
            }

            /* Redirect back to the page the admin was viewing */
            return $this->redirectToRoute('app_admin_bookings', [
                'status' => (string) $request->request->get('ret_status', 'all'),
                'q'      => (string) $request->request->get('ret_q', ''),
                'page'   => (int) $request->request->get('ret_page', 1),
            ]);
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        /* =========================================================
           FILTERS — status tabs + search + pagination
        ========================================================= */
        $status = (string) $request->query->get('status', 'all');
        if (!in_array($status, self::VALID_FILTERS, true)) {
            $status = 'all';
        }
        $q    = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $where  = [];
        $params = [];

        if ($status !== 'all') {
            $where[]        = 'b.status = :st';
            $params['st']   = $status;
        }
        if ($q !== '') {
            $where[] = '(l.title LIKE :q OR g.name LIKE :q OR hh.name LIKE :q OR l.location LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        /* ---- Total count for pagination ---- */
        $totalItems = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM bookings b
             JOIN listings l ON l.id = b.listing_id
             JOIN users g ON g.id = b.user_id
             JOIN users hh ON hh.id = l.user_id
             $whereSql",
            $params
        );
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * self::PER_PAGE;

        /* ---- Page rows ----
           NOTE: bookings has NO payment_method column — the real
           column is payment_status (enum: pending/paid/failed/
           cancelled). Mirrors the legacy query exactly. */
        $bookings = $this->db->fetchAllAssociative(
            "SELECT b.id, b.status, b.amount_paid, b.checkin_date, b.checkout_date,
                    b.guests, b.created_at, b.payment_status,
                    l.title, l.location,
                    g.name AS guest_name,
                    hh.name AS host_name
             FROM bookings b
             JOIN listings l ON l.id = b.listing_id
             JOIN users g ON g.id = b.user_id
             JOIN users hh ON hh.id = l.user_id
             $whereSql
             ORDER BY b.created_at DESC
             LIMIT " . self::PER_PAGE . ' OFFSET ' . $offset,
            $params
        );

        /* ---- Per-status counts for the filter tabs ---- */
        $countRows = $this->db->fetchAllKeyValue(
            'SELECT status, COUNT(*) AS c FROM bookings GROUP BY status'
        );
        $counts = [];
        foreach ($countRows as $k => $v) {
            $counts[(string) $k] = (int) $v;
        }
        $cnt    = fn(string $k): int => $counts[$k] ?? 0;
        $cntAll = array_sum($counts);

        /* ---- Flash from the session (after redirect) ---- */
        $flash = null;
        $flashBag = $request->getSession()->getFlashBag()->get('admin_bookings_flash');
        if (!empty($flashBag)) {
            $flash = $flashBag[0];
        }

        return $this->render('admin/bookings.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'bookings'      => $bookings,
            'status'        => $status,
            'q'              => $q,
            'page'           => $page,
            'total_pages'    => $totalPages,

            'cnt_all'        => $cntAll,
            'cnt_pending'    => $cnt('pending'),
            'cnt_confirmed'  => $cnt('confirmed'),
            'cnt_completed'  => $cnt('completed'),
            'cnt_cancelled'  => $cnt('cancelled'),
            'cnt_rejected'   => $cnt('rejected'),

            'badge_class_map' => self::BADGE_CLASS_MAP,
            'valid_statuses' => self::VALID_STATUSES,
            'flash'          => $flash,
        ]);
    }

    /* =====================================================================
     *  NOTIFICATIONS badge count for the topbar bell.
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
