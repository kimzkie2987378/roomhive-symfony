<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /* =========================================================
       HOME — one route, two faces. The session check gates the
       user-home render: a logged-out visitor can NEVER receive
       user/home.html.twig (which hardcodes is_logged_in:true).
    ========================================================== */
    #[Route('/', name: 'app_home')]
    public function index(Request $request): Response
    {
        $session    = $request->getSession();
        $isLoggedIn = $session->get('logged_in') === true;

        /* ---------- GUEST → public landing ---------- */
        if (!$isLoggedIn) {
            return $this->render('home/index.html.twig', [
                'is_logged_in'       => false,
                'current_page'       => '/',
                'nav_avatar'         => '',
                'notification_count' => 0,
            ]);
        }

        /* ---------- LOGGED-IN: resolve the user ---------- */
        $userId = (int) $session->get('user_id', 0);
        if ($userId <= 0) {
            /* logged_in flag without a user id — stale session */
            $session->invalidate();
            return $this->redirectToRoute('app_login');
        }

        $userRow = $this->db->fetchAssociative(
            "SELECT id, name, avatar_path, is_host FROM users WHERE id = :id LIMIT 1",
            ['id' => $userId]
        );

        if ($userRow === false) {
            /* Orphaned session (user deleted) — force clean login */
            $session->invalidate();
            return $this->redirectToRoute('app_login');
        }

        /* Keep is_host + avatar in sync with the DB */
        $session->set('is_host', (int) ($userRow['is_host'] ?? 0) === 1);
        $session->set('avatar_path', $userRow['avatar_path'] ?? null);

        /* ---- Real unread bell count ---- */
        $notificationCount = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0",
            ['u' => $userId]
        );

        /* ---- Category tiles (?category= is what app_listings reads) ---- */
        $listings = [
            ['name' => 'STUDIO LOFT',    'image' => 'images/StudioLoft.png',    'type' => 'studio-loft'],
            ['name' => 'SHARED ROOM',    'image' => 'images/SharedBedroom.png', 'type' => 'shared-bedroom'],
            ['name' => 'ENTIRE HOUSE',   'image' => 'images/EntireHouse.png',   'type' => 'entire-house'],
            ['name' => 'PRIVATE ROOM',   'image' => 'images/PrivateRoom.png',   'type' => 'private-room'],
            ['name' => 'BOARDING HOUSE', 'image' => 'images/BoardingHouse.png', 'type' => 'boarding-house'],
            ['name' => 'APARTMENT',      'image' => 'images/Apartment.png',     'type' => 'apartment'],
        ];

        /* LUCIDE icon names */
        $reasons = [
            ['icon' => 'headset',      'title' => '24/7 Support',       'text' => 'Our team is on call around the clock for hosts and tenants.'],
            ['icon' => 'badge-check',  'title' => 'Verified Listings',  'text' => 'Every listing is hand-reviewed and checked by our team before it goes live.'],
            ['icon' => 'shield-check', 'title' => 'Secure Payments',    'text' => 'Your booking and deposits are protected end-to-end.'],
            ['icon' => 'receipt-text', 'title' => 'No Hidden Fees',     'text' => 'What you see is what you pay — no surprise charges, ever.'],
        ];

        $testimonials = [
            ['quote' => 'Booking my apartment through RoomHive was seamless.', 'body' => 'The team was responsive from day one and the listing matched exactly what was shown. Moving in was stress-free.', 'author' => 'Isabelle Cruz'],
            ['quote' => 'RoomHive made finding a place stress-free.',          'body' => 'Verified listings and quick support meant I never had to worry about hidden costs or surprises on move-in day.', 'author' => 'Miguel Santos'],
            ['quote' => 'The verified listings gave me peace of mind.',        'body' => 'I was nervous about renting sight-unseen, but every photo and detail on RoomHive matched reality. Highly recommend.', 'author' => 'Andrea Lopez'],
            ['quote' => 'Customer support answered me at midnight.',           'body' => 'I had a payment issue right before move-in and the RoomHive team sorted it out within minutes, even that late.', 'author' => 'Kevin Tan'],
            ['quote' => 'No hidden fees, exactly as advertised.',              'body' => 'What I saw on the listing page was what I paid — no surprise charges at checkout or on my first invoice.', 'author' => 'Grace Villanueva'],
            ['quote' => 'Found my apartment in under a week.',                 'body' => 'The filters made it easy to narrow down listings by price and amenities, so I didn\'t waste time scrolling.', 'author' => 'Paolo Reyes'],
        ];

        $ctaImages = [
            'images/FirstImageLeft.jpg',
            'images/SecondImageLeft.jpg',
            'images/MiddleImage.avif',
            'images/FirstImageRight.jpg',
            'images/SecondImageRight.jpg',
        ];

        /* ---- Sponsored ads: approved, no pending hold, newest 8 ---- */
        $adRows = $this->db->fetchAllAssociative(
            "SELECT l.id, l.title, l.location, l.price,
                    p.photo_path AS cover_photo
             FROM listings l
             LEFT JOIN listing_photos p
                    ON p.listing_id = l.id AND p.photo_type = 'cover'
             WHERE l.status = 'approved'
               AND NOT EXISTS (
                   SELECT 1 FROM bookings b
                   WHERE b.listing_id = l.id
                     AND b.status = 'pending'
               )
             ORDER BY l.created_at DESC
             LIMIT 8"
        );

        $adListings = array_map(function (array $row): array {
            $cover = (string) ($row['cover_photo'] ?? '');

            if ($cover === '') {
                $image = '/images/ListingPlaceholder.png';
            } elseif (preg_match('#^(https?://|/)#i', $cover)) {
                $image = $cover;
            } else {
                $image = '/uploads/listing_photos/cover/' . basename($cover);
            }

            return [
                'id'       => (int) $row['id'],
                'title'    => (string) $row['title'],
                'location' => (string) $row['location'],
                'price'    => number_format((float) $row['price']),
                'image'    => $image,
            ];
        }, $adRows);

        /* generateUrl needs the request context — second pass */
        $adListings = array_map(function (array $ad): array {
            $ad['url'] = $this->generateUrl('app_listing_detail', ['id' => $ad['id']]);
            return $ad;
        }, $adListings);

        $response = $this->render('user/home.html.twig', [
            'user_name'          => (string) $userRow['name'],
            'is_host'            => (int) ($userRow['is_host'] ?? 0) === 1,
            'nav_avatar'         => (string) ($userRow['avatar_path'] ?? '') ?: '/images/default-avatar.png',
            'notification_count' => $notificationCount,
            'is_logged_in'       => true,
            'listings'           => $listings,
            'reasons'            => $reasons,
            'testimonials'       => $testimonials,
            'cta_images'         => $ctaImages,
            'ad_listings'        => $adListings,
            'current_page'       => '/',
        ]);

        /* A logged-in page must never sit in any browser cache —
           this is half of the logout fix. */
        $response->headers->set('Cache-Control', 'no-store, max-age=0, must-revalidate');

        return $response;
    }
}
