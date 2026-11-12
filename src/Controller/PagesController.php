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
       LOGOUT — hammer version. THE FIX:
       1) remove() deletes each auth key EXPLICITLY — even a
          worst-case invalidate() cannot resurrect them
       2) invalidate() then wipes the rest + rotates the ID
       3) NO addFlash after invalidate() — writing to the
          session after invalidating it re-activates it and
          carried the old state forward (that WAS the bug)
       4) no-store so this redirect can never be cached
    ========================================================== */
    #[Route('/logout', name: 'app_logout', priority: 2)]
    public function logout(Request $request): Response
    {
        // die('LOGOUT HANDLER RAN');   /* probe — only if it still fails */

        $session = $request->getSession();

        /* 1) Explicitly delete the auth keys — deterministic */
        $session->remove('logged_in');
        $session->remove('user_id');
        $session->remove('is_host');
        $session->remove('avatar_path');

        /* 2) Wipe everything + rotate the session ID */
        $session->invalidate();

        /* 3) Redirect with a no-store header */
        $response = $this->redirectToRoute('app_home');
        $response->headers->set('Cache-Control', 'no-store, max-age=0, must-revalidate');

        return $response;
    }
}
