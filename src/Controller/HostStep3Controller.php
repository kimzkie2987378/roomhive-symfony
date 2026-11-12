<?php

/*
 * =========================================================
 * ROOMHIVE — BECOME A HOST (STEP 3) · UPLOAD PHOTOS
 * src/Controller/HostStep3Controller.php
 * =========================================================
 * Symfony port of the legacy host-step3.php controller block
 * (template: templates/host/host_step3.html.twig).
 *
 * THE FIX for  "No route found for GET /host/step-3 (from
 * /host/step-2)":  Step 2 now redirects to /host/step-3 but
 * nothing served that URL. This controller registers it —
 * and because the form self-posts (action="") to the URL the
 * page is served at, the SAME route must accept GET and POST
 * (methods: ['GET','POST']) or the submit 404s.
 *
 * Contract (host_step3.html.twig docstring):
 *   - Plain-session auth (logged_in / user_id) — no
 *     SecurityBundle, same as HostStep2Controller.
 *   - STEP 1&2 CHECK 1:1 with legacy: session host_application
 *     must carry BOTH 'title' AND 'listing_id' (written by
 *     Step 2), else redirect /host/become-a-host.
 *   - Self-contained CSRF via the _rh_csrf_tokens session store
 *     (token id 'host_step3'), validated with hash_equals.
 *   - POST: cover_photo REQUIRED (<= 5MB, JPG/PNG),
 *     additional_photos[] up to 20 files (each <= 5MB, JPG/PNG)
 *     — same messages as legacy, same check order.
 *   - On success: move files into public/uploads/listing_photos/
 *     cover/ and .../additional/ as cover_{time}_{uniqid}.{ext}
 *     / photo_{time}_{uniqid}.{ext}, INSERT one listing_photos
 *     row per file (listing_id, photo_path, photo_type,
 *     sort_order — cover first at 0, additional 1..n, DB paths
 *     relative like legacy), store the paths in session
 *     host_application (cover_photo / additional_photos), then
 *     redirect /host/step-4 (their /host/step-N convention).
 *   - On failure: re-render with errors (file inputs cannot be
 *     re-populated by any server, so there is no `old` here).
 * =========================================================
 */

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

class HostStep3Controller extends AbstractController
{
    private Connection $conn;

    /* =====================================================
       UPLOAD CONFIG — same values as the legacy page
       ($maxAdditionalPhotos / $initialPhotoSlots /
       $allowedTypes / $maxFileSize, verbatim).
       ===================================================== */

    private const MAX_ADDITIONAL_PHOTOS = 20;

    private const INITIAL_PHOTO_SLOTS = 10;

    private const ALLOWED_TYPES = [
        'image/jpeg',
        'image/png',
    ];

    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    // The route URL must match the URL the page is served at — the form
    // self-posts (action="") to the current URL. Live URL: /host/step-3.
    #[Route('/host/step-3', name: 'app_host_step3', methods: ['GET', 'POST'])]
    public function step3(Request $request): Response
    {
        $session = $request->getSession();

        /* =====================================================
           AUTHENTICATION CHECK (legacy 1:1 — plain session)
           ===================================================== */

        $userId = (int) $session->get('user_id');

        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirect('/login');
        }

        /* =====================================================
           STEP 1 & STEP 2 CHECK — legacy 1:1: the session copy
           of host_application must already carry 'title' AND
           'listing_id' (both written by a successful Step 2
           POST). No DB restore here — the legacy step 3 page
           redirects straight back to Step 1 without one.
           ===================================================== */

        $hostApplication = $session->get('host_application');

        if (
            !is_array($hostApplication)
            || !isset($hostApplication['title'])
            || !isset($hostApplication['listing_id'])
        ) {
            return $this->redirect('/host/become-a-host');
        }

        /* =====================================================
           CSRF — self-contained (same _rh_csrf_tokens store as
           Step 1 / Step 2 / Settings). The template posts
           csrf_token.
           ===================================================== */

        $csrfHostStep3 = $this->ensureCsrfToken($session, 'host_step3');

        $errors = [];

        /* =====================================================
           POST — CSRF + FILE VALIDATION (messages and check
           order 1:1 with the legacy page)
           ===================================================== */

        if ($request->isMethod('POST')) {

            if (!$this->csrfValid($session, 'host_step3', $this->postedToken($request))) {
                $errors[] = 'Your session expired. Please try again.';
            }

            // =====================================================
            // COVER PHOTO VALIDATION
            // =====================================================

            // Legacy read $_FILES['cover_photo'] and checked
            // error !== UPLOAD_ERR_OK — an UploadedFile that is
            // null or !isValid() is exactly that condition (PHP
            // itself rejects anything past upload_max_filesize).
            $coverFile = $request->files->get('cover_photo');

            if (!$coverFile instanceof UploadedFile || !$coverFile->isValid()) {

                $errors[] = 'Please upload a cover photo for your space.';
            } else {

                if ($coverFile->getSize() > self::MAX_FILE_SIZE) {

                    $errors[] = 'Cover photo must not exceed 5MB.';
                }

                // Legacy read $_FILES['...']['type'] — the client
                // mime — so the Symfony twin is getClientMimeType().
                if (!in_array($coverFile->getClientMimeType(), self::ALLOWED_TYPES, true)) {

                    $errors[] = 'Cover photo must be a JPG or PNG file.';
                }
            }

            // =====================================================
            // ADDITIONAL PHOTOS VALIDATION
            // =====================================================

            $additionalFiles = [];

            // Symfony's files bag already returns one UploadedFile
            // per additional_photos[] slot (null when the field is
            // absent) — the legacy is_array(count) guard becomes a
            // plain array-wrap for safety.
            $postedAdditional = $request->files->get('additional_photos', []);

            if (!is_array($postedAdditional)) {
                $postedAdditional = [$postedAdditional];
            }

            $additionalCount = count($postedAdditional);

            if ($additionalCount > self::MAX_ADDITIONAL_PHOTOS) {

                $errors[] = 'You can upload a maximum of ' . self::MAX_ADDITIONAL_PHOTOS . ' additional photos.';
            }

            foreach ($postedAdditional as $file) {

                if (!$file instanceof UploadedFile || !$file->isValid()) {
                    continue;
                }

                if ($file->getSize() > self::MAX_FILE_SIZE) {

                    $errors[] = 'Each additional photo must not exceed 5MB.';

                    break;
                }

                if (!in_array($file->getClientMimeType(), self::ALLOWED_TYPES, true)) {

                    $errors[] = 'Additional photos must be JPG or PNG files.';

                    break;
                }

                $additionalFiles[] = $file;
            }

            /* =================================================
               IF VALID — MOVE FILES + INSERT (Symfony twin of
               the legacy move_uploaded_file block)
               ================================================= */

            if (!$errors) {

                $listingId = (int) $hostApplication['listing_id'];

                try {

                    // Legacy: $_SERVER['DOCUMENT_ROOT'] . '/webprogg/uploads/listing_photos/...'
                    // Symfony: <project>/public/uploads/listing_photos/...
                    $uploadBase = $this->getParameter('kernel.project_dir') . '/public/uploads/listing_photos';

                    $coverUploadDirectory      = $uploadBase . '/cover';
                    $additionalUploadDirectory = $uploadBase . '/additional';

                    if (!is_dir($coverUploadDirectory)) {

                        @mkdir($coverUploadDirectory, 0755, true);
                    }

                    if (!is_dir($additionalUploadDirectory)) {

                        @mkdir($additionalUploadDirectory, 0755, true);
                    }

                    // ---- Cover photo ----

                    $coverExtension = strtolower($coverFile->getClientOriginalExtension());

                    $coverFileName =
                        'cover_' .
                        time() .
                        '_' .
                        uniqid() .
                        '.' .
                        $coverExtension;

                    $coverDbPath = 'uploads/listing_photos/cover/' . $coverFileName;

                    // move() throws on failure where legacy's
                    // move_uploaded_file() returned false — the
                    // try/catch keeps the same "skip on failure"
                    // behaviour per file.
                    try {

                        $coverFile->move($coverUploadDirectory, $coverFileName);

                        $this->conn->executeStatement(
                            "INSERT INTO listing_photos (listing_id, photo_path, photo_type, sort_order)
                             VALUES (:listing_id, :photo_path, 'cover', 0)",
                            [
                                'listing_id' => $listingId,
                                'photo_path' => $coverDbPath,
                            ]
                        );

                        $hostApplication['cover_photo'] = $coverDbPath;
                    } catch (\Throwable $moveError) {
                        error_log('host-step3 cover move/INSERT failed: ' . $moveError->getMessage());
                    }

                    // ---- Additional photos ----

                    $savedAdditionalPaths = [];

                    $sortOrder = 1;

                    foreach ($additionalFiles as $file) {

                        $extension = strtolower($file->getClientOriginalExtension());

                        $fileName =
                            'photo_' .
                            time() .
                            '_' .
                            uniqid() .
                            '.' .
                            $extension;

                        $dbPath = 'uploads/listing_photos/additional/' . $fileName;

                        try {

                            $file->move($additionalUploadDirectory, $fileName);

                            $this->conn->executeStatement(
                                "INSERT INTO listing_photos (listing_id, photo_path, photo_type, sort_order)
                                 VALUES (:listing_id, :photo_path, 'additional', :sort_order)",
                                [
                                    'listing_id' => $listingId,
                                    'photo_path' => $dbPath,
                                    'sort_order' => $sortOrder,
                                ]
                            );

                            $savedAdditionalPaths[] = $dbPath;

                            $sortOrder++;
                        } catch (\Throwable $moveError) {
                            error_log('host-step3 additional move/INSERT failed: ' . $moveError->getMessage());
                        }
                    }

                    $hostApplication['additional_photos'] = $savedAdditionalPaths;

                    $session->set('host_application', $hostApplication);

                    // Go to next host registration step. Their URL
                    // convention is /host/step-N (Step 3 =
                    // /host/step-3) — adjust if your Step 4 route differs.
                    return $this->redirect('/host/step-4');
                } catch (\Throwable $e) {
                    error_log('host-step3 upload failed: ' . $e->getMessage());
                    $errors[] = 'Could not save your photos. Please try again.';
                }
            }
        }

        /* =====================================================
           USER ROW FOR THE NAVBAR (superset — avatar_path AND
           avatar present, the ProfileController lesson)
           ===================================================== */

        $userRow = null;
        try {
            $userRow = $this->conn->fetchAssociative(
                'SELECT id, name, email, avatar_path, is_host
                   FROM users WHERE id = :id LIMIT 1',
                ['id' => $userId]
            );
        } catch (\Throwable) {
            $userRow = null;
        }

        $avatarPath = (is_array($userRow) && !empty($userRow['avatar_path']))
            ? (string) $userRow['avatar_path']
            : '';

        $user = [
            'id'          => $userId,
            'name'        => (is_array($userRow) && !empty($userRow['name']))
                ? (string) $userRow['name']
                : (string) ($session->get('user_name') ?? 'User'),
            'email'       => is_array($userRow) ? (string) ($userRow['email'] ?? '') : '',
            'avatar_path' => $avatarPath,
            'avatar'      => $avatarPath !== '' ? $avatarPath : '/images/default-avatar.png',
            'is_host'     => (int) (is_array($userRow)
                ? ($userRow['is_host'] ?? 0)
                : ($session->get('is_host') ? 1 : 0)),
        ];

        /* =====================================================
           RENDER (failed POST falls through to here with the
           error list; file inputs cannot be re-populated)
           ===================================================== */

        $notifCount = $this->notifCount($userId);

        return $this->render('host/host_step3.html.twig', [
            // Navbar dual-contract vars (the partial ?? guards read these)
            'user'                => $user,
            'is_host'             => (bool) $session->get('is_host'),
            'nav_avatar'          => $user['avatar'],
            'notification_count'  => $notifCount,

            // Page contract (host_step3.html.twig docstring)
            'errors'              => $errors,
            'max_additional_photos' => self::MAX_ADDITIONAL_PHOTOS,
            'initial_photo_slots' => self::INITIAL_PHOTO_SLOTS,
            'notif_count'         => $notifCount,
            'csrf_host_step3'     => $csrfHostStep3,
        ]);
    }

    /* =========================================================
       HELPERS — same implementations as SecurityController /
       HostStep2Controller / WalletController (kept identical
       across controllers)
       ========================================================= */

    private function ensureCsrfToken(SessionInterface $session, string $key): string
    {
        $tokens = $session->get('_rh_csrf_tokens', []);
        if (!isset($tokens[$key])) {
            $tokens[$key] = bin2hex(random_bytes(16));
            $session->set('_rh_csrf_tokens', $tokens);
        }

        return (string) $tokens[$key];
    }

    private function csrfValid(SessionInterface $session, string $key, ?string $token): bool
    {
        $tokens = $session->get('_rh_csrf_tokens', []);

        return isset($tokens[$key]) && is_string($token) && hash_equals((string) $tokens[$key], $token);
    }

    /** Accepts both csrf_token and _token form field names. */
    private function postedToken(Request $request): ?string
    {
        $t = $request->request->get('csrf_token');
        if (!is_string($t) || $t === '') {
            $t = $request->request->get('_token');
        }

        return is_string($t) ? $t : null;
    }

    private function notifCount(int $userId): int
    {
        try {
            return (int) $this->conn->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0',
                ['id' => $userId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}
