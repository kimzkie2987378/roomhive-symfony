<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — HOST › EDIT LISTING.
 * Port of legacy user/edit-listing.php (the TODO route that every
 * host page's "Edit Listing" link pointed at — now REAL).
 *
 *   GET /host/listings/edit?id=N   → the edit form for the host's
 *     own listing. Same auth chain as HostListingsController:
 *     logged_in + user_id (else /login), is_host = 1 (else
 *     /host/become-a-host), then ownership (listing.user_id ===
 *     session user, else back to /host/listings).
 *
 *   POST /host/listings/edit       → two intents (one controller,
 *     no extra endpoints — mirrors the legacy single-file shape):
 *       intent=save         the main form (multipart): text fields
 *                           + cover replace + additional photos.
 *       intent=remove_photo the × button on each photo thumbnail
 *                           (tiny inline form per thumbnail).
 *
 * LOCKS ("price and availability lock while a guest is booked or
 *   reserving" — the same rule the My Listings card surfaces):
 *   Cosmetic fields — title, exact_address, description,
 *   house_rules, amenities, photos — are ALWAYS editable.
 *   price / capacity / bedrooms / bathrooms / size_sqm / floor /
 *   parking are LOCKED while the listing has an ACTIVE booking
 *   (status 'pending' = paid reserve awaiting decision, or
 *   'confirmed' = occupied). Even if a locked field is POSTed, the
 *   save path re-checks the lock INSIDE the transaction (SELECT
 *   ... FOR UPDATE on the listing row + the booking rows) and
 *   forces the DB values — the server never trusts the client.
 *   The template renders those inputs disabled with a lock note.
 *   listing.status is deliberately NOT editable here — the
 *   draft→pending→approved lifecycle belongs to the host
 *   application wizard + the admin review queue.
 *
 * FIELDS / VALUES 1:1 with the wizard (HostStep2Controller):
 *   categories, property types, capacity options, parking options,
 *   the six amenity keys and their labels, and the validation
 *   messages are the same constants.
 *
 * PHOTOS 1:1 with the wizard (HostStep3Controller):
 *   upload base  <project>/public/uploads/listing_photos
 *   cover        cover_<time>_<uniqid>.<ext>  → photo_type 'cover',  sort_order 0
 *                (an edit-replace DELETEs the previous cover row(s) first)
 *   additional   photo_<time>_<uniqid>.<ext>  → photo_type 'additional',
 *                sort_order continues after MAX(existing)
 *   JPG/PNG only, 5MB per file, max 20 additional photos; per-file
 *   try/catch keeps the same "skip on failure" behaviour.
 *   Remove photo deletes the listing_photos ROW only (the wizard
 *   never unlinks files — matched).
 *
 * CSRF — self-contained, same _rh_csrf_tokens session store as
 *   HostStep2Controller (token id 'host_listing_edit', validated
 *   with hash_equals; accepts csrf_token or _token field names).
 *
 * Redirects: successful save → /host/listings; remove_photo →
 *   back to the edit page; failed POST → re-render with the
 *   submitted values re-stuck (same pattern as Step 2).
 *
 * Render vars: host, notification_count, pending_tenants_count,
 *   is_host (host/base.html.twig shell contract — same as
 *   HostListingsController) + l (the listing row normalized),
 *   photos (cover + additional, URLs resolved via resolvePhoto),
 *   errors, old, price_locked, capacity_options, parking_options,
 *   amenities (key + label, in wizard order), status_class,
 *   status_label, csrf_host_listing_edit.
 */
class HostEditListingController extends AbstractController
{
    /* =====================================================
       OPTION MAPS — same values as HostStep2Controller.
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

    private const AMENITIES = [
        ['key' => 'wifi',              'label' => 'Wi-fi'],
        ['key' => 'aircon',            'label' => 'Aircon'],
        ['key' => 'pet-friendly',      'label' => 'Pet Friendly'],
        ['key' => 'free-water',        'label' => 'Free Water'],
        ['key' => 'free-electricity',  'label' => 'Free Electricity'],
        ['key' => 'security',          'label' => '24/7 Security'],
    ];

    /* Photo limits — same as HostStep3Controller. */
    private const MAX_ADDITIONAL_PHOTOS = 20;
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png'];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(private readonly Connection $db) {}

    /* =====================================================================
     |  GET /host/listings/edit?id=N   ·   POST /host/listings/edit
     | ================================================================== */
    #[Route('/host/listings/edit', name: 'app_host_listing_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostListingsController) ---- */
        $userId = (int) $session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $dbUser = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host, created_at
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if (!$dbUser) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }

        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* ---- Listing id: POST hidden field wins, GET ?id= falls back ---- */
        $listingId = (int) $request->request->get('id', (string) $request->query->get('id', '0'));
        if ($listingId <= 0) {
            return $this->redirect('/host/listings');
        }

        /* ---- Load + ownership check (silent bounce, like legacy) ---- */
        $listing = $this->db->fetchAssociative(
            'SELECT * FROM listings WHERE id = :id LIMIT 1',
            ['id' => $listingId]
        );

        if (!$listing || (int) $listing['user_id'] !== $userId) {
            return $this->redirect('/host/listings');
        }

        /* ---- Lock state (pending = reserving, confirmed = occupied) ---- */
        $priceLocked = $this->hasActiveBooking($listingId);

        /* ---- CSRF token (same store as HostStep2Controller) ---- */
        $csrfToken = $this->ensureCsrfToken($session, 'host_listing_edit');

        $errors = [];

        /* =====================================================
           POST — two intents (save / remove_photo)
           ===================================================== */

        if ($request->isMethod('POST')) {

            $intent = (string) $request->request->get('intent', 'save');
            $csrfOk = $this->csrfValid($session, 'host_listing_edit', $this->postedToken($request));

            /* ---- intent=remove_photo — the × on a photo thumbnail ---- */
            if ($csrfOk && $intent === 'remove_photo') {
                $photoId = (int) $request->request->get('photo_id', '0');

                if ($photoId > 0) {
                    /* Ownership via JOIN — the photo must belong to a
                       listing owned by this host. Row-only delete (the
                       wizard never unlinks files — matched). */
                    $photo = $this->db->fetchAssociative(
                        "SELECT ph.id
                           FROM listing_photos ph
                           JOIN listings l ON l.id = ph.listing_id
                          WHERE ph.id = :pid AND l.id = :lid AND l.user_id = :uid
                          LIMIT 1",
                        ['pid' => $photoId, 'lid' => $listingId, 'uid' => $userId]
                    );

                    if ($photo) {
                        $this->db->delete('listing_photos', ['id' => $photoId]);
                    }
                }

                return $this->redirect('/host/listings/edit?id=' . $listingId);
            }

            if (!$csrfOk) {
                $errors[] = 'Your session expired. Please try again.';
            }

            /* =================================================
               intent=save — collect + validate + persist
               ================================================= */

            $trimmed = static fn (string $key): string => trim((string) $request->request->get($key, ''));

            $title        = $trimmed('title');
            $exactAddress = $trimmed('exact_address');
            $description  = $trimmed('description');
            $houseRules   = $trimmed('house_rules');
            $price        = $trimmed('price');
            $capacity     = $trimmed('capacity');
            $bedrooms     = $trimmed('bedrooms');
            $bathrooms    = $trimmed('bathrooms');
            $sizeSqm      = $trimmed('size_sqm');
            $floor        = $trimmed('floor');
            $parking      = $trimmed('parking');

            /* Amenities — same scalar-wrapping dual-compat as Step 2. */
            $rawAmenities = $request->request->all()['amenities'] ?? [];
            if (!is_array($rawAmenities)) {
                $rawAmenities = [$rawAmenities];
            }
            $amenityKeys = array_map(static fn (array $a): string => $a['key'], self::AMENITIES);
            $selectedAmenities = array_values(array_intersect($rawAmenities, $amenityKeys));

            /* Files — cover replace (single) + additional adds (multi). */
            $coverFile = $request->files->get('cover_photo');
            if (!$coverFile instanceof UploadedFile) {
                $coverFile = null;
            }

            $postedAdditional = $request->files->get('additional_photos', []);
            if (!is_array($postedAdditional)) {
                $postedAdditional = [$postedAdditional];
            }
            $additionalFiles = [];
            foreach ($postedAdditional as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $additionalFiles[] = $file;
                }
            }

            /* ---- Old array (re-stick on failed POST) ---- */
            $old = [
                'title'         => $title,
                'exact_address' => $exactAddress,
                'description'   => $description,
                'house_rules'   => $houseRules,
                'price'         => $price,
                'capacity'      => $capacity,
                'bedrooms'      => $bedrooms,
                'bathrooms'     => $bathrooms,
                'size_sqm'      => $sizeSqm,
                'floor'         => $floor,
                'parking'       => $parking,
                'amenities'     => $selectedAmenities,
            ];

            /* ---- Validation (messages 1:1 with Step 2 / Step 3) ---- */

            if (!$errors) {
                if ($title === '') {
                    $errors[] = 'Please enter a title for your space.';
                }

                if ($exactAddress === '') {
                    $errors[] = 'Please enter the exact address.';
                }

                if ($description === '') {
                    $errors[] = 'Please describe your space.';
                }

                if (!$priceLocked) {
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
                }

                /* Photo files — same limits as Step 3. */
                if ($coverFile !== null && !$coverFile->isValid()) {
                    $coverFile = null;
                }

                if ($coverFile !== null && $coverFile->getSize() > self::MAX_FILE_SIZE) {
                    $errors[] = 'The cover photo must not exceed 5MB.';
                }

                if ($coverFile !== null && !in_array($coverFile->getClientMimeType(), self::ALLOWED_TYPES, true)) {
                    $errors[] = 'The cover photo must be a JPG or PNG file.';
                }

                if (count($additionalFiles) > self::MAX_ADDITIONAL_PHOTOS) {
                    $errors[] = 'You can upload a maximum of ' . self::MAX_ADDITIONAL_PHOTOS . ' additional photos.';
                }

                foreach ($additionalFiles as $file) {
                    if ($file->getSize() > self::MAX_FILE_SIZE) {
                        $errors[] = 'Each additional photo must not exceed 5MB.';
                        break;
                    }

                    if (!in_array($file->getClientMimeType(), self::ALLOWED_TYPES, true)) {
                        $errors[] = 'Additional photos must be JPG or PNG files.';
                        break;
                    }
                }
            }

            /* =================================================
               IF VALID — TRANSACTION: lock re-check + UPDATE
               listing + cover replace + additional adds
               ================================================= */

            if (!$errors) {
                $this->db->beginTransaction();

                try {
                    /* FOR UPDATE on the listing row + re-check ownership
                       and the lock INSIDE the transaction — the server
                       never trusts the disabled inputs. */
                    $owned = $this->db->executeQuery(
                        'SELECT id FROM listings WHERE id = :id AND user_id = :uid FOR UPDATE',
                        ['id' => $listingId, 'uid' => $userId]
                    )->fetchAssociative();

                    if (!$owned) {
                        $this->db->rollBack();
                        return $this->redirect('/host/listings');
                    }

                    $bookingRows = $this->db->fetchAllAssociative(
                        "SELECT id FROM bookings
                          WHERE listing_id = :id
                            AND status IN ('pending', 'confirmed')
                          FOR UPDATE",
                        ['id' => $listingId]
                    );
                    $priceLockedNow = count($bookingRows) > 0;

                    /* Cosmetic fields — always updatable. */
                    $updates = [
                        'title'         => $title,
                        'exact_address' => $exactAddress,
                        'description'   => $description,
                        'house_rules'   => $houseRules,
                        'amenities'     => json_encode($selectedAmenities),
                    ];

                    /* Locked fields — only when the space is free. */
                    if (!$priceLockedNow) {
                        $updates['price']     = $price;
                        $updates['capacity']  = $capacity;
                        $updates['bedrooms']  = (int) $bedrooms;
                        $updates['bathrooms'] = (int) $bathrooms;
                        $updates['size_sqm']  = $sizeSqm;
                        $updates['floor']     = $floor;
                        $updates['parking']   = $parking;
                    }

                    $set = implode(', ', array_map(
                        static fn (string $col): string => $col . ' = :' . $col,
                        array_keys($updates)
                    ));
                    $updates['id'] = $listingId;

                    $this->db->executeStatement(
                        "UPDATE listings SET {$set} WHERE id = :id",
                        $updates
                    );

                    /* Upload directories — same as HostStep3Controller. */
                    $uploadBase = $this->getParameter('kernel.project_dir') . '/public/uploads/listing_photos';
                    $coverUploadDirectory      = $uploadBase . '/cover';
                    $additionalUploadDirectory = $uploadBase . '/additional';

                    if (!is_dir($coverUploadDirectory)) {
                        @mkdir($coverUploadDirectory, 0755, true);
                    }
                    if (!is_dir($additionalUploadDirectory)) {
                        @mkdir($additionalUploadDirectory, 0755, true);
                    }

                    /* ---- Cover replace: DELETE old cover row(s), INSERT new ---- */
                    if ($coverFile !== null) {
                        $coverExtension = strtolower($coverFile->getClientOriginalExtension());
                        $coverFileName  = 'cover_' . time() . '_' . uniqid() . '.' . $coverExtension;
                        $coverDbPath    = 'uploads/listing_photos/cover/' . $coverFileName;

                        try {
                            $coverFile->move($coverUploadDirectory, $coverFileName);

                            $this->db->executeStatement(
                                "DELETE FROM listing_photos
                                  WHERE listing_id = :lid AND photo_type = 'cover'",
                                ['lid' => $listingId]
                            );

                            $this->db->executeStatement(
                                "INSERT INTO listing_photos (listing_id, photo_path, photo_type, sort_order)
                                 VALUES (:lid, :photo_path, 'cover', 0)",
                                ['lid' => $listingId, 'photo_path' => $coverDbPath]
                            );
                        } catch (\Throwable $moveError) {
                            error_log('host-edit-listing cover replace failed: ' . $moveError->getMessage());
                        }
                    }

                    /* ---- Additional adds: sort_order continues after MAX ---- */
                    if (count($additionalFiles) > 0) {
                        $sortOrder = (int) $this->db->fetchOne(
                            "SELECT COALESCE(MAX(sort_order), 0) FROM listing_photos
                              WHERE listing_id = :lid AND photo_type = 'additional'",
                            ['lid' => $listingId]
                        ) + 1;

                        foreach ($additionalFiles as $file) {
                            $extension = strtolower($file->getClientOriginalExtension());
                            $fileName  = 'photo_' . time() . '_' . uniqid() . '.' . $extension;
                            $dbPath    = 'uploads/listing_photos/additional/' . $fileName;

                            try {
                                $file->move($additionalUploadDirectory, $fileName);

                                $this->db->executeStatement(
                                    "INSERT INTO listing_photos (listing_id, photo_path, photo_type, sort_order)
                                     VALUES (:lid, :photo_path, 'additional', :sort_order)",
                                    ['lid' => $listingId, 'photo_path' => $dbPath, 'sort_order' => $sortOrder]
                                );

                                $sortOrder++;
                            } catch (\Throwable $moveError) {
                                error_log('host-edit-listing additional move/INSERT failed: ' . $moveError->getMessage());
                            }
                        }
                    }

                    $this->db->commit();

                    return $this->redirect('/host/listings');
                } catch (\Throwable $e) {
                    if ($this->db->isTransactionActive()) {
                        $this->db->rollBack();
                    }
                    error_log('host-edit-listing save failed: ' . $e->getMessage());
                    $errors[] = 'Could not save your changes. Please try again.';
                }
            }
        }

        /* =====================================================
           DATA FOR THE RENDER (GET, or failed POST fall-through)
           ===================================================== */

        /* ---- Amenities from the DB row (GET seeding) ---- */
        $dbAmenities = json_decode((string) ($listing['amenities'] ?? ''), true);
        if (!is_array($dbAmenities)) {
            $dbAmenities = [];
        }
        $amenityKeys = array_map(static fn (array $a): string => $a['key'], self::AMENITIES);
        $dbAmenities = array_values(array_intersect($dbAmenities, $amenityKeys));

        /* ---- Old array: posted values (failed POST) or DB values (GET) ---- */
        if (!isset($old)) {
            $old = [
                'title'         => (string) $listing['title'],
                'exact_address' => (string) $listing['exact_address'],
                'description'   => (string) $listing['description'],
                'house_rules'   => (string) ($listing['house_rules'] ?? ''),
                'price'         => (string) $listing['price'],
                'capacity'      => (string) $listing['capacity'],
                'bedrooms'      => (string) $listing['bedrooms'],
                'bathrooms'     => (string) $listing['bathrooms'],
                'size_sqm'      => (string) $listing['size_sqm'],
                'floor'         => (string) ($listing['floor'] ?? ''),
                'parking'       => (string) $listing['parking'],
                'amenities'     => $dbAmenities,
            ];
        }

        /* ---- Photos: cover first, then additional by sort_order ---- */
        $photoRows = $this->db->fetchAllAssociative(
            "SELECT id, photo_path, photo_type, sort_order
               FROM listing_photos
              WHERE listing_id = :lid
              ORDER BY (photo_type = 'cover') DESC, sort_order ASC, id ASC",
            ['lid' => $listingId]
        );

        $cover    = null;
        $additional = [];
        foreach ($photoRows as $row) {
            $resolved = [
                'id'  => (int) $row['id'],
                'url' => $this->resolvePhoto((string) $row['photo_path']),
            ];

            if ($row['photo_type'] === 'cover' && $cover === null) {
                $cover = $resolved;
            } elseif ($row['photo_type'] === 'additional') {
                $additional[] = $resolved;
            }
        }

        /* ---- Status pill (same helpers as HostListingsController) ---- */
        $status = (string) $listing['status'];

        /* ---- Shell contract (host/base.html.twig — same as
           HostListingsController) ---- */
        $host = [
            'name'         => (string) $dbUser['name'],
            'avatar'       => !empty($dbUser['avatar_path'])
                ? $this->resolvePhoto((string) $dbUser['avatar_path'], '/images/default-avatar.png')
                : '/images/default-avatar.png',
            'member_since' => date('F Y', strtotime((string) $dbUser['created_at'])),
            'location'     => '',
            'email'        => (string) $dbUser['email'],
            'phone'        => '',
            'age'          => '',
            'about'        => '',
        ];

        /* Category / property type labels for the read-only strip. */
        $categoryLabel = (string) (array_search((string) $listing['category'], self::LISTING_CATEGORIES, true) ?: $listing['category']);
        $propertyLabel = (string) (array_search((string) $listing['property_type'], self::PROPERTY_TYPES, true) ?: $listing['property_type']);

        return $this->render('host/edit_listing.html.twig', [
            /* Host shell contract */
            'host'                  => $host,
            'notification_count'    => $this->countUnreadNotifications($userId),
            'pending_tenants_count' => $this->countPendingTenants($userId),
            'is_host'               => true,

            /* Page data */
            'l'                     => [
                'id'             => (int) $listing['id'],
                'title'          => (string) $listing['title'],
                'location'       => (string) $listing['location'],
                'category_label' => $categoryLabel,
                'property_label' => $propertyLabel,
                'status'         => $status,
                'status_class'   => $this->statusClass($status),
                'status_label'   => $this->statusLabel($status),
            ],
            'cover'                 => $cover,
            'additional'            => $additional,
            'errors'                => $errors,
            'old'                   => $old,
            'price_locked'          => $priceLocked,
            'capacity_options'      => self::CAPACITY_OPTIONS,
            'parking_options'       => self::PARKING_OPTIONS,
            'amenities'             => self::AMENITIES,
            'csrf_host_listing_edit' => $csrfToken,
        ]);
    }

    /* =====================================================================
     *  ACTIVE-BOOKING CHECK — pending (paid reserve, awaiting decision)
     *  or confirmed (occupied) → the rate & specs are locked.
     * ================================================================= */

    private function hasActiveBooking(int $listingId): bool
    {
        try {
            return ((int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM bookings
                  WHERE listing_id = :id
                    AND status IN ('pending', 'confirmed')",
                ['id' => $listingId]
            )) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /* =====================================================================
     *  STATUS HELPERS — same as HostListingsController
     *  (approved renders as "Active").
     * ================================================================= */

    private function statusClass(string $status): string
    {
        switch ($status) {
            case 'approved':
                return 'hp-status-active';
            case 'pending':
                return 'hp-status-pending';
            case 'rejected':
                return 'hp-status-rejected';
            case 'unlisted':
                return 'hp-status-unlisted';
            default:
                return 'hp-status-pending';
        }
    }

    private function statusLabel(string $status): string
    {
        switch ($status) {
            case 'approved':
                return 'Active';
            case 'pending':
                return 'Pending';
            case 'rejected':
                return 'Rejected';
            case 'unlisted':
                return 'Unlisted';
            default:
                return ucfirst($status);
        }
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostListingsController.
     * ================================================================= */

    private function countUnreadNotifications(int $userId): int
    {
        try {
            return (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0',
                ['u' => $userId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function countPendingTenants(int $hostUserId): int
    {
        try {
            return (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM bookings b
                 JOIN listings l ON l.id = b.listing_id
                 WHERE l.user_id = :h AND b.status = 'pending'",
                ['h' => $hostUserId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* =====================================================================
     *  CSRF — same self-contained store as HostStep2Controller /
     *  SecurityController / WalletController (kept identical).
     * ================================================================= */

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

    /* =====================================================================
     *  PHOTO PATH RESOLVER — same as HostListingsController /
     *  HostProfileController / AdminController / AccountController.
     * ================================================================= */

    private function resolvePhoto(string $path, string $fallback = '/images/ListingPlaceholder.png'): string
    {
        $path = trim($path);
        if ($path === '') {
            return $fallback;
        }
        /* BUGFIX: no leading-slash early-return — DB values like
           /webprogg/uploads/avatars/… must be remapped, not echoed
           (same fix as HostPublicProfileController) */
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $path = str_replace('\\', '/', ltrim($path, '/'));
        if (stripos($path, 'webprogg/') === 0) {
            $path = substr($path, strlen('webprogg/'));
        }
        if (stripos($path, 'host/') === 0) {
            $path = substr($path, strlen('host/'));
            return '/uploads/host/' . $path;
        }
        if (stripos($path, 'uploads/') === 0) {
            return '/' . $path;
        }
        if (stripos($path, 'cover_') === 0) {
            return '/uploads/listing_photos/cover/' . basename($path);
        }
        return '/uploads/listing_photos/' . $path;
    }
}
