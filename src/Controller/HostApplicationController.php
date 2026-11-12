<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HostApplicationController extends AbstractController
{
    /* Step 2 destination — a route NAME, or an absolute PATH
       (starting with "/") if host-step2.php is still plain PHP. */
    private const STEP2 = 'app_host_step2';
    private const LOGIN = 'app_login';

    private const CSRF_ID = 'host_apply';

    public function __construct(
        private readonly Connection $db,
    ) {}

    #[Route('/host/become-a-host', name: 'app_host_apply', methods: ['GET', 'POST'])]
    public function apply(Request $request): Response
    {
        /* =====================================================
           SESSION AUTH — legacy keys, NO SecurityBundle.
           The rest of this app authenticates with plain session
           keys (logged_in / user_id / is_host — see PagesController),
           so this controller does the same.

           Do NOT call $this->getUser() here: it requires the
           SecurityBundle and throws
           "The SecurityBundle is not registered in your application".
           Same for isCsrfTokenValid() — replaced by the
           self-contained session token below.
        ====================================================== */
        $session = $request->getSession();

        if ($session->get('logged_in', false) !== true) {
            return $this->redirectToRoute(self::LOGIN);
        }

        $userId = (int) $session->get('user_id', 0);
        if ($userId <= 0) {
            $session->invalidate();
            return $this->redirectToRoute(self::LOGIN);
        }

        $userRow = $this->db->fetchAssociative(
            "SELECT id, name, email, avatar_path, location, is_host
             FROM users WHERE id = :id LIMIT 1",
            ['id' => $userId]
        );

        if ($userRow === false) {
            /* Orphaned session — force a clean login */
            $session->invalidate();
            return $this->redirectToRoute(self::LOGIN);
        }

        /* Already a host → straight to Step 2, never re-apply */
        if ((int) ($userRow['is_host'] ?? 0) === 1) {
            return $this->step2();
        }

        $errors = [];
        $old    = [];

        /* CSRF token — self-contained (session-stored), so the
           page works WITHOUT the SecurityBundle or security-csrf. */
        $csrf = $this->ensureCsrfToken($request);

        /* =====================================================
           POST — validate, save, redirect
        ====================================================== */
        if ($request->isMethod('POST')) {

            if (!$this->csrfValid($request, self::CSRF_ID, (string) $request->request->get('csrf_token', ''))) {
                $errors[] = 'Your session expired. Please try again.';
            }

            $old = [
                'full_name' => trim((string) $request->request->get('full_name', '')),
                'email'     => trim((string) $request->request->get('email', '')),
                'phone'     => trim((string) $request->request->get('phone', '')),
                'age'       => trim((string) $request->request->get('age', '')),
                'location'  => trim((string) $request->request->get('location', '')),
                'id_type'   => trim((string) $request->request->get('id_type', '')),
                'id_number' => trim((string) $request->request->get('id_number', '')),
            ];

            /* ---- Field validation (mirrors the legacy rules) ---- */
            if ($old['full_name'] === '') {
                $errors[] = 'Please enter your full name.';
            }

            if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }

            if ($old['phone'] === '') {
                $errors[] = 'Please enter your phone number.';
            } elseif (!preg_match('/^[0-9]{11}$/', $old['phone'])) {
                $errors[] = 'Phone number must be exactly 11 digits.';
            }

            if ($old['age'] === '' || !ctype_digit($old['age'])) {
                $errors[] = 'Please enter a valid age.';
            } elseif ((int) $old['age'] < 18) {
                $errors[] = 'You must be at least 18 years old to become a host.';
            } elseif ((int) $old['age'] > 120) {
                $errors[] = 'Please enter a valid age.';
            }

            if ($old['location'] === '') {
                $errors[] = 'Please enter your location.';
            }

            if ($old['id_type'] === '') {
                $errors[] = 'Please select your ID type.';
            }

            if ($old['id_number'] === '') {
                $errors[] = 'Please enter your ID number.';
            }

            /* ---- Duplicate email / phone among OTHER users'
                    ACTIVE applications (pending/approved) ---- */
            if ($old['email'] !== '' && filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
                $hit = $this->db->fetchOne(
                    "SELECT id FROM host_applications
                     WHERE email = :email AND user_id != :uid
                       AND status IN ('pending', 'approved')
                     LIMIT 1",
                    ['email' => $old['email'], 'uid' => $userId]
                );
                if ($hit) {
                    $errors[] = 'This email address is already registered to another host application.';
                }
            }

            if ($old['phone'] !== '') {
                $hit = $this->db->fetchOne(
                    "SELECT id FROM host_applications
                     WHERE phone = :phone AND user_id != :uid
                       AND status IN ('pending', 'approved')
                     LIMIT 1",
                    ['phone' => $old['phone'], 'uid' => $userId]
                );
                if ($hit) {
                    $errors[] = 'This phone number is already registered to another host application.';
                }
            }

            /* ---- ID upload validation ---- */
            $idFile = $request->files->get('upload_id');

            if (!$idFile instanceof UploadedFile || !$idFile->isValid()) {
                $errors[] = 'Please upload your ID.';
            } else {
                if ($idFile->getSize() > 5 * 1024 * 1024) {
                    $errors[] = 'ID file must not exceed 5MB.';
                }
                $mime = $idFile->getMimeType() ?: $idFile->getClientMimeType();
                if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                    $errors[] = 'Only JPG and PNG files are allowed.';
                }
            }

            /* =================================================
               IF VALID — update users, save ID, upsert application
            ================================================== */
            if (empty($errors)) {

                $this->db->update('users', [
                    'phone'    => $old['phone'],
                    'location' => $old['location'],
                    'age'      => (int) $old['age'],
                ], ['id' => $userId]);

                /* Save the uploaded ID to public/uploads/host_ids/ */
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/host_ids';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $ext = strtolower($idFile->getClientOriginalExtension() ?: 'jpg');
                $newFileName = 'host_' . time() . '_' . uniqid() . '.' . $ext;
                $idFile->move($uploadDir, $newFileName);
                $webPath = '/uploads/host_ids/' . $newFileName;

                /* ---- Reuse an existing application for this user
                        (Step 2's BACK link re-submits Step 1) —
                        session first, DB as fallback, matching
                        'approved' too. ---- */
                $existingId  = $session->get('host_application_id');
                $existingRow = null;

                if ($existingId !== null) {
                    $existingRow = $this->db->fetchAssociative(
                        'SELECT id, id_file FROM host_applications WHERE id = :id LIMIT 1',
                        ['id' => (int) $existingId]
                    );
                }

                if ($existingRow === null || $existingRow === false) {
                    $existingRow = $this->db->fetchAssociative(
                        "SELECT id, id_file FROM host_applications
                         WHERE user_id = :uid AND status IN ('pending', 'approved')
                         ORDER BY id DESC
                         LIMIT 1",
                        ['uid' => $userId]
                    );
                    $existingId = $existingRow !== false ? (int) $existingRow['id'] : null;
                } else {
                    $existingId = (int) $existingRow['id'];
                }

                if ($existingId !== null && $existingRow !== false) {

                    /* Update the application on file — no duplicates */
                    $this->db->executeStatement(
                        "UPDATE host_applications
                         SET full_name = :full_name,
                             email = :email,
                             phone = :phone,
                             age = :age,
                             location = :location,
                             id_type = :id_type,
                             id_number = :id_number,
                             id_file = :id_file,
                             status = CASE
                                        WHEN status = 'rejected' THEN 'pending'
                                        ELSE status
                                     END,
                             updated_at = NOW()
                         WHERE id = :id",
                        [
                            'full_name' => $old['full_name'],
                            'email'     => $old['email'],
                            'phone'     => $old['phone'],
                            'age'       => (int) $old['age'],
                            'location'  => $old['location'],
                            'id_type'   => $old['id_type'],
                            'id_number' => $old['id_number'],
                            'id_file'   => $webPath,
                            'id'        => $existingId,
                        ]
                    );

                    /* Delete the replaced ID file (guarded so a
                       non-uploads path can never be unlinked) */
                    $oldWeb = (string) ($existingRow['id_file'] ?? '');
                    if ($oldWeb !== '' && $oldWeb !== $webPath && str_starts_with($oldWeb, '/uploads/')) {
                        $oldFs = $this->getParameter('kernel.project_dir') . '/public' . $oldWeb;
                        if (is_file($oldFs)) {
                            @unlink($oldFs);
                        }
                    }
                } else {

                    /* First application for this user */
                    $this->db->insert('host_applications', [
                        'user_id'   => $userId,
                        'full_name' => $old['full_name'],
                        'email'     => $old['email'],
                        'phone'     => $old['phone'],
                        'age'       => (int) $old['age'],
                        'location'  => $old['location'],
                        'id_type'   => $old['id_type'],
                        'id_number' => $old['id_number'],
                        'id_file'   => $webPath,
                        'status'    => 'pending',
                    ]);
                    $existingId = (int) $this->db->lastInsertId();
                }

                /* ---- Session keys kept IDENTICAL to the legacy
                        page so Step 2 still finds them ---- */
                $session->set('host_application_id', $existingId);
                $session->set('host_application', [
                    'full_name' => $old['full_name'],
                    'email'     => $old['email'],
                    'phone'     => $old['phone'],
                    'age'       => (int) $old['age'],
                    'location'  => $old['location'],
                    'id_type'   => $old['id_type'],
                    'id_number' => $old['id_number'],
                    'id_file'   => $webPath,
                ]);

                $this->addFlash('success', 'Step 1 complete — now tell us about your space.');

                return $this->step2();
            }
        }

        /* =====================================================
           RENDER — old values win after a failed POST,
           otherwise prefill from the users row.
        ====================================================== */
        $form = [
            'full_name' => $old['full_name'] ?? (string) ($userRow['name'] ?? ''),
            'email'     => $old['email']     ?? (string) ($userRow['email'] ?? ''),
            'phone'     => $old['phone']     ?? '',
            'age'       => $old['age']       ?? '',
            'location'  => $old['location']  ?? trim((string) ($userRow['location'] ?? '')),
            'id_type'   => $old['id_type']   ?? '',
            'id_number' => $old['id_number'] ?? '',
        ];

        $hostSteps = [
            ['number' => 1, 'icon' => 'user-plus',      'alt' => 'Create Account', 'title' => 'Create Account', 'description' => 'Sign up as a host in minutes'],
            ['number' => 2, 'icon' => 'home',           'alt' => 'Add Your Space', 'title' => 'Add Your Space', 'description' => 'Tell us about your property'],
            ['number' => 3, 'icon' => 'image-plus',     'alt' => 'Upload Photos',  'title' => 'Upload Photos',  'description' => 'Show your space to attract renters'],
            ['number' => 4, 'icon' => 'calendar-check', 'alt' => 'Get Bookings',   'title' => 'Get Bookings',   'description' => 'Approve bookings and start earning'],
        ];

        return $this->render('host/becomeahost.html.twig', [
            'errors'          => $errors,
            'form'            => $form,
            'hostSteps'       => $hostSteps,
            'currentHostStep' => 1,
            'csrf'            => $csrf,
            /* Navbar partial overrides — snake_case, matching
               navbar.html.twig's variables */
            'is_logged_in'    => true,
            'nav_avatar'      => ($userRow['avatar_path'] ?? '') ?: '/images/default-avatar.png',
            'current_page'    => '/become-a-host',
        ]);
    }

    /* =========================================================
       SELF-CONTAINED CSRF (no SecurityBundle / security-csrf).
       One random token per ID, stored in the session; validated
       with hash_equals on POST. If you migrate to Symfony
       Security later, swap these for csrf_token()/
       isCsrfTokenValid().
    ========================================================== */
    private function ensureCsrfToken(Request $request): string
    {
        $session = $request->getSession();
        $tokens  = $session->get('_rh_csrf_tokens', []);

        if (!isset($tokens[self::CSRF_ID]) || !is_string($tokens[self::CSRF_ID])) {
            $tokens[self::CSRF_ID] = bin2hex(random_bytes(20));
            $session->set('_rh_csrf_tokens', $tokens);
        }

        return $tokens[self::CSRF_ID];
    }

    private function csrfValid(Request $request, string $id, string $token): bool
    {
        $tokens = $request->getSession()->get('_rh_csrf_tokens', []);

        return isset($tokens[$id])
            && is_string($tokens[$id])
            && $token !== ''
            && hash_equals($tokens[$id], $token);
    }

    private function step2(): Response
    {
        return str_starts_with(self::STEP2, '/')
            ? $this->redirect(self::STEP2)
            : $this->redirectToRoute(self::STEP2);
    }
}
