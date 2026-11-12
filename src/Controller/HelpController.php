<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — My Account › Help Center.
 * 1:1 port of legacy user/helpcenter.php.
 *
 *   GET|POST /help   → FAQ (React island) + contact support form
 *
 * Legacy behaviour kept intact:
 *   - PHP owns the FAQ data ($faqs array); React owns open/close state.
 *     The array is handed over as JSON via #react-faq-props with the
 *     JSON_HEX_* flag set (15) — same convention as the payments page's
 *     react-payments-props island.
 *   - Contact form inserts an OPEN row into support_tickets and shows
 *     the reply-to email on success.
 *
 * Port notes:
 *   - CSRF key 'help_contact' (per-key session token pair).
 *   - The React bundle path is assets/react/faq-accordion.js — copy it
 *     from webprogg/assets/react/ next to payment-history.js if you
 *     haven't already.
 */
class HelpController extends AbstractController
{
    private const CSRF_KEY = 'help_contact';

    public function __construct(private readonly Connection $db) {}

    #[Route('/help', name: 'app_help', methods: ['GET', 'POST'], priority: 2)]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if (!$user) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        $user['id'] = (int)$user['id'];

        if ((int)$user['is_host'] === 1) {
            return new RedirectResponse('/host/profile');
        }

        /* FAQ CONTENT — data source for the React faq-accordion island */
        $faqs = [
            ['q' => 'How do I book a stay?', 'a' => 'Open a listing you like and send a booking request with your dates. The host has 24 hours to accept or decline before the request expires.'],
            ['q' => 'When am I charged for a booking?', 'a' => 'Nothing is charged while a booking sits at "Pending." Once the host accepts, the charge goes through and the booking moves to "Confirmed."'],
            ['q' => 'How do I cancel a booking?', 'a' => "Go to My Bookings, open the booking, and choose Cancel. Refund amounts depend on the listing's cancellation policy, shown on the listing page."],
            ['q' => 'How do I get verified?', 'a' => 'Go to Profile & Account and upload one government-issued ID. Complete your profile (name, phone, age, location) and your Verified badge activates automatically.'],
            ['q' => 'How do I message a host?', 'a' => 'Open the listing and use Contact Host, or continue an existing conversation from the Messages tab in your account.'],
            ['q' => 'How do I change my password?', 'a' => 'Go to Profile & Account, then Manage Security, to set a new password.'],
        ];

        /* CONTACT SUPPORT — SUBMIT */
        $errors = [];
        $sent   = false;

        if ($request->isMethod('POST')) {
            if (!$this->csrfValid($session, self::CSRF_KEY, $this->postedToken($request))) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                $subject = trim((string)$request->request->get('subject', ''));
                $message = trim((string)$request->request->get('message', ''));

                if ($subject === '') {
                    $errors[] = 'Please choose a subject.';
                }
                if ($message === '') {
                    $errors[] = 'Please describe your issue.';
                }

                if (empty($errors)) {
                    try {
                        $this->db->insert('support_tickets', [
                            'user_id'    => $userId,
                            'subject'    => $subject,
                            'message'    => $message,
                            'status'     => 'open',
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                        $sent = true;
                    } catch (\Throwable $e) {
                        error_log('helpcenter: ticket insert failed: ' . $e->getMessage());
                        $errors[] = 'Something went wrong sending your message. Please try again.';
                    }
                }
            }
        }

        return $this->render('user/help.html.twig', [
            'user'        => $user,
            'faqs'        => $faqs,
            'errors'      => $errors,
            'sent'        => $sent,
            'notif_count' => $this->notifCount($userId),
            'csrf_help'   => $this->ensureCsrfToken($session, self::CSRF_KEY),
        ]);
    }

    /* =====================================================================
     |  HELPERS — same implementations as WalletController (kept identical)
     | ================================================================== */

    private function ensureCsrfToken(SessionInterface $session, string $key): string
    {
        $tokens = $session->get('_rh_csrf_tokens', []);
        if (!isset($tokens[$key])) {
            $tokens[$key] = bin2hex(random_bytes(16));
            $session->set('_rh_csrf_tokens', $tokens);
        }
        return (string)$tokens[$key];
    }

    private function csrfValid(SessionInterface $session, string $key, ?string $token): bool
    {
        $tokens = $session->get('_rh_csrf_tokens', []);
        return isset($tokens[$key]) && is_string($token) && hash_equals((string)$tokens[$key], $token);
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
            return (int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0',
                ['id' => $userId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}
