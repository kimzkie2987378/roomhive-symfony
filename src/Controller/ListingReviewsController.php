<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — LISTING RATINGS OVERLAY (JSON feed for the detail
 * page's ★ chip modal).
 *
 *   GET /listings/{id}/reviews-data   name app_listing_reviews_data
 *
 * The listing detail page's rating chip ("4.8 (12 reviews)") opens
 * a two-panel reviews modal (see listings/detail.html.twig, item 15
 * of its header). This endpoint feeds it in one round trip:
 *
 *   RIGHT PANEL (everyone)
 *     - average + review count (recomputed live from reviews);
 *     - distribution per star (1..5) for the bars;
 *     - every review card, newest first: reviewer name shown
 *       "Joel M." style (first name + initial), avatar resolved
 *       with the shared resolvePhoto rule (webprogg/ + host/
 *       prefixes stripped, bare filenames under /uploads/),
 *       falling back to the ui-avatars initials image the admin
 *       dashboard uses so a card never shows a broken picture.
 *
 *   LEFT PANEL (only when the viewer may rate this listing NOW)
 *     - logged in, NOT the listing's host, holds a COMPLETED or
 *       CANCELLED booking on it, and has not reviewed it yet —
 *       the legacy one-review-per-guest-per-listing identity rule
 *       (the reviews table has no booking_id column);
 *     - payload carries that booking (id for the POST, status for
 *       the "Stay completed" / "Stay cancelled" tag, formatted
 *       checkin/checkout for the subline).
 *     Everyone else gets can_review:false and the modal renders
 *     reviews-only; a viewer who already reviewed gets
 *     already_reviewed:true so the panel can show "You rated this
 *     stay".
 *
 * THE SUBMIT ITSELF IS NOT HERE — it stays POST /reviews/submit
 * (ReviewSubmitController: booking_id · rating · comment), which
 * re-checks every guard server-side. This endpoint only decides
 * whether the write panel shows; it never trusts the client.
 *
 * Read-only: no writes, no CSRF needed; not-logged-in viewers get
 * the public reviews feed exactly like the page itself.
 */
final class ListingReviewsController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    /* =====================================================================
     |  GET /listings/{id}/reviews-data — one round trip for the modal.
     |  priority 10 keeps it ahead of any future /listings/{...} catch-all.
     | ================================================================== */
    #[Route('/listings/{id}/reviews-data', name: 'app_listing_reviews_data', methods: ['GET'], requirements: ['id' => '\d+'], priority: 10)]
    public function data(Request $request, int $id): Response
    {
        $session  = $request->getSession();
        $loggedIn = $session->get('logged_in') === true;
        $userId   = (int) $session->get('user_id', 0);

        /* ---- listing must exist ---- */
        $listing = $this->db->fetchAssociative(
            'SELECT id, title, user_id FROM listings WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
        if ($listing === false) {
            return $this->json(['ok' => false, 'message' => 'Listing not found.'], 404);
        }

        /* ---- reviews, newest first ---- */
        $rows = $this->db->fetchAllAssociative(
            "SELECT r.rating, r.comment, r.created_at,
                    u.name  AS reviewer_name,
                    u.avatar_path
               FROM reviews r
               JOIN users u ON u.id = r.user_id
              WHERE r.listing_id = :id
              ORDER BY r.created_at DESC, r.id DESC",
            ['id' => $id]
        );

        $dist    = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $reviews = [];
        $sum     = 0.0;

        foreach ($rows as $r) {
            $stars = max(1, min(5, (int) round((float) $r['rating'])));
            $dist[$stars]++;

            $name = (string) ($r['reviewer_name'] ?? '');
            $reviews[] = [
                'name'    => $this->shortName($name),
                'avatar'  => $this->avatarUrl($r['avatar_path'] ?? null, $name),
                'date'    => date('M j, Y', strtotime((string) $r['created_at'])),
                'rating'  => $stars,
                'comment' => (string) ($r['comment'] ?? ''),
            ];
            $sum += (float) $r['rating'];
        }

        $count = count($reviews);
        $avg   = $count > 0 ? round($sum / $count, 1) : 0;

        /* ---- can the viewer write a review right now? ---- */
        $canReview       = false;
        $alreadyReviewed = false;
        $booking         = null;

        if ($loggedIn && $userId > 0 && $userId !== (int) $listing['user_id']) {
            try {
                $alreadyReviewed = (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM reviews WHERE user_id = :u AND listing_id = :l',
                    ['u' => $userId, 'l' => $id]
                ) > 0;
            } catch (\Throwable) {
                $alreadyReviewed = false;
            }

            /* The viewer's latest finished stay on THIS listing — the
               one the write panel is for. pending/confirmed stays are
               deliberately invisible here (nothing to rate yet). */
            $bk = $this->db->fetchAssociative(
                "SELECT id, status, checkin_date, checkout_date
                   FROM bookings
                  WHERE listing_id = :l
                    AND user_id = :u
                    AND status IN ('completed', 'cancelled')
                  ORDER BY booked_at DESC
                  LIMIT 1",
                ['l' => $id, 'u' => $userId]
            );

            if ($bk !== false) {
                $booking = [
                    'id'       => (int) $bk['id'],
                    'status'   => (string) $bk['status'],
                    'checkin'  => !empty($bk['checkin_date'])
                        ? date('M j, Y', strtotime((string) $bk['checkin_date']))
                        : '',
                    'checkout' => !empty($bk['checkout_date'])
                        ? date('M j, Y', strtotime((string) $bk['checkout_date']))
                        : '',
                ];

                $canReview = !$alreadyReviewed;
            }
        }

        return $this->json([
            'ok'               => true,
            'listing'          => [
                'id'      => (int) $listing['id'],
                'title'   => (string) $listing['title'],
                'rating'  => $avg,
                'reviews' => $count,
            ],
            'distribution'     => [
                '1' => $dist[1],
                '2' => $dist[2],
                '3' => $dist[3],
                '4' => $dist[4],
                '5' => $dist[5],
            ],
            'reviews'          => $reviews,
            'can_review'       => $canReview,
            'already_reviewed' => $alreadyReviewed,
            'booking'          => $booking,
        ]);
    }

    /* =======================================================
       HELPERS — display-name + avatar, same rules the other
       controllers render with.
    ======================================================== */

    /**
     * "Joel M." — first name + initial, the way the legacy
     * pages show reviewers. Single-word names pass through.
     */
    private function shortName(string $full): string
    {
        $full = trim($full);
        if ($full === '') {
            return 'Guest';
        }

        $parts = preg_split('/\s+/', $full, -1, PREG_SPLIT_NO_EMPTY);

        if (!$parts || count($parts) === 1) {
            return $parts ? (string) $parts[0] : $full;
        }

        return $parts[0] . ' ' . mb_strtoupper(mb_substr((string) $parts[1], 0, 1)) . '.';
    }

    /**
     * Shared resolvePhoto rule (HostPublicProfileController):
     * https stays as-is; 'webprogg/' and 'host/' legacy prefixes
     * are stripped; an 'uploads/' path maps under the site root;
     * an 'avatars/...' path or a bare filename is a user avatar
     * under /uploads/avatars/ (ProfileController writes them
     * there). Anything unrecognised falls back to the ui-avatars
     * initials image the admin dashboard uses.
     */
    private function avatarUrl(?string $path, string $name): string
    {
        $initials = 'https://ui-avatars.com/api/?background=EDA423&color=fff&bold=true&name='
            . urlencode($this->shortName($name));

        $path = trim((string) $path);
        if ($path === '') {
            return $initials;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $p = str_replace('\\', '/', $path);
        $p = ltrim($p, '/');

        if (stripos($p, 'webprogg/') === 0) {
            $p = substr($p, strlen('webprogg/'));
        }
        if (stripos($p, 'host/') === 0) {
            $p = substr($p, strlen('host/'));
        }
        if (stripos($p, 'uploads/') === 0) {
            return '/' . $p;
        }
        if (stripos($p, 'avatars/') === 0) {
            return '/uploads/' . $p;
        }
        if (strpos($p, '/') === false) {
            return '/uploads/avatars/' . $p;
        }

        return $initials;
    }
}
