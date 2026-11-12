<?php

/*
 * =========================================================
 * ROOMHIVE — BECOME A HOST (STEP 2) · ADD YOUR SPACE
 * src/Controller/HostStep2Controller.php
 * =========================================================
 * Symfony port of the legacy host-step2.php controller block
 * (template: templates/host/host_step2.html.twig).
 *
 * THE FIX for  SQLSTATE[42S22] "Unknown column 'application_id'
 * in 'field list'":  the listings table's FK column is
 * **host_application_id** (ground truth: the legacy
 * host-step2.php INSERT). The INSERT below names it 1:1 with
 * the legacy schema — column AND placeholder. Nothing else in
 * the flow changed.
 *
 * Contract (host_step2.html.twig docstring):
 *   - Route accepts GET + POST on the same URL.
 *   - Plain-session auth (logged_in / user_id / is_host) — no
 *     SecurityBundle, same as HostApplicationController::apply().
 *   - Self-contained CSRF via the _rh_csrf_tokens session store
 *     (token id 'host_step2'), validated with hash_equals.
 *   - On success: INSERT INTO listings (...) + session
 *     host_application.listing_id = lastInsertId() → redirect
 *     /host/step-3 (their URL convention: /host/step-2 ⇒ step-3).
 *   - On failure: re-render with errors + old + rh2_initial.
 * =========================================================
 */

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

class HostStep2Controller extends AbstractController
{
    private Connection $conn;

    /* =====================================================
       OPTION MAPS — same values as the legacy page, validated
       1:1 (in_array checks the VALUES, array_key_exists the
       KEYS, exactly like the original code).
       ===================================================== */

    private const LISTING_CATEGORIES = [
        'Shared Bedroom' => 'shared-bedroom',
        'Private Room'   => 'private-room',
        'Entire House'   => 'entire-house',
        'Boarding House' => 'boarding-house',
        'Studio Loft'    => 'studio-loft',
    ];

    private const PROPERTY_TYPES = [
        'Apartment'      => 'apartment',
        'Condominium'    => 'condominium',
        'House'          => 'house',
        'Townhouse'      => 'townhouse',
        'Dormitory'      => 'dormitory',
        'Boarding House' => 'boarding-house',
    ];

    private const CAPACITY_OPTIONS = [
        '1'   => '1 Guest',
        '2'   => '2 Guests',
        '3'   => '3 Guests',
        '4'   => '4 Guests',
        '5'   => '5 Guests',
        '6'   => '6 Guests',
        '8'   => '8 Guests',
        '10'  => '10 Guests',
        '10+' => 'More than 10 Guests',
    ];

    private const PARKING_OPTIONS = [
        'Yes' => 'Yes, parking available',
        'No'  => 'No parking available',
    ];

    private const AMENITY_KEYS = [
        'wifi',
        'aircon',
        'pet-friendly',
        'free-water',
        'free-electricity',
        'security',
    ];

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    // The route URL must match the URL the page is served at — the form
    // self-posts (action="") to the current URL. Live URL: /host/step-2.
    #[Route('/host/step-2', name: 'app_host_step2', methods: ['GET', 'POST'])]
    public function step2(Request $request): Response
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
           STEP 1 CHECK — restore the approved host_applications
           row into the session, or bounce back to Step 1.
           ===================================================== */

        $hostApplication   = $session->get('host_application');
        $hostApplicationId = $session->get('host_application_id');

        if (!is_array($hostApplication) || !$hostApplicationId) {

            $application = null;

            if ($session->get('is_host') && $userId > 0) {
                try {
                    $application = $this->conn->fetchAssociative(
                        "SELECT * FROM host_applications
                          WHERE user_id = :user_id AND status = 'approved'
                          ORDER BY id DESC
                          LIMIT 1",
                        ['user_id' => $userId]
                    );
                } catch (\Throwable) {
                    $application = null;
                }
            }

            if ($application) {

                $hostApplicationId = (int) $application['id'];
                $session->set('host_application_id', $hostApplicationId);

                $hostApplication = [
                    'full_name' => (string) ($application['full_name'] ?? ''),
                    'email'     => (string) ($application['email'] ?? ''),
                    'phone'     => (string) ($application['phone'] ?? ''),
                    'age'       => (string) ($application['age'] ?? ''),
                    'location'  => (string) ($application['location'] ?? ''),
                    'id_type'   => (string) ($application['id_type'] ?? ''),
                    'id_number' => (string) ($application['id_number'] ?? ''),
                    'id_file'   => (string) ($application['id_file'] ?? ''),
                ];
                $session->set('host_application', $hostApplication);
            } else {
                return $this->redirect('/host/become-a-host');
            }
        }

        /* =====================================================
           CSRF — self-contained (same _rh_csrf_tokens store as
           Step 1 / Settings). The template posts csrf_token.
           ===================================================== */

        $csrfHostStep2 = $this->ensureCsrfToken($session, 'host_step2');

        /* =====================================================
           FORM PROCESSING — collect the posted values (on GET
           everything lands empty, which pre-seeds the re-stick
           arrays for the initial render).
           ===================================================== */

        $trimmed = static fn(string $key): string => trim((string) $request->request->get($key, ''));

        $title        = $trimmed('title');
        $category     = $trimmed('category');
        $propertyType = $trimmed('property_type');
        $location     = $trimmed('location');
        $province     = $trimmed('province');
        $city         = $trimmed('city');
        $exactAddress = $trimmed('exact_address');
        $latitude     = $trimmed('latitude');
        $longitude    = $trimmed('longitude');
        $price        = $trimmed('price');
        $capacity     = $trimmed('capacity');
        $bedrooms     = $trimmed('bedrooms');
        $bathrooms    = $trimmed('bathrooms');
        $sizeSqm      = $trimmed('size_sqm');
        $floor        = $trimmed('floor');
        $parking      = $trimmed('parking');
        $description  = $trimmed('description');
        $houseRules   = $trimmed('house_rules');

        // cont-48 dual-compat: the template's JS composes the hidden
        // `location` field as "City, Province" on every keystroke and
        // at submit. Belt-and-suspenders fallback keeps validation
        // green even when that hidden field arrives empty (JS off).
        if ($location === '' && ($province !== '' || $city !== '')) {
            $location = trim($city . ', ' . $province);
        }

        // Amenities — read via all() WITHOUT a key first: Symfony 6.1+
        // InputBag::all('amenities') throws on a scalar value, and the
        // legacy page wrapped scalars into an array instead.
        $rawAmenities = $request->request->all()['amenities'] ?? [];

        if (!is_array($rawAmenities)) {
            $rawAmenities = [$rawAmenities];
        }

        $selectedAmenities = array_values(array_intersect($rawAmenities, self::AMENITY_KEYS));

        // Values for re-populating the form after a FAILED submission.
        $old = [
            'title'         => $title,
            'category'      => $category,
            'property_type' => $propertyType,
            'province'      => $province,
            'city'          => $city,
            'location'      => $location,
            'exact_address' => $exactAddress,
            'latitude'      => $latitude,
            'longitude'     => $longitude,
            'price'         => $price,
            'capacity'      => $capacity,
            'bedrooms'      => $bedrooms,
            'bathrooms'     => $bathrooms,
            'size_sqm'      => $sizeSqm,
            'floor'         => $floor,
            'parking'       => $parking,
            'description'   => $description,
            'house_rules'   => $houseRules,
            'amenities'     => $selectedAmenities,
        ];

        // JSON payload for the map/preview scripts (failed-POST restore).
        $rh2Initial = [
            'lat' => $latitude !== '' && is_numeric($latitude) ? (float) $latitude : null,
            'lng' => $longitude !== '' && is_numeric($longitude) ? (float) $longitude : null,
        ];

        $errors = [];

        /* =====================================================
           POST — CSRF + VALIDATION (messages 1:1 with legacy)
           ===================================================== */

        if ($request->isMethod('POST')) {

            if (!$this->csrfValid($session, 'host_step2', $this->postedToken($request))) {
                $errors[] = 'Your session expired. Please try again.';
            }

            if ($title === '') {
                $errors[] = 'Please enter a title for your space.';
            }

            if ($category === '' || !in_array($category, self::LISTING_CATEGORIES, true)) {
                $errors[] = 'Please select a category.';
            }

            if ($propertyType === '' || !in_array($propertyType, self::PROPERTY_TYPES, true)) {
                $errors[] = 'Please select a property type.';
            }

            if ($location === '') {
                $errors[] = 'Please enter the location of your space.';
            }

            if ($exactAddress === '') {
                $errors[] = 'Please enter the exact address.';
            }

            if ($latitude === '' || $longitude === '') {
                $errors[] = 'Please pinpoint your space on the map.';
            }

            if ($latitude !== '' && (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90)) {
                $errors[] = 'Invalid map pin latitude.';
            }

            if ($longitude !== '' && (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180)) {
                $errors[] = 'Invalid map pin longitude.';
            }

            if ($price === '' || !is_numeric($price) || (float) $price <= 0) {
                $errors[] = 'Please enter a valid price per month.';
            }

            if ($capacity === '' || !array_key_exists($capacity, self::CAPACITY_OPTIONS)) {
                $errors[] = 'Please select the maximum number of guests.';
            }

            if ($bedrooms === '' || !is_numeric($bedrooms) || (int) $bedrooms <= 0) {
                $errors[] = 'Please enter the number of bedrooms.';
            }

            if ($bathrooms === '' || !is_numeric($bathrooms) || (int) $bathrooms <= 0) {
                $errors[] = 'Please enter the number of bathrooms.';
            }

            if ($sizeSqm === '' || !is_numeric($sizeSqm) || (float) $sizeSqm <= 0) {
                $errors[] = 'Please enter the size of your space in square meters.';
            }

            if ($floor === '') {
                $errors[] = 'Please enter which floor your space is on.';
            }

            if ($parking === '' || !array_key_exists($parking, self::PARKING_OPTIONS)) {
                $errors[] = 'Please let renters know if parking is available.';
            }

            if ($description === '') {
                $errors[] = 'Please describe your space.';
            }

            /* =================================================
               IF VALID — INSERT (THE FIX LIVES HERE)
               ================================================= */

            if (!$errors) {
                try {
                    // Column list 1:1 with the legacy host-step2.php
                    // INSERT. The FK column is host_application_id —
                    // NOT application_id (that name does not exist in
                    // the listings table → SQLSTATE[42S22]).
                    $this->conn->executeStatement(
                        "INSERT INTO listings
                            (host_application_id, user_id, title, category, property_type, location,
                             exact_address, latitude, longitude, price, capacity, bedrooms, bathrooms,
                             size_sqm, floor, parking, amenities, description, house_rules, status)
                         VALUES
                            (:host_application_id, :user_id, :title, :category, :property_type, :location,
                             :exact_address, :latitude, :longitude, :price, :capacity, :bedrooms, :bathrooms,
                             :size_sqm, :floor, :parking, :amenities, :description, :house_rules, 'draft')",
                        [
                            'host_application_id' => (int) $hostApplicationId,
                            'user_id'             => $userId,
                            'title'               => $title,
                            'category'            => $category,
                            'property_type'       => $propertyType,
                            'location'            => $location,
                            'exact_address'       => $exactAddress,
                            'latitude'            => (float) $latitude,
                            'longitude'           => (float) $longitude,
                            'price'               => $price,
                            'capacity'            => $capacity,
                            'bedrooms'            => $bedrooms,
                            'bathrooms'           => $bathrooms,
                            'size_sqm'            => $sizeSqm,
                            'floor'               => $floor,
                            'parking'             => $parking,
                            'amenities'           => json_encode($selectedAmenities),
                            'description'         => $description,
                            'house_rules'         => $houseRules,
                        ]
                    );

                    $listingId = (int) $this->conn->lastInsertId();

                    /* Session writes (legacy 1:1) — Step 3 reads
                       host_application.listing_id + title from here. */
                    $hostApplication = $session->get('host_application');
                    if (!is_array($hostApplication)) {
                        $hostApplication = [];
                    }

                    $hostApplication['listing_id'] = $listingId;

                    unset($hostApplication['finalized'], $hostApplication['cover_photo']);

                    $hostApplication['title']         = $title;
                    $hostApplication['category']      = $category;
                    $hostApplication['property_type'] = $propertyType;
                    $hostApplication['location']      = $location;
                    $hostApplication['exact_address'] = $exactAddress;
                    $hostApplication['latitude']      = $latitude;
                    $hostApplication['longitude']     = $longitude;
                    $hostApplication['price']         = $price;
                    $hostApplication['capacity']      = $capacity;
                    $hostApplication['bedrooms']      = $bedrooms;
                    $hostApplication['bathrooms']     = $bathrooms;
                    $hostApplication['size_sqm']      = $sizeSqm;
                    $hostApplication['floor']         = $floor;
                    $hostApplication['parking']       = $parking;
                    $hostApplication['amenities']     = $selectedAmenities;
                    $hostApplication['description']   = $description;
                    $hostApplication['house_rules']   = $houseRules;

                    $session->set('host_application', $hostApplication);

                    // Their URL convention is /host/step-N (Step 2 =
                    // /host/step-2) — adjust if your Step 3 route differs.
                    return $this->redirect('/host/step-3');
                } catch (\Throwable $e) {
                    error_log('host-step2 INSERT failed: ' . $e->getMessage());
                    $errors[] = 'Could not save your space. Please try again.';
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
           submitted values re-stuck)
           ===================================================== */

        $notifCount = $this->notifCount($userId);

        return $this->render('host/host_step2.html.twig', [
            // Navbar dual-contract vars (the partial ?? guards read these)
            'user'               => $user,
            'is_host'            => (bool) $session->get('is_host'),
            'nav_avatar'         => $user['avatar'],
            'notification_count' => $notifCount,

            // Page contract (host_step2.html.twig docstring)
            'errors'             => $errors,
            'old'                => $old,
            'rh2_initial'        => $rh2Initial,
            'notif_count'        => $notifCount,
            'csrf_host_step2'    => $csrfHostStep2,
            'google_maps_api_key' => $_ENV['GOOGLE_MAPS_API_KEY'] ?? '',
        ]);
    }

    /* =========================================================
       HELPERS — same implementations as SecurityController /
       WalletController (kept identical across controllers)
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
