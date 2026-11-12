<?php

/*
 * =========================================================
 * ROOMHIVE — BECOME A HOST (STEP 4) · SPACE SUBMITTED
 * src/Controller/HostStep4Controller.php
 * =========================================================
 * Symfony port of the legacy host-step4.php controller block
 * (template: templates/host/host_step4.html.twig).
 *
 * THE FIX for  "No route found for GET /host/step-4":  Step 3
 * now redirects to /host/step-4 (cont-53 redirect target)
 * but nothing served that URL. This controller registers it.
 * The page is GET-only (no form, no POST), so the route only
 * needs methods: ['GET'].
 *
 * Contract (host_step4.html.twig docstring):
 *   - Plain-session auth (logged_in / user_id) — no
 *     SecurityBundle, same as HostStep2/3Controller.
 *   - STEP 2 & STEP 3 CHECK legacy 1:1: session
 *     host_application must carry BOTH 'listing_id' (written
 *     by Step 2's INSERT) AND 'cover_photo' (written by Step
 *     3's move+INSERT), else redirect /host/become-a-host.
 *   - is_host_approved = (bool) session->get('is_host') —
 *     the legacy $isHostApproved gate. True only once an
 *     admin approves the underlying host_applications row.
 *   - FINALIZE (runs once per session): if
 *     !host_application['finalized'] →
 *         UPDATE listings SET status='pending' WHERE id = :id
 *         host_application['finalized'] = true; persist.
 *     This flips the row written by Step 2 from 'draft' to
 *     'pending' so it shows up in the admin review queue. It
 *     does NOT touch is_host — that only flips when an admin
 *     approves the host_applications row.
 *   - No CSRF (no form, no POST). The page is a status page.
 *   - On success: render host_step4.html.twig with the vars
 *     below.
 *
 * Render vars:
 *   user                    { id, name, email, avatar_path,
 *                             avatar, is_host }  (navbar contract)
 *   is_host                bool — navbar dual-contract
 *   nav_avatar             string — navbar dual-contract
 *   notification_count     int — navbar badge
 *   notif_count            int — page contract alias
 *   is_host_approved       bool — picks the hero+actions branch
 *   step_progress_percent  int  (0..75) — stepper fill target
 *   full_name              string — display-name fallback
 *                           (host_application.full_name ?? user.name)
 * =========================================================
 */

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HostStep4Controller extends AbstractController
{
    private Connection $conn;

    /* =====================================================
       STATIC CONFIG — the 4-step stepper. The legacy page
       computed stepProgress as ((currentStep-1)/count)*100,
       clamped to max 75 (because .hs-stepper-track spans
       12.5%..87.5% = 75% of the container; 3 of 4 steps
       done fills the whole track → 75%).
       ===================================================== */

    private const CURRENT_STEP      = 4;
    private const TOTAL_STEPS       = 4;
    private const STEP_PROGRESS_MAX = 75;

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    // GET-only — the page has no form. The route URL must match
    // the URL the page is served at (legacy self-link pattern,
    // cont-52 lesson). Live URL: /host/step-4.
    #[Route('/host/step-4', name: 'app_host_step4', methods: ['GET'])]
    public function step4(Request $request): Response
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
           STEP 2 & STEP 3 CHECK — legacy 1:1: the session
           copy of host_application must carry BOTH 'listing_id'
           (written by Step 2's INSERT INTO listings) AND
           'cover_photo' (written by Step 3's cover move+INSERT).
           Either missing → bounce back to Step 1.
           ===================================================== */

        $hostApplication = $session->get('host_application');

        if (
            !is_array($hostApplication)
            || !isset($hostApplication['listing_id'])
            || !isset($hostApplication['cover_photo'])
        ) {
            return $this->redirect('/host/become-a-host');
        }

        /* =====================================================
           IS HOST APPROVED? — the legacy $isHostApproved gate.
           True only when session is_host === true (flipped by
           the admin host-application approve action, NOT by
           finishing the upload wizard). Picked up by the
           template to switch the hero + actions branch.
           ===================================================== */

        $isHostApproved = (bool) $session->get('is_host');

        /* =====================================================
           FINALIZE THE LISTING SUBMISSION (runs once per
           session) — flips the listings row from 'draft'
           (written by Step 2) to 'pending' so it appears in
           the admin review queue (listingapplication.php in
           legacy). Guarded by host_application['finalized']
           so refreshing this page doesn't re-run the UPDATE.
           ===================================================== */

        if (empty($hostApplication['finalized'])) {

            $listingId = (int) $hostApplication['listing_id'];

            try {
                $this->conn->executeStatement(
                    "UPDATE listings SET status = 'pending' WHERE id = :id",
                    ['id' => $listingId]
                );
            } catch (\Throwable $e) {
                // Legacy didn't try/catch; the port keeps the
                // page alive even if the UPDATE fails (logged,
                // user-facing flow continues — the row may
                // already be 'pending' from a prior visit, or
                // the admin queue will simply not show it yet).
                error_log('host-step4 finalize UPDATE failed: ' . $e->getMessage());
            }

            $hostApplication['finalized'] = true;
            $session->set('host_application', $hostApplication);
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
           DISPLAY NAME — legacy $fullName =
           $_SESSION["host_application"]["full_name"] ?? $userName.
           The host_application carries full_name from Step 1's
           restore block (HostStep2Controller fetches it).
           ===================================================== */

        $fullName = (string) ($hostApplication['full_name'] ?? $user['name']);

        /* =====================================================
           STEPPER PROGRESS — legacy formula:
           (($currentStep-1) / count($hostSteps)) * 100,
           clamped 0..75. With currentStep=4, count=4 → 75.
           The clamp matters because .hs-stepper-track spans
           only 75% of the container (12.5%..87.5%).
           ===================================================== */

        $stepProgress = self::TOTAL_STEPS > 0
            ? ((self::CURRENT_STEP - 1) / self::TOTAL_STEPS) * 100
            : 0;
        $stepProgress = max(0, min(self::STEP_PROGRESS_MAX, $stepProgress));

        /* =====================================================
           NOTIFICATION COUNT — same placeholder as legacy
           ($notification_count = 0 in the legacy file). The
           notifCount() helper does a real DB lookup so the
           navbar badge works the moment notifications land.
           ===================================================== */

        $notifCount = $this->notifCount($userId);

        return $this->render('host/host_step4.html.twig', [
            // Navbar dual-contract vars (the partial ?? guards read these)
            'user'                => $user,
            'is_host'             => (bool) $session->get('is_host'),
            'nav_avatar'          => $user['avatar'],
            'notification_count'  => $notifCount,

            // Page contract (host_step4.html.twig docstring)
            'notif_count'         => $notifCount,
            'is_host_approved'    => $isHostApproved,
            'step_progress_percent' => (int) round($stepProgress),
            'full_name'           => $fullName,
        ]);
    }

    /* =========================================================
       HELPERS — same implementations as SecurityController /
       HostStep2/3Controller / WalletController (kept identical
       across controllers). No CSRF helpers here — Step 4 has
       no form and no POST, so they're not needed.
       ========================================================= */

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
