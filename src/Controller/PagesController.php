<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Form\ContactMessageType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PagesController extends AbstractController
{
    #[Route('/about-us', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('about/index.html.twig', [
            'current_page' => '/about-us',
        ]);
    }

    #[Route('/how-it-works', name: 'app_how_it_works')]
    public function howItWorks(Request $request): Response
    {
        $isLoggedIn = $request->getSession()->get('logged_in', false) === true;

        $renterSteps = [
            ['number' => 1, 'icon' => 'search',        'alt' => 'Search',          'title' => 'Search',       'description' => 'Browse listings by location, category, and price.'],
            ['number' => 2, 'icon' => 'eye',           'alt' => 'Choose',          'title' => 'Choose',       'description' => 'View details, photos, amenities, and compare options.'],
            ['number' => 3, 'icon' => 'calendar-plus', 'alt' => 'Book',            'title' => 'Book',         'description' => 'Select your dates and send a booking request.'],
            ['number' => 4, 'icon' => 'badge-check',   'alt' => 'Confirm Booking', 'title' => 'Confirm',      'description' => 'Host confirms your request and you are all set!'],
            ['number' => 5, 'icon' => 'key-round',     'alt' => 'Stay and Enjoy',  'title' => 'Stay & Enjoy', 'description' => 'Check in and enjoy your comfortable stay.'],
        ];

        $hostSteps = [
            ['number' => 1, 'icon' => 'house-plus',  'alt' => 'List Your Space',  'title' => 'List Your Space',  'description' => 'Add photos, details, amenities, and set your price.'],
            ['number' => 2, 'icon' => 'megaphone',   'alt' => 'Get Discovered',   'title' => 'Get Discovered',   'description' => 'Your listing goes live and matches with renters.'],
            ['number' => 3, 'icon' => 'inbox',       'alt' => 'Receive Bookings', 'title' => 'Receive Bookings', 'description' => 'Guests send booking requests for your review.'],
            ['number' => 4, 'icon' => 'badge-check', 'alt' => 'Confirm Booking',  'title' => 'Confirm Booking',  'description' => 'Review the request and confirm the booking.'],
            ['number' => 5, 'icon' => 'banknote',    'alt' => 'Host and Earn',    'title' => 'Host & Earn',      'description' => 'Welcome guests and earn with RoomHive.'],
        ];

        return $this->render('how-it-works/index.html.twig', [
            'is_logged_in' => $isLoggedIn,
            'renter_steps' => $renterSteps,
            'host_steps'   => $hostSteps,
            'current_page' => '/how-it-works',
        ]);
    }

    #[Route('/contacts', name: 'app_contacts')]
    public function contacts(Request $request, EntityManagerInterface $em): Response
    {
        $contact = new ContactMessage();
        $contact->setCreatedAt(new \DateTimeImmutable());

        $form = $this->createForm(ContactMessageType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($contact);
            $em->flush();

            $this->addFlash(
                'contact_success',
                sprintf(
                    "Thank you, %s! Your message has been received — we'll reply to %s within 24 hours.",
                    $contact->getFirstName(),
                    $contact->getEmail()
                )
            );

            return $this->redirectToRoute('app_contacts');
        }

        return $this->render('contacts/index.html.twig', [
            'form'         => $form,
            'current_page' => '/contacts',
        ]);
    }

    #[Route('/become-a-host', name: 'app_become_host')]
    public function becomeHost(): Response
    {
        return $this->redirectToRoute('app_host_apply');
    }

    /* =========================================================
       LOGOUT — priority: 2 makes this route WIN any path
       collision with a leftover /logout placeholder. invalidate()
       wipes every session key (logged_in, user_id, is_host...)
       AND destroys the server-side session, so even the 30-day
       cookie can only re-adopt an empty session.
    ========================================================== */
    #[Route('/logout', name: 'app_logout', priority: 2)]
    public function logout(Request $request): Response
    {
        $request->getSession()->invalidate();

        $this->addFlash('logout_success', 'You have been logged out. See you soon!');

        return $this->redirect('/');
    }

    /* =========================================================
       LISTING PAYMENT — placeholder until the real payment
       flow is wired to this route (if PaymentController owns
       app_listing_payment, DELETE this method — duplicate
       route names crash Symfony).
    ========================================================== */
    #[Route('/listings/{id}/payment', name: 'app_listing_payment', requirements: ['id' => '\d+'])]
    public function listingPayment(int $id): Response
    {
        return $this->render('pages/coming-soon.html.twig', [
            'page_name'    => 'app_listing_payment',
            'current_page' => '/listings',
        ]);
    }

    /* =========================================================
       PLACEHOLDER PAGES.
       ⚠ NEVER add /logout, /login, /register, /forgot-password
       or /become-a-host here — they live in their own methods/
       controllers. A duplicate /logout PATH here would shadow
       the real logout depending on method order (that bug
       caused "logout doesn't work").
    ========================================================== */
    #[Route('/profile', name: 'app_profile')]
    #[Route('/messages', name: 'app_messages')]
    #[Route('/settings', name: 'app_settings')]
    #[Route('/notifications', name: 'app_notifications')]
    #[Route('/wallet', name: 'app_wallet')]
    public function comingSoon(Request $request): Response
    {
        return $this->render('pages/coming-soon.html.twig', [
            'page_name'    => $request->attributes->get('_route'),
            'current_page' => $request->getPathInfo(),
        ]);
    }
}
