<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host profile photo upload.
 * Port of legacy uploadavatar.php for the Host Profile (Overview) page.
 *
 *   POST /host/profile/avatar    (multipart form field: "avatar")
 *
 * The camera button on host/profile.html.twig posts here. JSON
 * contract expected by that page's JS (do not change the key names):
 *     {login: true}                        → session expired; JS redirects to /login
 *     {success: true, avatar_url: "..."}   → ok; JS swaps the <img> src
 *     {error: "message"}                   → JS alert()s it
 *
 * Storage contract is identical to ProfileController::uploadAvatar
 * (POST /profile/avatar — the tenant-side port of the same legacy
 * file): public/uploads/avatars/avatar-{userId}-{timestamp}.{ext},
 * users.avatar_path updated, session avatar_path refreshed so the
 * navbar chip follows on the next full page load.
 *
 * Auth: any logged-in user — legacy uploadavatar.php was ONE shared
 * endpoint for tenants and hosts. This page happens to be host-only,
 * but the endpoint itself deliberately carries no host guard.
 */
class HostProfileAvatarController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/host/profile/avatar', name: 'app_host_profile_avatar', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $session = $request->getSession();

        /* ---- Auth gate — mirrors the JS {login: true} contract ---- */
        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->json(['login' => true]);
        }

        /* ---- File gate ---- */
        $file = $request->files->get('avatar');
        if (!$file) {
            return $this->json(['error' => 'No file received.'], 400);
        }
        if (!$file->isValid()) {
            return $this->json(['error' => 'Upload failed — please try again.'], 400);
        }

        /* Same allow-list as ProfileController::uploadAvatar; the page's
           JS pre-validates the same three types and the 5MB ceiling. */
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = (string) $file->getClientMimeType();
        if (!isset($allowed[$mime])) {
            return $this->json(['error' => 'Only JPG, PNG, or WEBP images are allowed.'], 400);
        }
        if ((int) $file->getSize() > 5 * 1024 * 1024) {
            return $this->json(['error' => 'That image is too large. Maximum is 5MB.'], 400);
        }

        try {
            /* ---- Store: public/uploads/avatars/avatar-{id}-{ts}.{ext} ---- */
            $dir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $name = 'avatar-' . $userId . '-' . time() . '.' . $allowed[$mime];
            $file->move($dir, $name);
            $url = '/uploads/avatars/' . $name;

            /* ---- Persist. avatar_path exists in the live schema (the host
               profile page already SELECTs it), but keep the try/catch —
               the session refresh below keeps the UI correct regardless. */
            try {
                $this->db->update('users', ['avatar_path' => $url], ['id' => $userId]);
            } catch (\Throwable $e) {
                /* column missing on a very old dump — non-fatal */
            }

            $session->set('avatar_path', $url);

            return $this->json(['success' => true, 'avatar_url' => $url]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Could not save the file. Please try again.'], 500);
        }
    }
}
