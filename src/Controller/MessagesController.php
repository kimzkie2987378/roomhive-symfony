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
 * RoomHive — My Account › Messages (tenant inbox).
 * 1:1 port of legacy user/usermessages.php (chat-app layout: a
 * "Chats" pane on the left with search + All/Unread filtering, and
 * the open thread on the right).
 *
 *   GET  /messages       → page (Chats pane + open thread)
 *   POST /messages/send  → legacy sendmessage.php (composer submit)
 *   GET  /messages/start/{hostId}
 *                        → legacy start-conversation.php: the host
 *                          public profile's "Message {host}" CTA lands
 *                          here — the thread with that host is found
 *                          or created, then the inbox is opened with
 *                          ?conversation=N so the conversation is
 *                          already on screen (mobile opens the thread
 *                          pane too, via is_explicit_thread).
 *
 *   ?conversation=N selects the thread
 *
 * Legacy behaviour kept intact:
 *   - Assumed schema: conversations (id, user_id, host_id, listing_id,
 *     last_message_at) and messages (id, conversation_id, sender_id,
 *     body, created_at, read_at). Both tables self-heal
 *     (CREATE TABLE IF NOT EXISTS) — same port convention as
 *     ProfileController's wallet/user_id_documents self-heal.
 *   - Conversation-list SQL is the legacy query verbatim (ORDER BY
 *     c.last_message_at DESC), including h.id AS host_id so the
 *     thread header can link out to the host's public profile
 *     (?id=<host_id>).
 *   - Thread selection: ?conversation=<id> is explicit; on larger
 *     screens the most recent conversation opens by default so the
 *     page never lands empty; on mobile only an explicit
 *     ?conversation opens the thread pane (up-msg-mobile-open) so
 *     the visitor lands on the list first, same as a normal chat app.
 *   - Opening a thread does NOT mark messages read — the pasted page
 *     has no UPDATE at all. (If legacy sendmessage.php marked reads,
 *     paste it and it folds into send().)
 *   - Bell badge on THIS page is 0 (legacy: $notification_count = 0).
 *   - is_host = 1 → /host/profile; missing user row → clear + login.
 *
 * Port notes:
 *   - h() → Twig auto-escape; nl2br(h($body)) → |nl2br (pre-escapes).
 *   - resolve_photo() → resolveAvatar(): empty → default avatar,
 *     http(s) stays as-is, 'webprogg/' prefixes stripped to the root.
 *   - csrf_field() → per-key session token pair (key 'messages_send'),
 *     rendered as the csrf_token form field.
 *   - sendmessage.php was not in the paste; send() is the minimal
 *     sane port (auth → CSRF → ownership → INSERT →
 *     last_message_at = NOW() → PRG back to the thread). Paste the
 *     real file and it gets reconciled 1:1.
 *   - Send errors surface through a tiny session flash (rendered
 *     only when present) so a failed send is never silent.
 */
class MessagesController extends AbstractController
{
    private const CSRF_KEY       = 'messages_send';
    private const DEFAULT_AVATAR = '/images/default-avatar.png';

    public function __construct(private readonly Connection $db) {}

    #[Route('/messages', name: 'app_messages', methods: ['GET'], priority: 2)]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* PORT ADDITION — self-heal the two chat tables */
        $this->ensureSchema();

        /* -----------------------------------------------------
           USER DATA
        ------------------------------------------------------ */
        $dbUser = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if (!$dbUser) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        $dbUser['id'] = (int)$dbUser['id'];

        if ((int)$dbUser['is_host'] === 1) {
            return new RedirectResponse('/host/profile');
        }

        /* legacy: $notification_count = 0 on this page — kept 1:1 */
        $notificationCount = 0;

        /* -----------------------------------------------------
           CONVERSATION LIST — legacy SQL verbatim, including
           h.id AS host_id so the thread header can link out to
           the host's public profile (?id=<host_id>).
        ------------------------------------------------------ */
        $convRows = $this->db->fetchAllAssociative(
            "SELECT c.id, c.listing_id, c.last_message_at,
                    h.id AS host_id, h.name AS host_name, h.avatar_path AS host_avatar,
                    l.title AS listing_title,
                    (SELECT body FROM messages m
                      WHERE m.conversation_id = c.id
                      ORDER BY m.created_at DESC LIMIT 1) AS last_body,
                    (SELECT COUNT(*) FROM messages m
                      WHERE m.conversation_id = c.id
                        AND m.sender_id != :uid2
                        AND m.read_at IS NULL) AS unread_count
               FROM conversations c
               JOIN users h ON h.id = c.host_id
               LEFT JOIN listings l ON l.id = c.listing_id
              WHERE c.user_id = :uid
              ORDER BY c.last_message_at DESC",
            ['uid' => $userId, 'uid2' => $userId]
        );

        $conversations = array_map(function ($row) {
            return [
                'id'            => (int)$row['id'],
                'listing_id'    => $row['listing_id'] ? (int)$row['listing_id'] : null,
                'listing_title' => (string)($row['listing_title'] ?? ''),
                'host_id'       => (int)$row['host_id'],
                'host_name'     => (string)$row['host_name'],
                'host_avatar'   => $this->resolveAvatar($row['host_avatar'] ?? null),
                'last_body'     => (string)($row['last_body'] ?? ''),
                'last_at'       => $row['last_message_at'],
                'unread'        => (int)$row['unread_count'],
            ];
        }, $convRows);

        /* -----------------------------------------------------
           OPEN THREAD
           ?conversation=<id> selects which thread to show. On
           larger screens we default to the most recent
           conversation so the page never opens empty. On mobile,
           only an explicit ?conversation opens the thread pane
           (up-msg-mobile-open) so the visitor lands on the list
           first, same as a normal chat app.
        ------------------------------------------------------ */
        $isExplicitThread     = $request->query->has('conversation');
        $activeConversationId = $isExplicitThread
            ? (int)$request->query->get('conversation')
            : ($conversations[0]['id'] ?? null);

        $activeConversation = null;
        $threadMessages     = [];

        if ($activeConversationId !== null) {
            foreach ($conversations as $c) {
                if ($c['id'] === $activeConversationId) {
                    $activeConversation = $c;
                    break;
                }
            }

            if ($activeConversation) {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT sender_id, body, created_at
                       FROM messages
                      WHERE conversation_id = :cid
                      ORDER BY created_at ASC',
                    ['cid' => $activeConversationId]
                );

                foreach ($rows as $m) {
                    $ts = strtotime((string)$m['created_at']);
                    $threadMessages[] = [
                        'body'      => (string)$m['body'],
                        'is_mine'   => (int)$m['sender_id'] === $userId,
                        'time'      => date('g:i A', $ts),
                        'day_key'   => date('Y-m-d', $ts),
                        'day_label' => $this->messageDayLabel($ts),
                    ];
                }
            }
        }

        $flash = $this->flashTake($session);

        return $this->render('user/messages.html.twig', [
            'user'                => $dbUser,
            'flash'               => $flash,
            'conversations'       => $conversations,
            'is_explicit_thread'  => $isExplicitThread,
            'active_conversation' => $activeConversation,
            'thread_messages'     => $threadMessages,
            'notif_count'         => $notificationCount,
            'csrf_messages'       => $this->ensureCsrfToken($session, self::CSRF_KEY),
        ]);
    }

    /**
     * Legacy sendmessage.php (not in the paste) — the composer's
     * POST target. Minimal sane port: auth → CSRF → ownership →
     * INSERT → touch last_message_at → PRG back to the thread.
     */
    #[Route('/messages/send', name: 'app_messages_send', methods: ['POST'], priority: 2)]
    public function send(Request $request): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $convId = (int)$request->request->get('conversation_id');
        $body   = trim((string)$request->request->get('body'));
        $back   = '/messages' . ($convId > 0 ? '?conversation=' . $convId : '');

        if (!$this->csrfValid($session, self::CSRF_KEY, $this->postedToken($request))) {
            $this->flashSet($session, 'error', 'Your session expired — please try sending that again.');
        } elseif ($body === '') {
            $this->flashSet($session, 'error', 'Message cannot be empty.');
        } else {
            try {
                $own = $this->db->fetchOne(
                    'SELECT id FROM conversations WHERE id = :id AND user_id = :u LIMIT 1',
                    ['id' => $convId, 'u' => $userId]
                );

                if (!$own) {
                    $this->flashSet($session, 'error', 'That conversation could not be found.');
                } else {
                    $this->db->insert('messages', [
                        'conversation_id' => $convId,
                        'sender_id'       => $userId,
                        'body'            => $body,
                        'created_at'      => date('Y-m-d H:i:s'),
                    ]);

                    $this->db->executeStatement(
                        'UPDATE conversations SET last_message_at = NOW() WHERE id = :id',
                        ['id' => $convId]
                    );
                }
            } catch (\Throwable $e) {
                $this->flashSet($session, 'error', 'Something went wrong sending your message. Please try again.');
                error_log('messages send failed: ' . $e->getMessage());
            }
        }

        return new RedirectResponse($back);
    }

    /**
     * Legacy start-conversation.php (the host public profile's
     * "Message {host}" button). Finds the tenant's most recent
     * thread with this host — one thread per host pair, reused
     * whatever listing it was originally opened for — or creates a
     * fresh empty one, then PRGs into the inbox with
     * ?conversation=N so the conversation is already open on
     * arrival (an empty thread renders the "Say hello" welcome +
     * composer). Idempotent GET, so no CSRF token is needed:
     * replaying the URL can never duplicate a conversation.
     */
    #[Route('/messages/start/{id}', name: 'app_messages_start', methods: ['GET'], requirements: ['id' => '\\d+'], priority: 2)]
    public function start(Request $request, int $id): Response
    {
        $session = $request->getSession();
        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        $this->ensureSchema();

        $hostId = $id;

        /* The CTA only exists on host profiles, so the target must
           be an actual host — and you cannot start a thread with
           yourself (the profile shows "Edit your profile" there,
           but a hand-typed URL should not create junk rows). */
        $host = $this->db->fetchAssociative(
            'SELECT id FROM users WHERE id = :id AND is_host = 1 LIMIT 1',
            ['id' => $hostId]
        );

        if (!$host || $hostId === $userId) {
            $this->flashSet($session, 'error', 'That host could not be found.');
            return new RedirectResponse('/messages');
        }

        try {
            /* Find-or-create: reuse the newest thread with this host */
            $convId = (int)$this->db->fetchOne(
                'SELECT id FROM conversations
                  WHERE user_id = :u AND host_id = :h
                  ORDER BY last_message_at DESC, id DESC
                  LIMIT 1',
                ['u' => $userId, 'h' => $hostId]
            );

            if ($convId <= 0) {
                $this->db->insert('conversations', [
                    'user_id'         => $userId,
                    'host_id'         => $hostId,
                    'listing_id'      => null,
                    'last_message_at' => date('Y-m-d H:i:s'),
                ]);
                $convId = (int)$this->db->lastInsertId();
            }
        } catch (\Throwable $e) {
            $this->flashSet($session, 'error', 'The conversation could not be started. Please try again.');
            error_log('messages start failed: ' . $e->getMessage());
            return new RedirectResponse('/messages');
        }

        return new RedirectResponse('/messages?conversation=' . $convId);
    }

    /* =====================================================================
     |  HELPERS
     | ================================================================== */

    /**
     * PORT ADDITION — self-heal (same convention as ProfileController's
     * wallet/user_id_documents). Creates the two chat tables exactly as
     * the legacy page assumes them; never throws.
     */
    private function ensureSchema(): void
    {
        try {
            $this->db->executeStatement(
                "CREATE TABLE IF NOT EXISTS conversations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    host_id INT NOT NULL,
                    listing_id INT NULL,
                    last_message_at DATETIME NULL,
                    INDEX idx_conversations_user (user_id),
                    INDEX idx_conversations_host (host_id),
                    INDEX idx_conversations_listing (listing_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $this->db->executeStatement(
                "CREATE TABLE IF NOT EXISTS messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    conversation_id INT NOT NULL,
                    sender_id INT NOT NULL,
                    body TEXT NOT NULL,
                    created_at DATETIME NULL,
                    read_at DATETIME NULL,
                    INDEX idx_messages_conversation (conversation_id),
                    INDEX idx_messages_sender (sender_id),
                    INDEX idx_messages_read (read_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            error_log('messages ensureSchema failed: ' . $e->getMessage());
        }
    }

    /**
     * Human day-divider label for a message timestamp, the way most
     * chat apps group messages ("Today", "Yesterday", then a full date).
     */
    private function messageDayLabel(int $timestamp): string
    {
        $day       = date('Y-m-d', $timestamp);
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        if ($day === $today) {
            return 'Today';
        }
        if ($day === $yesterday) {
            return 'Yesterday';
        }
        return date('F j, Y', $timestamp);
    }

    /**
     * Legacy resolve_photo(): empty → default avatar, http(s) stays
     * as-is, 'webprogg/' prefixes are stripped to the site root.
     */
    private function resolveAvatar(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return self::DEFAULT_AVATAR;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $n = ltrim($path, '/');
        if (stripos($n, 'webprogg/') === 0) {
            $n = substr($n, strlen('webprogg/'));
        }
        return '/' . $n;
    }

    /* ---- flash (rendered only when present) ---- */

    private function flashSet(SessionInterface $session, string $type, string $message): void
    {
        $session->set('_messages_flash', ['type' => $type, 'message' => $message]);
    }

    private function flashTake(SessionInterface $session): ?array
    {
        $flash = $session->get('_messages_flash');
        if ($flash) {
            $session->remove('_messages_flash');
        }
        return $flash;
    }

    /* ---- CSRF pair (same as ProfileController / WalletController) ---- */

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
}
