<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Staff Auth (login).
 *
 *   GET  /staff/login → renders templates/staff/login.html.twig
 *   POST /staff/login → any users row with role='staff' + status='active'
 *                       + a passing password_verify() signs in, then
 *                       lands on /admin (the same dashboard as the admin).
 *
 * SESSION SHAPE (staff reuses the whole admin shell):
 *   admin_logged_in = true
 *   admin_name / admin_email
 *   admin_csrf (rotated on every sign-in)
 *   admin_role = 'staff'
 *
 * Drop into: src/Controller/StaffAuthController.php
 * After dropping in: php bin/console cache:clear
 */
class StaffAuthController extends AbstractController
{
    private const BOOTSTRAP_EMAIL = 'staff1@roomhive.com';
    private const BOOTSTRAP_NAME  = 'Staff 1';

    public function __construct(private readonly Connection $db) {}

    /** Optional: put STAFF_BOOTSTRAP_PASSWORD=... in .env.local */
    private function bootstrapPassword(): string
    {
        $env = $_ENV['STAFF_BOOTSTRAP_PASSWORD'] ?? $_SERVER['STAFF_BOOTSTRAP_PASSWORD'] ?? '';
        return $env !== '' ? (string) $env : 'kimzkie0978';
    }

    #[Route('/staff/login', name: 'app_staff_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $session = $request->getSession();

        // Already signed in (admin OR staff)? Straight to the dashboard.
        if ($session->get('admin_logged_in')) {
            $dest = $session->get('admin_role') === 'staff' ? '/staff' : '/admin';
            return $this->redirect($dest);
        }

        $this->ensureRoleColumn();

        $staffCsrf = (string) $session->get('staff_csrf');
        if ($staffCsrf === '') {
            $staffCsrf = bin2hex(random_bytes(32));
            $session->set('staff_csrf', $staffCsrf);
        }

        $error    = '';
        $oldEmail = '';

        if ($request->isMethod('POST')) {
            $oldEmail    = trim((string) $request->request->get('email', ''));
            $password    = (string) $request->request->get('password', '');
            $postedToken = (string) $request->request->get(
                '_token',
                $request->request->get('csrf_token', '')
            );

            if (!hash_equals($staffCsrf, $postedToken)) {
                $error = 'Your session expired. Please try again.';
            } elseif ($oldEmail === '' || $password === '') {
                $error = 'Please enter your staff email and password.';
            } else {
                $email = strtolower($oldEmail);
                if ($email === self::BOOTSTRAP_EMAIL) {
                    $this->ensureBootstrapStaffRow();
                }

                try {
                    $user = $this->db->fetchAssociative(
                        "SELECT id, name, email, password, status, role
                         FROM users WHERE email = :email LIMIT 1",
                        ['email' => $email]
                    );
                } catch (\Throwable $e) {
                    error_log('staff login: lookup failed: ' . $e->getMessage());
                    $user  = false;
                    $error = 'Staff sign-in is unavailable right now. Please try again.';
                }

                if ($error === '') {
                    if (
                        !$user
                        || ($user['status'] ?? 'active') !== 'active'
                        || (string) ($user['role'] ?? '') !== 'staff'
                        || !password_verify($password, (string) $user['password'])
                    ) {
                        $error = 'Incorrect staff email or password.';
                    } else {
                        $session->set('admin_logged_in', true);
                        $session->set('admin_name', (string) $user['name']);
                        $session->set('admin_email', (string) $user['email']);
                        $session->set('admin_role', 'staff');
                        $session->set('admin_csrf', bin2hex(random_bytes(32)));

                        return $this->redirect('/staff');
                    }
                }
            }
        }

        return $this->render('staff/login.html.twig', [
            'error'     => $error,
            'old_email' => $oldEmail,
            'csrf'      => $staffCsrf,
        ]);
    }

    private function ensureRoleColumn(): void
    {
        try {
            if ($this->db->fetchOne("SHOW COLUMNS FROM users LIKE 'role'") === false) {
                $this->db->executeStatement(
                    "ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'"
                );
            }
        } catch (\Throwable $e) {
            error_log('staff login: role column self-heal failed: ' . $e->getMessage());
        }
    }

    private function ensureBootstrapStaffRow(): void
    {
        try {
            $existing = $this->db->fetchAssociative(
                'SELECT id, role FROM users WHERE email = :email LIMIT 1',
                ['email' => self::BOOTSTRAP_EMAIL]
            );

            if (!$existing) {
                $this->db->executeStatement(
                    "INSERT INTO users (name, email, password, role, status)
                     VALUES (:name, :email, :password, 'staff', 'active')",
                    [
                        'name'     => self::BOOTSTRAP_NAME,
                        'email'    => self::BOOTSTRAP_EMAIL,
                        'password' => password_hash($this->bootstrapPassword(), PASSWORD_BCRYPT),
                    ]
                );
            } elseif ((string) ($existing['role'] ?? '') !== 'staff') {
                $this->db->executeStatement(
                    "UPDATE users SET role = 'staff' WHERE email = :email",
                    ['email' => self::BOOTSTRAP_EMAIL]
                );
            }
        } catch (\Throwable $e) {
            error_log('staff login: bootstrap row heal skipped: ' . $e->getMessage());
        }
    }
}
