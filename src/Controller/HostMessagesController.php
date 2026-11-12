<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Host › Messages.
 * 1:1 port of legacy host/hostmessages.php (SUPPORT CHAT
 * VISIBILITY edition).
 *
 *   GET|POST /host/messages  → conversation list (left) + thread
 *     pane (right). Host replies from this page; conversation
 *     list includes BOTH normal tenant chats (host_id = me) AND
 *     support chats (user_id = me, host_id = the system
 *     RoomHive Admin account at support@roomhive.local).
 *
 * SUPPORT CHAT VISIBILITY (the S1–S4 fixes legacy made):
 *   S1. Conversation list shows chats where THIS host is the
 *       user_id (support chats with the admin), not just chats
 *       where they are the host_id. Before this fix, a host who
 *       asked the admin something via Contacts could never see
 *       the admin's replies.
 *   S2. Support chats render with a RoomHive Admin · SUPPORT
 *       header and no listing tag; normal tenant chats unchanged.
 *   S3. Send handler ownership accepts EITHER side
 *       (host_id = me OR user_id = me); replies inside a support
 *       chat skip notifying the support account (the admin
 *       panel's unread badge picks them up instead).
 *   S4. Bell badge counts unread across BOTH roles.
 *
 * SCHEMA AUTO-DETECT (same as legacy): SHOW COLUMNS FROM messages,
 *   then use 'sent_at' if present else 'created_at' for the
 *   timestamp column; prefer 'is_read' for the read flag, fall
 *   back to 'read_at IS NULL'. The unread SQL, mark-read UPDATE,
 *   and message SELECT all use the detected names. If neither
 *   is_read nor read_at exists, unread always returns 0 and
 *   messages are never marked read (1 = 0 predicate).
 *
 * SEND (POST, PRG): CSRF-verified (session key 'hp_csrf',
 *   bin2hex(32), hash_equals — same per-session pattern as
 *   HostPayoutsController's pay_csrf). Body trimmed non-empty.
 *   Ownership check: conversations row where id = convId AND
 *   (host_id = me OR user_id = me) — single query, either side.
 *   On success: INSERT into messages (sender_id = me, NOW()),
 *   UPDATE conversations.last_message_at = NOW(), then notify
 *   the OTHER party (the side that's NOT me) — UNLESS the
 *   recipient is the support account (the admin panel picks
 *   the reply up). Notification link routes to the recipient's
 *   messages page (usermessages for tenant, hostmessages for
 *   host) with ?conversation= / ?c= respectively.
 *
 * FLASHES: session 'rh_flash' = ['type','message'], consumed
 *   once on the following GET — the same hp_flash_set/take
 *   pattern HostBookingsController / HostPayoutsController
 *   established.
 *
 * ICON SUBSTITUTIONS — the macros file uses the older lucide.dev
 *   names the rest of the shell standardized on:
 *     legacy `message-circle` → macro 'message'      (closest
 *                                visual: a single chat bubble.
 *                                'message-square' is the
 *                                sidebar icon; using the
 *                                rounded bubble here keeps the
 *                                empty-state art distinct)
 *     legacy `search`         → macro 'search'        (exists)
 *     legacy `info`           → macro 'circle-help'    (a circle
 *                                with a question mark reads
 *                                the same as info for a
 *                                "View listing" affordance —
 *                                'info' itself isn't in the
 *                                macro table)
 *     legacy `chevron-left`  → macro 'chevron-left'  (exists)
 *     legacy `send`          → macro 'arrow-up'      (the
 *                                closest send affordance in
 *                                the macro table — an upward
 *                                arrow inside a rounded button
 *                                reads the same as a paper
 *                                plane; CSS keeps it solid
 *                                via fill: currentColor)
 *
 *   The send button keeps its SOLID look — the macro emits
 *   fill="none", but .hp-msg-send-btn svg { fill: currentColor }
 *   overrides it (same trick the legacy page used).
 *
 * Auth: same gate as HostBookingsController / HostListingsController
 *   — logged_in + user_id (else app_login), then is_host = 1
 *   (else /host/become-a-host).
 */
class HostMessagesController extends AbstractController
{
    /** Session key for the host-messages CSRF token (legacy hp_csrf). */
    private const CSRF_KEY = 'hp_csrf';

    public function __construct(private readonly Connection $db) {}

    #[Route('/host/messages', name: 'app_host_messages', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        /* ---- Auth gate (same as HostBookingsController) ---- */
        $userId = (int) $session->get('user_id');
        $me     = $userId;
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* ---- Load the user row ---- */
        $dbUser = $this->db->fetchAssociative(
            'SELECT id, name, email, avatar_path, is_host, created_at
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );

        if (!$dbUser) {
            $session->clear();
            return $this->redirectToRoute('app_login');
        }

        /* ---- HOST GUARD — non-hosts go to Become a Host ---- */
        if ((int) $dbUser['is_host'] !== 1) {
            return $this->redirect('/host/become-a-host');
        }

        /* =========================================================
           CSRF TOKEN — generated once per session (legacy
           hp_csrf_token()); verified on POST below.
        ========================================================= */
        $csrfToken = (string) $session->get(self::CSRF_KEY);
        if ($csrfToken === '') {
            $csrfToken = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $csrfToken);
        }

        /* =========================================================
           BADGE COUNTS
           • sidebar notification_count = NOTIFICATIONS unread
             (sidebar bell + sidebar badge — same contract as every
             other host page)
           • pending_tenants_count (sidebar badge)
           • bell badge (notification_count) here is the S4
             unread-MESSAGES count across BOTH roles, same as
             legacy — see computeBellBadge() below.
        ========================================================= */
        $sidebarNotifCount = $this->countUnreadNotifications($userId);
        $pendingTenantsCount = $this->countPendingTenants($userId);

        /* ---- Host array (mirrors legacy $host from host_init) ---- */
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

        /* =========================================================
           MESSAGES SCHEMA AUTO-DETECT (1:1 with legacy)
           SHOW COLUMNS, then use 'sent_at' if present else
           'created_at'; prefer 'is_read' for the read flag,
           fall back to 'read_at IS NULL'. If neither exists,
           unread always returns 0 (1 = 0 predicate).
        ========================================================= */
        $msgCols = [];
        try {
            $msgCols = array_map(
                fn (array $r) => (string) $r['Field'],
                $this->db->fetchAllAssociative('SHOW COLUMNS FROM messages')
            );
        } catch (\Throwable $e) {
            /* Fallback: assume the columns the dump ships */
            $msgCols = ['id', 'conversation_id', 'sender_id', 'body',
                        'created_at', 'read_at', 'is_read'];
        }

        $msgTime       = in_array('sent_at', $msgCols, true) ? 'sent_at' : 'created_at';
        $hasIsRead     = in_array('is_read', $msgCols, true);
        $hasReadAt     = in_array('read_at', $msgCols, true);
        $hasReadFlag   = $hasIsRead || $hasReadAt;

        $unreadSql = $hasReadFlag
            ? ($hasIsRead ? 'm.is_read = 0' : 'm.read_at IS NULL')
            : '1 = 0';

        /* =========================================================
           SUPPORT ACCOUNT DETECTOR (1:1 with legacy
           hp_support_user_id()) — looks up the system RoomHive
           Admin account by email support@roomhive.local. Memoized
           per request. 0 if the row doesn't exist (support chats
           simply won't appear in that case).
        ========================================================= */
        $supportId = $this->findSupportUserId();

        /* =========================================================
           SEND A MESSAGE (POST, PRG) — CSRF-verified, then the
           same validation ladder as legacy. Every path flashes
           and PRG-redirects back to ?c={convId}.
        ========================================================= */
        if ($request->isMethod('POST') && $request->request->has('send_message')
            && $request->request->has('conversation_id')) {

            $convId = (int) $request->request->get('conversation_id');
            $body   = trim((string) ($request->request->get('body') ?? ''));
            $postedToken = (string) $request->request->get('hp_csrf', '');

            if (!is_string($postedToken) || $postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'Your session expired — please try sending that again.',
                ]);
            } elseif ($body === '') {
                $session->set('rh_flash', [
                    'type'    => 'error',
                    'message' => 'Message cannot be empty.',
                ]);
            } else {
                /* S3 — ownership: this host may be the host_id
                   (tenant chats) OR the user_id (support chats
                   with the admin). Single query, either side. */
                $own = $this->db->fetchAssociative(
                    'SELECT id FROM conversations
                     WHERE id = :id AND (host_id = :h1 OR user_id = :h2) LIMIT 1',
                    ['id' => $convId, 'h1' => $me, 'h2' => $me]
                );

                if (!$own) {
                    $session->set('rh_flash', [
                        'type'    => 'error',
                        'message' => 'That conversation does not exist.',
                    ]);
                } else {
                    try {
                        $this->db->insert('messages', [
                            'conversation_id' => $convId,
                            'sender_id'        => $me,
                            'body'             => $body,
                            $msgTime           => date('Y-m-d H:i:s'),
                        ]);

                        /* Legacy checked rowCount() === 1; DBAL
                           insert() returns 1 on success. */
                        $this->db->executeStatement(
                            'UPDATE conversations SET last_message_at = NOW() WHERE id = :id',
                            ['id' => $convId]
                        );

                        /* S3 — notify the other party, EXCEPT the
                           system support account (the admin panel's
                           unread badge picks the reply up instead) */
                        $convRow = $this->db->fetchAssociative(
                            'SELECT user_id, host_id FROM conversations WHERE id = :id LIMIT 1',
                            ['id' => $convId]
                        );

                        if ($convRow) {
                            $recipient = ((int) $convRow['user_id'] === $me)
                                ? (int) $convRow['host_id']
                                : (int) $convRow['user_id'];

                            if ($recipient > 0 && $recipient !== $supportId) {
                                $recipientLink = ((int) $convRow['user_id'] === $recipient)
                                    ? '/user/messages?conversation=' . $convId
                                    : '/host/messages?c=' . $convId;

                                $this->notify(
                                    $recipient,
                                    ($session->get('user_name') ?: 'Your host')
                                        . ' sent you a new message.',
                                    $recipientLink
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        $session->set('rh_flash', [
                            'type'    => 'error',
                            'message' => 'Something went wrong sending your message. Please try again.',
                        ]);
                        error_log('hostmessages send failed: ' . $e->getMessage());
                    }
                }
            }

            /* Hardcoded PRG target (legacy: ?c=convId) */
            return $this->redirectToRoute('app_host_messages', ['c' => $convId]);
        }

        /* =========================================================
           CONVERSATION LIST (S1 — both roles)
           Normal chats:  c.host_id = me  → partner is the tenant (u)
           Support chats: c.user_id = me AND c.host_id = support
                          → partner is the support account (s)
           Two CASE expressions pick name + avatar based on which
           side I am; the i_am_host_side flag drives the support-
           chat detection in the row mapper below.
        ========================================================= */
        $convRows = [];
        try {
            $convRows = $this->db->fetchAllAssociative(
                "SELECT c.id, c.listing_id, c.last_message_at, c.created_at,
                        CASE WHEN c.host_id = :hA THEN u.name        ELSE s.name        END AS partner_name,
                        CASE WHEN c.host_id = :hB THEN u.avatar_path ELSE s.avatar_path END AS partner_avatar,
                        (c.host_id = :hC) AS i_am_host_side,
                        l.title AS listing_title,
                        (SELECT body FROM messages m
                          WHERE m.conversation_id = c.id
                          ORDER BY m.$msgTime DESC LIMIT 1) AS last_body,
                        (SELECT COUNT(*) FROM messages m
                          WHERE m.conversation_id = c.id
                            AND m.sender_id != :hD
                            AND $unreadSql) AS unread_count
                   FROM conversations c
                   JOIN users u ON u.id = c.user_id
                   JOIN users s ON s.id = c.host_id
                   LEFT JOIN listings l ON l.id = c.listing_id
                  WHERE c.host_id = :hE
                     OR (c.user_id = :hF AND c.host_id = :sup)
                  ORDER BY COALESCE(c.last_message_at, c.created_at) DESC",
                [
                    'hA'  => $me, 'hB' => $me, 'hC' => $me, 'hD' => $me,
                    'hE'  => $me, 'hF' => $me, 'sup' => $supportId,
                ]
            );
        } catch (\Throwable $e) {
            /* Best-effort: empty list rather than 500 */
            $convRows = [];
        }

        $conversations = array_map(function (array $row) use ($supportId): array {
            $iAmHostSide = ((int) $row['i_am_host_side']) === 1;
            return [
                'id'            => (int) $row['id'],
                'listing_id'    => !empty($row['listing_id']) ? (int) $row['listing_id'] : null,
                'listing_title' => (string) ($row['listing_title'] ?? ''),
                'tenant_name'   => (string) $row['partner_name'],
                'tenant_avatar' => $this->resolvePhoto(
                    (string) ($row['partner_avatar'] ?? ''),
                    '/images/default-avatar.png'
                ),
                'last_body'     => (string) ($row['last_body'] ?? ''),
                'unread'        => (int) $row['unread_count'],
                /* S2 — support-chat flag (I'm the user side,
                   partner is the RoomHive Admin account) */
                'is_support'    => !$iAmHostSide && $supportId > 0,
            ];
        }, $convRows);

        /* =========================================================
           SELECTED THREAD
           ?c= explicit; otherwise the first conversation (legacy
           default). If the selected id isn't in the list (e.g. a
           support chat the host can't see), $selected stays null
           and the "Select a conversation" placeholder renders.
        ========================================================= */
        $isExplicitThread = $request->query->has('c');
        $selectedId = $isExplicitThread
            ? (int) $request->query->get('c')
            : ($conversations[0]['id'] ?? 0);

        $selected = null;
        $thread   = [];

        if ($selectedId) {
            foreach ($conversations as $c) {
                if ($c['id'] === $selectedId) {
                    $selected = $c;
                    break;
                }
            }
            if ($selected) {
                try {
                    $thread = $this->db->fetchAllAssociative(
                        "SELECT body, sender_id, m.$msgTime AS msg_time
                           FROM messages m
                          WHERE m.conversation_id = :c
                          ORDER BY m.$msgTime ASC",
                        ['c' => $selectedId]
                    );
                } catch (\Throwable $e) {
                    $thread = [];
                }

                /* ---- Mark messages from the other party as read ---- */
                $this->markThreadRead($selectedId, $me, $hasIsRead, $hasReadAt);
            }
        }

        /* =========================================================
           S4 — bell badge = unread across BOTH roles.
           This is the navbar bell count for THIS page only — the
           sidebar badge uses the NOTIFICATIONS unread count
           (sidebarNotifCount) like every other host page.
        ========================================================= */
        $notificationCount = $this->computeBellBadge($me, $unreadSql);

        /* =========================================================
           SESSION FLASH (rh_flash) — consumed once, then cleared.
        ========================================================= */
        $sessionFlash = null;
        $sf = $session->get('rh_flash');
        if (is_array($sf) && !empty($sf['message'])) {
            $sessionFlash = [
                'type'    => (string) ($sf['type'] ?? 'success'),
                'message' => (string) $sf['message'],
            ];
            $session->remove('rh_flash');
        }

        /* =========================================================
           THREAD ROWS — normalise per-message fields for the
           template (legacy did this inline: strtotime, day key,
           is_mine, date 'g:i A').
        ========================================================= */
        $threadRows = array_map(function (array $m) use ($me): array {
            $ts = !empty($m['msg_time']) ? strtotime((string) $m['msg_time']) : time();
            return [
                'body'      => (string) $m['body'],
                'is_mine'   => (int) $m['sender_id'] === $me,
                'day_key'   => date('Y-m-d', $ts),
                'day_label' => $this->dayLabel($ts),
                'time_label' => date('g:i A', $ts),
            ];
        }, $thread);

        return $this->render('host/messages.html.twig', [
            /* Host shell contract (host/base.html.twig + navbar) */
            'host'                  => $host,
            'notification_count'    => $notificationCount,
            'pending_tenants_count' => $pendingTenantsCount,
            'is_host'               => true,

            /* Page data */
            'conversations'       => $conversations,
            'selected'            => $selected,
            'thread'              => $threadRows,
            'is_explicit_thread'  => $isExplicitThread,
            'hp_csrf'             => $csrfToken,
            'session_flash'       => $sessionFlash,
        ]);
    }

    /* =====================================================================
     *  SUPPORT ACCOUNT DETECTOR — equivalent of legacy
     *  hp_support_user_id(). Looks up the system RoomHive Admin
     *  account by email support@roomhive.local. Returns 0 if the
     *  row doesn't exist (support chats simply won't appear).
     * ================================================================= */

    private function findSupportUserId(): int
    {
        try {
            return (int) $this->db->fetchOne(
                'SELECT id FROM users WHERE email = :e LIMIT 1',
                ['e' => 'support@roomhive.local']
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* =====================================================================
     *  NOTIFY — equivalent of legacy hm_notify(). Best-effort: writes
     *  a row to notifications (mb_substr 240). Silently no-ops on DB
     *  error (never breaks the send flow). The legacy version also
     *  tried includes/notify.php's roomhive_notify() first; the
     *  Symfony port goes straight to the DB (the helper file hasn't
     *  been ported and the INSERT is the documented fallback).
     * ================================================================= */

    private function notify(int $userId, string $message, string $link): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $this->db->insert('notifications', [
                'user_id'    => $userId,
                'message'    => mb_substr($message, 0, 240),
                'link'       => $link,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('hostmessages notify failed: ' . $e->getMessage());
            return false;
        }
    }

    /* =====================================================================
     *  MARK THREAD READ — marks messages from the other party as
     *  read. Uses is_read = 1 if the column exists, else
     *  read_at = NOW(). No-op if neither column exists.
     * ================================================================= */

    private function markThreadRead(int $convId, int $me, bool $hasIsRead, bool $hasReadAt): void
    {
        try {
            if ($hasIsRead) {
                $this->db->executeStatement(
                    'UPDATE messages SET is_read = 1
                     WHERE conversation_id = :c AND sender_id != :me AND is_read = 0',
                    ['c' => $convId, 'me' => $me]
                );
            } elseif ($hasReadAt) {
                $this->db->executeStatement(
                    'UPDATE messages SET read_at = NOW()
                     WHERE conversation_id = :c AND sender_id != :me AND read_at IS NULL',
                    ['c' => $convId, 'me' => $me]
                );
            }
        } catch (\Throwable $e) {
            /* Best-effort — never break the render */
        }
    }

    /* =====================================================================
     *  S4 — bell badge = unread across BOTH roles. Counts messages
     *  across every conversation where I'm either the host_id or
     *  the user_id, sent by anyone but me, that are still unread
     *  under the same $unreadSql predicate as the list query.
     * ================================================================= */

    private function computeBellBadge(int $me, string $unreadSql): int
    {
        try {
            return (int) $this->db->fetchOne(
                "SELECT COUNT(*)
                   FROM messages m
                   JOIN conversations c ON c.id = m.conversation_id
                  WHERE (c.host_id = :h1 OR c.user_id = :h2)
                    AND m.sender_id != :h3
                    AND $unreadSql",
                ['h1' => $me, 'h2' => $me, 'h3' => $me]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* =====================================================================
     *  DAY LABEL — equivalent of legacy hp_message_day_label().
     *  'Today' / 'Yesterday' / 'F j, Y'.
     * ================================================================= */

    private function dayLabel(int $timestamp): string
    {
        $day = date('Y-m-d', $timestamp);
        if ($day === date('Y-m-d')) {
            return 'Today';
        }
        if ($day === date('Y-m-d', strtotime('-1 day'))) {
            return 'Yesterday';
        }
        return date('F j, Y', $timestamp);
    }

    /* =====================================================================
     *  BADGE COUNTS — same implementations as HostBookingsController.
     *  (Sidebar notification_count here is NOTIFICATIONS unread, same
     *  as every other host page; the page-specific bell badge is
     *  computeBellBadge() above.)
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
     *  PHOTO PATH RESOLVER — same as HostListingsController /
     *  HostProfileController / HostBookingsController /
     *  HostPayoutsController / HostReviewsController /
     *  AdminController / AccountController.
     * ================================================================= */

    private function resolvePhoto(string $path, string $fallback = '/images/ListingPlaceholder.png'): string
    {
        $path = trim($path);
        if ($path === '') {
            return $fallback;
        }
        if (preg_match('#^(https?://|/)#i', $path)) {
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
