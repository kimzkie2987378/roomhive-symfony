<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminStaffController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/staff', name: 'app_admin_staff', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->get('admin_logged_in')) {
            return $this->redirect('/admin/login');
        }

        // Only Administrator can manage staff
        if ($session->get('admin_role') === 'staff') {
            return $this->redirect('/staff');
        }

        $adminName  = (string) ($session->get('admin_name')  ?? 'Admin User');
        $adminEmail = (string) ($session->get('admin_email') ?? '');

        $adminCsrf = (string) $session->get(self::CSRF_KEY);
        if ($adminCsrf === '') {
            $adminCsrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $adminCsrf);
        }

        $isAjax = strtolower($request->headers->get('X-Requested-With', '')) === 'xmlhttprequest'
            || str_contains((string) $request->headers->get('Accept'), 'application/json');

        /* =========================================================
           ACTIONS (POST)
        ========================================================= */
        if ($request->isMethod('POST')) {
            $postedToken = (string) $request->request->get('csrf_token', '');
            if (!hash_equals($adminCsrf, $postedToken)) {
                if ($isAjax) {
                    return new JsonResponse(['success' => false, 'error' => 'Session expired. Please try again.'], 400);
                }
                $this->addFlash('error', 'Session expired. Please try again.');
                return $this->redirect('/admin/staff');
            }

            $action = (string) $request->request->get('action', '');

            /* ---- 1. ADD NEW STAFF ---- */
            if ($action === 'add') {
                $name     = trim((string) $request->request->get('name', ''));
                $email    = strtolower(trim((string) $request->request->get('email', '')));
                $password = trim((string) $request->request->get('password', ''));

                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $err = 'Please provide a valid staff email address.';
                    if ($isAjax) {
                        return new JsonResponse(['success' => false, 'error' => $err], 422);
                    }
                    $this->addFlash('error', $err);
                    return $this->redirect('/admin/staff');
                }

                if ($name === '') {
                    $name = ucfirst(explode('@', $email)[0]);
                }

                // If password left blank, auto-generate a secure random 10-char password
                if ($password === '') {
                    $password = 'stf_' . substr(bin2hex(random_bytes(4)), 0, 6);
                }

                // Check uniqueness in users table
                $exists = $this->db->fetchOne(
                    "SELECT id FROM users WHERE email = :email LIMIT 1",
                    ['email' => $email]
                );
                if ($exists) {
                    $err = 'An account with this email already exists.';
                    if ($isAjax) {
                        return new JsonResponse(['success' => false, 'error' => $err], 422);
                    }
                    $this->addFlash('error', $err);
                    return $this->redirect('/admin/staff');
                }

                try {
                    $this->db->executeStatement(
                        "INSERT INTO users (name, email, password, role, status, created_at)
                         VALUES (:name, :email, :password, 'staff', 'active', NOW())",
                        [
                            'name'     => $name,
                            'email'    => $email,
                            'password' => password_hash($password, PASSWORD_BCRYPT),
                        ]
                    );

                    $staffData = [
                        'name'     => $name,
                        'email'    => $email,
                        'password' => $password,
                    ];

                    if ($isAjax) {
                        return new JsonResponse([
                            'success' => true,
                            'message' => 'Staff account created successfully.',
                            'staff'   => $staffData,
                        ]);
                    }

                    $session->set('new_staff_credentials', $staffData);
                    $this->addFlash('success', 'Staff account created successfully.');
                } catch (\Throwable $e) {
                    error_log('AdminStaffController: add failed: ' . $e->getMessage());
                    $err = 'Failed to create staff account. Please try again.';
                    if ($isAjax) {
                        return new JsonResponse(['success' => false, 'error' => $err], 500);
                    }
                    $this->addFlash('error', $err);
                }

                return $this->redirect('/admin/staff');
            }

            /* ---- 2. DELETE STAFF ---- */
            if ($action === 'delete') {
                $staffId = (int) $request->request->get('staff_id', 0);
                if ($staffId > 0) {
                    try {
                        $this->db->executeStatement(
                            "DELETE FROM users WHERE id = :id AND role = 'staff'",
                            ['id' => $staffId]
                        );

                        if ($isAjax) {
                            return new JsonResponse(['success' => true, 'message' => 'Staff member deleted.']);
                        }
                        $this->addFlash('success', 'Staff member deleted.');
                    } catch (\Throwable $e) {
                        error_log('AdminStaffController: delete failed: ' . $e->getMessage());
                        if ($isAjax) {
                            return new JsonResponse(['success' => false, 'error' => 'Delete failed.'], 500);
                        }
                        $this->addFlash('error', 'Delete failed.');
                    }
                }
                return $this->redirect('/admin/staff');
            }

            /* ---- 3. TOGGLE STATUS ---- */
            if ($action === 'toggle_status') {
                $staffId = (int) $request->request->get('staff_id', 0);
                if ($staffId > 0) {
                    try {
                        $this->db->executeStatement(
                            "UPDATE users SET status = IF(status = 'active', 'inactive', 'active')
                             WHERE id = :id AND role = 'staff'",
                            ['id' => $staffId]
                        );

                        if ($isAjax) {
                            return new JsonResponse(['success' => true, 'message' => 'Staff status updated.']);
                        }
                        $this->addFlash('success', 'Staff status updated.');
                    } catch (\Throwable $e) {
                        error_log('AdminStaffController: status update failed: ' . $e->getMessage());
                    }
                }
                return $this->redirect('/admin/staff');
            }
        }

        /* =========================================================
           LOAD ALL STAFF MEMBERS
        ========================================================= */
        $staffMembers = $this->db->fetchAllAssociative(
            "SELECT id, name, email, status, avatar_path, created_at
             FROM users
             WHERE role = 'staff'
             ORDER BY id DESC"
        );

        $totalStaff    = count($staffMembers);
        $activeStaff   = 0;
        $inactiveStaff = 0;

        foreach ($staffMembers as $s) {
            if (($s['status'] ?? 'active') === 'active') {
                $activeStaff++;
            } else {
                $inactiveStaff++;
            }
        }

        // Notification count for the bell
        $notificationCount = 0;
        try {
            $notificationCount = (int) $this->db->fetchOne(
                "SELECT (SELECT COUNT(*) FROM host_applications WHERE status = 'pending')
                      + (SELECT COUNT(*) FROM listings WHERE status = 'pending')"
            );
        } catch (\Throwable $e) {}

        // Check if a newly added staff has credentials to display
        $newStaffCredentials = $session->get('new_staff_credentials', null);
        if ($newStaffCredentials !== null) {
            $session->remove('new_staff_credentials');
        }

        return $this->render('admin/staff.html.twig', [
            'admin_name'            => $adminName,
            'admin_email'           => $adminEmail,
            'admin_csrf'            => $adminCsrf,
            'notification_count'    => $notificationCount,
            'staff_members'         => $staffMembers,
            'total_staff'           => $totalStaff,
            'active_staff'          => $activeStaff,
            'inactive_staff'        => $inactiveStaff,
            'new_staff_credentials' => $newStaffCredentials,
        ]);
    }
}
