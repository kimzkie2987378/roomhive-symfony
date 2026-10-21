<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListingsController extends AbstractController
{
    private const PER_PAGE = 16;

    /* =====================================================
       FILTER VOCABULARY — LEGACY slugs (match the JSON stored
       in listings.amenities by the legacy host flow).
    ====================================================== */
    private const CATEGORIES = [
        'shared-bedroom' => 'Shared Bedroom',
        'private-room'   => 'Private Room',
        'entire-house'   => 'Entire House',
        'boarding-house' => 'Boarding House',
        'studio-loft'    => 'Studio Loft',
        'apartment'      => 'Apartment',
    ];

    private const AMENITY_OPTIONS = [
        'wifi'             => 'Wi-fi',
        'aircon'           => 'Aircon',
        'pet-friendly'     => 'Pet Friendly',
        'free-water'       => 'Free Water',
        'free-electricity' => 'Free Electricity',
        'security'         => '24/7 Security',
    ];

    private const AMENITY_ICONS = [
        'wifi'             => 'wifi',
        'aircon'           => 'air-vent',
        'pet-friendly'     => 'paw-print',
        'free-water'       => 'droplets',
        'free-electricity' => 'plug-zap',
        'security'         => 'shield-check',
    ];

    private const CAPACITY_LABELS = [
        '1' => '1 Guest',
        '2' => '2 Guests',
        '3' => '3 Guests',
        '4' => '4 Guests',
        '5' => '5 Guests',
        '6' => '6 Guests',
        '8' => '8 Guests',
        '10' => '10 Guests',
        '10+' => 'More than 10 Guests',
    ];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /* =====================================================
       SHARED HELPERS
    ====================================================== */

    private function hasColumn(string $table, string $column): bool
    {
        $cols = $this->db->createSchemaManager()->listTableColumns($table);
        return isset($cols[$column]);
    }

    /**
     * Legacy photo-path resolver — Symfony edition.
     *
     * The DB stores paths written by the legacy app using several
     * historical conventions:
     *   webprogg/uploads/listing_photos/cover/x.jpg
     *   host/uploads/avatars/x.jpg
     *   uploads/listing_photos/x.jpg
     *   cover_xxx.jpg            (bare cover filename)
     *   xxx.jpg                  (bare filename)
     *   https://...              (absolute external URL)
     *
     * ALL of them map onto Symfony's public/ tree, which is why
     * legacy webprogg/uploads/ was copied to public/uploads/.
     */
    private function legacyPhotoUrl(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/images/ListingPlaceholder.png';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path; /* external URL — works as-is */
        }

        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        /* Strip legacy project prefixes — the files now live
           directly under public/uploads/ */
        if (stripos($path, 'webprogg/') === 0) {
            $path = substr($path, strlen('webprogg/'));
        }
        if (stripos($path, 'host/') === 0) {
            $path = substr($path, strlen('host/'));
        }

        /* Whatever remains is either uploads/... or a bare filename */
        if (stripos($path, 'uploads/') === 0) {
            return '/' . $path;
        }
        if (stripos($path, 'cover_') === 0) {
            return '/uploads/listing_photos/cover/' . basename($path);
        }

        return '/uploads/listing_photos/' . $path;
    }

    private function decodeAmenities(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /* =========================================================
       INDEX — search + filters + pagination
    ========================================================== */
    #[Route('/listings', name: 'app_listings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q         = trim((string) $request->query->get('q', ''));
        $category  = (string) $request->query->get('category', '');
        $province  = trim((string) $request->query->get('province', ''));
        $location  = trim((string) $request->query->get('location', ''));
        $priceMax  = (int) $request->query->get('price_max', 20000);
        $sort      = (string) $request->query->get('sort', 'featured');
        $page      = max(1, (int) $request->query->get('page', 1));
        $amenities = (array) $request->query->all('amenities');

        if (!isset(self::CATEGORIES[$category])) {
            $category = '';
        }
        if (!in_array($sort, ['featured', 'price-asc', 'price-desc'], true)) {
            $sort = 'featured';
        }
        $priceMax  = max(0, min(20000, $priceMax));
        $amenities = array_values(array_intersect($amenities, array_keys(self::AMENITY_OPTIONS)));

        $hasAmenitiesCol = $this->hasColumn('listings', 'amenities');

        $sql    = "SELECT l.id, l.title, l.location, l.category, l.price,
                          l.parking, l.created_at" .
            ($hasAmenitiesCol ? ", l.amenities" : "") . ",
                   p.photo_path AS cover_photo
             FROM listings l
             LEFT JOIN listing_photos p
                    ON p.listing_id = l.id AND p.photo_type = 'cover'
            WHERE l.status = 'approved'";
        $params = [];

        if ($q !== '') {
            $sql .= " AND (l.title LIKE :q OR l.location LIKE :q)";
            $params['q'] = '%' . $q . '%';
        }
        if ($category !== '') {
            $sql .= " AND l.category = :category";
            $params['category'] = $category;
        }
        if ($location !== '' && $province !== '') {
            $sql .= " AND (l.location LIKE :loc_town OR l.location LIKE :loc_prov)";
            $params['loc_town'] = '%' . $location . '%';
            $params['loc_prov'] = '%' . $province . '%';
        } elseif ($province !== '') {
            $sql .= " AND l.location LIKE :loc_prov";
            $params['loc_prov'] = '%' . $province . '%';
        } elseif ($location !== '') {
            $sql .= " AND l.location LIKE :loc";
            $params['loc'] = '%' . $location . '%';
        }
        if ($priceMax > 0 && $priceMax < 20000) {
            $sql .= " AND l.price <= :price_max";
            $params['price_max'] = $priceMax;
        }

        if ($hasAmenitiesCol) {
            foreach ($amenities as $i => $key) {
                $sql .= " AND l.amenities LIKE :am_" . $i;
                $params['am_' . $i] = '%' . $key . '%';
            }
            if (in_array('parking', $amenities, true)) {
                $sql .= " AND l.parking IS NOT NULL
                          AND LOWER(l.parking) NOT IN ('', '0', 'no', 'none')";
            }
        } elseif (in_array('parking', $amenities, true)) {
            $sql .= " AND l.parking IS NOT NULL
                      AND LOWER(l.parking) NOT IN ('', '0', 'no', 'none')";
        }

        $sql .= match ($sort) {
            'price-asc'  => " ORDER BY l.price ASC",
            'price-desc' => " ORDER BY l.price DESC",
            default      => " ORDER BY l.created_at DESC, l.id DESC",
        };

        $totalItems = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM ({$sql}) AS counted",
            $params
        );
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * self::PER_PAGE;

        $rows = $this->db->fetchAllAssociative(
            $sql . " LIMIT " . self::PER_PAGE . " OFFSET " . $offset,
            $params
        );

        $listings = array_map(function (array $row): array {
            $amenities = $this->decodeAmenities($row['amenities'] ?? null);

            $parkingRaw = strtolower(trim((string) ($row['parking'] ?? '')));
            $hasParking = $parkingRaw !== '' && !in_array($parkingRaw, ['0', 'no', 'none'], true);

            $loc  = (string) $row['location'];
            $prov = '';
            if (str_contains($loc, ',')) {
                [$loc, $prov] = array_map('trim', explode(',', $loc, 2));
            }

            return [
                'id'         => (int) $row['id'],
                'title'      => (string) $row['title'],
                'coverImage' => $this->legacyPhotoUrl((string) ($row['cover_photo'] ?? '')),
                'location'   => $loc,
                'province'   => $prov,
                'price'      => (float) $row['price'],
                'amenities'  => $amenities,
                'hasParking' => $hasParking,
                'createdAt'  => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['created_at'])
                    ?: new \DateTimeImmutable('@0'),
            ];
        }, $rows);

        $selected = [
            'q'         => $q,
            'category'  => $category,
            'province'  => $province,
            'location'  => $location,
            'price_max' => $priceMax,
            'sort'      => $sort,
            'amenities' => $amenities,
        ];

        $hasActiveFilters =
            $q !== '' || $category !== '' || $province !== '' || $location !== ''
            || ($priceMax > 0 && $priceMax < 20000)
            || $amenities !== [] || $sort !== 'featured';

        return $this->render('listings/index.html.twig', [
            'listings'                => $listings,
            'total_items'             => $totalItems,
            'total_pages'             => $totalPages,
            'page'                    => $page,
            'has_active_filters'      => $hasActiveFilters,
            'categories'              => self::CATEGORIES,
            'amenity_options'         => self::AMENITY_OPTIONS,
            'amenity_icons'           => self::AMENITY_ICONS,
            'selected'                => $selected,
            'selected_category_label' => $category !== '' ? self::CATEGORIES[$category] : 'All Categories',
            'current_page'            => '/listings',
        ]);
    }

    /* =========================================================
       DETAIL — full port of the legacy listing-detail.php:
       monthly duration pricing + Long Term mode, availability
       blocking, booked-range calendar payload, own-listing and
       application-status awareness, verification nudge.
    ========================================================== */
    #[Route('/listings/{id}', name: 'app_listing_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(Request $request, int $id): Response
    {
        $session    = $request->getSession();
        $isLoggedIn = $session->get('logged_in') === true;
        $userId     = (int) $session->get('user_id', 0);

        /* ---- Redirect flags (same GET keys as legacy) ---- */
        $flags = [
            'unavailable'     => $request->query->getBoolean('unavailable'),
            'ownbooking'      => $request->query->getBoolean('ownbooking'),
            'alreadylisted'   => $request->query->getBoolean('alreadylisted'),
            'incompletedates' => $request->query->getBoolean('incompletedates'),
            'needverify'      => $request->query->getBoolean('needverify'),
        ];

        /* ---- Listing + host ---- */
        $row = $this->db->fetchAssociative(
            "SELECT l.*, u.name AS host_name, u.email AS host_email,
                    u.created_at AS host_created_at, u.avatar_path AS host_avatar_path
             FROM listings l
             JOIN users u ON u.id = l.user_id
             WHERE l.id = :id
             LIMIT 1",
            ['id' => $id]
        );

        if ($row === false) {
            return $this->redirectToRoute('app_listings');
        }

        /* ---- Photos (cover first, then sort_order) ---- */
        $photoRows = $this->db->fetchAllAssociative(
            "SELECT photo_path
             FROM listing_photos
             WHERE listing_id = :id
             ORDER BY (photo_type = 'cover') DESC, sort_order ASC",
            ['id' => $id]
        );

        $gallery = array_map(
            fn(array $r): string => $this->legacyPhotoUrl((string) $r['photo_path']),
            $photoRows
        );
        if ($gallery === []) {
            $gallery = ['/images/ListingPlaceholder.png'];
        }

        /* ---- Ratings (listing + host) ---- */
        $reviewRatings = array_map('floatval', array_column(
            $this->db->fetchAllAssociative(
                "SELECT rating FROM reviews WHERE listing_id = :id",
                ['id' => $id]
            ),
            'rating'
        ));
        $listingRating  = $reviewRatings ? round(array_sum($reviewRatings) / count($reviewRatings), 1) : 0;
        $listingReviews = count($reviewRatings);

        $hostReviewRatings = array_map('floatval', array_column(
            $this->db->fetchAllAssociative(
                "SELECT r.rating
                 FROM reviews r
                 JOIN listings l2 ON l2.id = r.listing_id
                 WHERE l2.user_id = :host_id",
                ['host_id' => (int) $row['user_id']]
            ),
            'rating'
        ));
        $hostRating  = $hostReviewRatings ? round(array_sum($hostReviewRatings) / count($hostReviewRatings), 1) : 0;
        $hostReviews = count($hostReviewRatings);

        /* ---- Availability ---- */
        $isBookable = ($row['status'] ?? '') === 'approved';

        $unavailableRanges = $this->db->fetchAllAssociative(
            "SELECT checkin_date, checkout_date
             FROM bookings
             WHERE listing_id = :listing_id
               AND status IN ('confirmed', 'pending')
               AND checkin_date IS NOT NULL
             ORDER BY checkin_date ASC",
            ['listing_id' => $id]
        );

        /* Long-term occupancy: any active booking with NULL
           checkout blocks everyone */
        $isLongTermOccupied  = false;
        $unavailableRangesJs = [];

        foreach ($unavailableRanges as $range) {
            $co = $range['checkout_date'] ?? null;

            if (empty($co)) {
                $isLongTermOccupied = true;
            }

            $unavailableRangesJs[] = [
                'start' => !empty($range['checkin_date'])  ? $range['checkin_date']  : null,
                'end'   => !empty($range['checkout_date']) ? $range['checkout_date'] : null,
            ];
        }

        if ($isLongTermOccupied) {
            $isBookable = false;
        }

        $isOwnListing = $isLoggedIn && (int) $row['user_id'] === $userId;

        /* ---- Verification nudge (logged-in renters only) ---- */
        $viewerIsHost    = $session->get('is_host') === true;
        $showVerifyNudge = false;

        if ($isLoggedIn && !$viewerIsHost && !$isOwnListing) {
            /* Legacy used config/verification_gate.php::is_user_verified().
               Port: if the users table has an is_verified column use it;
               otherwise treat everyone as verified (nudge off). */
            if ($this->hasColumn('users', 'is_verified')) {
                $isVerified = (bool) $this->db->fetchOne(
                    "SELECT is_verified FROM users WHERE id = :id",
                    ['id' => $userId]
                );
                $showVerifyNudge = !$isVerified;
            }
        }

        /* ---- My latest application status (not on own listing) ---- */
        $myApplicationStatus = null;
        if ($isLoggedIn && !$isOwnListing && $userId > 0) {
            $status = $this->db->fetchOne(
                "SELECT status
                 FROM bookings
                 WHERE listing_id = :listing_id AND user_id = :user_id
                 ORDER BY booked_at DESC
                 LIMIT 1",
                ['listing_id' => $id, 'user_id' => $userId]
            );
            $myApplicationStatus = $status !== false ? $status : null;
        }

        /* ---- Monthly pricing (server-side display values;
                the payment page re-validates) ---- */
        $basePrice = (float) $row['price'];

        $allowedDurations = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        $durationMonths   = (int) $request->query->get('months', 1);
        if (!in_array($durationMonths, $allowedDurations, true)) {
            $durationMonths = 1;
        }

        $isLongTermSelected = $request->query->get('long_term') === '1';
        if ($isLongTermSelected) {
            $durationMonths = 1;
        }

        $listingTotal   = round($basePrice * $durationMonths, 2);
        $reserveFee     = round($listingTotal * 0.5, 2);
        $reserveBalance = round($listingTotal - $reserveFee, 2);
        $durationLabel  = $isLongTermSelected
            ? 'Long Term'
            : ($durationMonths === 1 ? '1 Month' : $durationMonths . ' Months');

        /* ---- Capacity → guests whitelist ---- */
        $rawCapacity = (string) ($row['capacity'] ?? '1');
        if (in_array($rawCapacity, ['1', '2', '3', '4+'], true)) {
            $guestsValue = $rawCapacity;
        } elseif (ctype_digit($rawCapacity) && (int) $rawCapacity >= 4) {
            $guestsValue = '4+';
        } else {
            $guestsValue = '1';
        }

        /* ---- Map ---- */
        $lat = isset($row['latitude'])  && $row['latitude']  !== null ? (float) $row['latitude']  : null;
        $lng = isset($row['longitude']) && $row['longitude'] !== null ? (float) $row['longitude'] : null;
        $hasPin = $lat !== null && $lng !== null;

        $mapsQuery = $hasPin
            ? $lat . ',' . $lng
            : trim(($row['exact_address'] ?? '') . ', ' . $row['location']);
        $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($mapsQuery);

        /* ---- Duration of host membership ---- */
        $hostSince = '—';
        if (!empty($row['host_created_at'])) {
            $ts = strtotime((string) $row['host_created_at']);
            $hostSince = $ts ? date('F Y', $ts) : '—';
        }

        $isNew = (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['created_at'])
            ?: new \DateTimeImmutable('@0')) >= new \DateTimeImmutable('-14 days');

        $parkingRaw = strtolower(trim((string) ($row['parking'] ?? '')));
        $hasParking = $parkingRaw !== '' && !in_array($parkingRaw, ['0', 'no', 'none'], true);

        return $this->render('listings/detail.html.twig', [
            /* nav */
            'is_logged_in'       => $isLoggedIn,
            'nav_avatar'         => (string) ($session->get('avatar_path') ?? '') ?: '/images/default-avatar.png',
            'notification_count' => 0,
            'current_page'       => '/listings',

            /* flags */
            'flags'                 => $flags,
            'show_verify_nudge'     => $showVerifyNudge,
            'my_application_status' => $myApplicationStatus,

            /* listing */
            'listing' => [
                'id'              => (int) $row['id'],
                'title'           => (string) $row['title'],
                'gallery'         => $gallery,
                'location_label'  => (string) $row['location'],
                'location_full'   => trim((string) ($row['exact_address'] ?? '')) . ', ' . $row['location'],
                'category_label'  => (string) ($row['category'] ?? ''),
                'price'           => $basePrice,
                'duration_months' => $durationMonths,
                'total_rent'      => $listingTotal,
                'reserve_fee'     => $reserveFee,
                'reserve_balance' => $reserveBalance,
                'duration_label'  => $durationLabel,
                'amenities'       => $this->decodeAmenities($row['amenities'] ?? null),
                'bedrooms'        => (int) ($row['bedrooms'] ?? 0),
                'bathrooms'       => (int) ($row['bathrooms'] ?? 0),
                'size_sqm'        => (float) ($row['size_sqm'] ?? 0),
                'floor'           => (string) ($row['floor'] ?? ''),
                'parking'         => (string) ($row['parking'] ?? ''),
                'has_parking'     => $hasParking,
                'capacity'        => $rawCapacity,
                'capacity_label'  => self::CAPACITY_LABELS[$rawCapacity] ?? ($rawCapacity . ' Guests'),
                'description'     => (string) ($row['description'] ?? ''),
                'house_rules'     => (string) ($row['house_rules'] ?? ''),
                'rating'          => $listingRating,
                'reviews'         => $listingReviews,
                'is_new'          => $isNew,
            ],

            /* host */
            'host' => [
                'id'           => (int) $row['user_id'],
                'name'         => (string) $row['host_name'],
                'avatar'       => !empty($row['host_avatar_path'])
                    ? $this->legacyPhotoUrl((string) $row['host_avatar_path'])
                    : '/images/default-avatar.png',
                'member_since' => $hostSince,
                'rating'       => $hostRating,
                'reviews'      => $hostReviews,
            ],

            /* booking state */
            'is_bookable'           => $isBookable,
            'is_own_listing'        => $isOwnListing,
            'viewer_is_host'        => $viewerIsHost,
            'is_long_term_occupied' => $isLongTermOccupied,
            'guests_value'          => $guestsValue,

            /* calendar payload */
            'unavailable_ranges' => $unavailableRangesJs,

            /* map */
            'has_pin'            => $hasPin,
            'maps_query'         => $mapsQuery,
            'maps_url'           => $mapsUrl,

            /* icon map for amenities (LUCIDE) */
            'amenity_icons'      => self::AMENITY_ICONS,
            'amenity_options'    => self::AMENITY_OPTIONS,
        ]);
    }
}
