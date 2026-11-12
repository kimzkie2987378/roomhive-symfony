<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RoomHive — Admin › Messages.
 * 1:1 port of legacy admin/adminmessages.php.
 *
 *   GET|POST /admin/messages   → 3 tabs:
 *
 *     TAB 1 "Contact Questions" — contact_messages submitted from
 *       the Contacts page. Two-pane: list left + detail panel right.
 *       Status update POST (ticket_id + status: open|in_progress|
 *       resolved|closed) — bare UPDATE, no CSRF token in legacy
 *       (kept that way for the 1:1 port; the legacy code uses
 *       no admin CSRF gate here). Replies happen off-page via
 *       mailto: or adminchat.php.
 *
 *     TAB 2 "Support Chats" — ongoing admin ↔ user chats. Lists
 *       conversations WHERE c.host_id = supportId (the auto-created
 *       RoomHive Admin system account, support@roomhive.local).
 *       Each row links to /admin/chat?c=<id> (the chat page is
 *       out of scope here — adminchat.php is a separate page that
 *       still needs to be ported when the user pastes it).
 *
 *     TAB 3 "Conversations" — original tenant ↔ host reader.
 *       Two-pane: list left + thread view right. Admin can read
 *       but not send (read-only oversight).
 *
 * Auth: same admin session keys as AdminController
 *   (admin_logged_in / admin_name / admin_email / admin_csrf).
 *   If admin_logged_in is missing → redirect to /admin/login.
 *
 * Support user auto-create: on first run, the system creates a
 *   `RoomHive Admin` user with email support@roomhive.local so
 *   chats from the admin side have a stable sender identity.
 *   (Mirrors adminchat.php's adm_support_user_id helper.)
 *
 * Schema auto-detect: messages table's time column is sent_at
 *   if present, else created_at. Unread flag uses is_read if
 *   present, else read_at IS NULL, else "1=0" (no unread detection).
 */
class AdminMessagesController extends AbstractController
{
    private const CSRF_KEY = 'admin_csrf';

    /** Statuses the admin can set on a contact_messages ticket. */
    private const VALID_TICKET_STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    /** Tab whitelist (query param `tab`). */
    private const VALID_TABS = ['inquiries', 'chats', 'conversations'];

    /** Status label + CSS class map for the inquiry badges. */
    private const TICKET_BADGE_MAP = [
        'open'        => ['Open',        'st-open'],
        'in_progress' => ['In Progress', 'st-progress'],
        'resolved'    => ['Resolved',    'st-resolved'],
        'closed'      => ['Closed',      'st-closed'],
    ];

    /** Email of the system RoomHive Admin / support user. */
    private const SUPPORT_EMAIL = 'support@roomhive.local';

    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/messages', name: 'app_admin_messages', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if (!$session->get('admin_logged_in')) {
            return $this->redirect('/admin/login');
        }

        $adminName  = (string) ($session->get('admin_name')  ?? 'Admin User');
        $adminEmail = (string) ($session->get('admin_email') ?? '');

        /* ---- CSRF token (per-session, available if a future CSRF
           gate is added to the ticket-status POST) ---- */
        $adminCsrf = (string) $session->get(self::CSRF_KEY);
        if ($adminCsrf === '') {
            $adminCsrf = bin2hex(random_bytes(32));
            $session->set(self::CSRF_KEY, $adminCsrf);
        }

        /* ---- System support user (RoomHive Admin) ---- */
        $supportId = $this->ensureSupportUser();

        /* ---- Schema auto-detect on `messages` table ---- */
        [$msgTime, $unreadSql] = $this->detectMessagesSchema();

        /* ---- Tab switcher (defaults to inquiries) ---- */
        $tab = (string) $request->query->get('tab', 'inquiries');
        if (!in_array($tab, self::VALID_TABS, true)) {
            $tab = 'inquiries';
        }

        /* =========================================================
           HANDLE POST: status update (inquiries tab only)
           Mirrors the legacy: bare UPDATE contact_messages, no CSRF.
        ========================================================= */
        if ($request->isMethod('POST') && $tab === 'inquiries') {
            $ticketId   = (int) $request->request->get('ticket_id', 0);
            $newStatus  = (string) $request->request->get('status', '');

            if ($ticketId > 0 && in_array($newStatus, self::VALID_TICKET_STATUSES, true)) {
                try {
                    $this->db->executeStatement(
                        'UPDATE contact_messages SET status = :s WHERE id = :id',
                        ['s' => $newStatus, 'id' => $ticketId]
                    );
                } catch (\Throwable $e) {
                    error_log('adminmessages: status update failed: ' . $e->getMessage());
                }
            }

            return $this->redirectToRoute('app_admin_messages', [
                'tab' => 'inquiries',
                'v'   => $ticketId,
            ]);
        }

        /* =========================================================
           TAB 1 — CONTACT QUESTIONS
           Left: list of inquiries + per-status counts.
           Right: selected inquiry's full detail + status buttons.
        ========================================================= */
        $inquiries      = [];
        $inqCounts      = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
        $selectedV       = (int) $request->query->get('v', 0);
        $selectedInquiry = null;

        try {
            $inquiries = $this->db->fetchAllAssociative(
                "SELECT cm.id, cm.first_name, cm.last_name, cm.email, cm.subject,
                        cm.message, cm.status, cm.created_at, cm.user_id,
                        u.name AS account_name
                 FROM contact_messages cm
                 LEFT JOIN users u ON u.id = cm.user_id
                 ORDER BY cm.created_at DESC
                 LIMIT 200"
            );

            foreach ($inquiries as &$row) {
                $s = (string) ($row['status'] ?? 'open');
                if (isset($inqCounts[$s])) {
                    $inqCounts[$s]++;
                }
                if ((int) $row['id'] === $selectedV) {
                    $selectedInquiry = $row;
                }
            }
            unset($row);
        } catch (\Throwable $e) {
            error_log('adminmessages: inquiries query failed: ' . $e->getMessage());
            $inquiries = [];
        }

        /* =========================================================
           TAB 2 — SUPPORT CHATS
           Conversations WHERE host_id = supportId (admin ↔ user).
        ========================================================= */
        $chats = [];
        if ($tab === 'chats' && $supportId > 0) {
            try {
                $chats = $this->db->fetchAllAssociative(
                    "SELECT c.id, c.user_id, u.name AS user_name, u.email,
                            c.last_message_at, c.created_at,
                            (SELECT m.body FROM messages m
                              WHERE m.conversation_id = c.id
                              ORDER BY m.$msgTime DESC LIMIT 1) AS last_body,
                            (SELECT COUNT(*) FROM messages m
                              WHERE m.conversation_id = c.id
                                AND m.sender_id != :sid
                                AND $unreadSql) AS unread_count
                     FROM conversations c
                     JOIN users u ON u.id = c.user_id
                     WHERE c.host_id = :sid2
                     ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
                     LIMIT 100",
                    ['sid' => $supportId, 'sid2' => $supportId]
                );
            } catch (\Throwable $e) {
                error_log('adminmessages: chats query failed: ' . $e->getMessage());
                $chats = [];
            }
        }

        /* =========================================================
           TAB 3 — CONVERSATIONS (tenant ↔ host reader, read-only)
        ========================================================= */
        $conversations = [];
        $thread        = [];
        $selected       = null;
        $selectedId    = (int) $request->query->get('c', 0);
        $threadHostId  = 0;

        if ($tab === 'conversations') {
            try {
                $conversations = $this->db->fetchAllAssociative(
                    "SELECT c.id, c.created_at, c.last_message_at, c.host_id,
                            t.name AS tenant_name, h.name AS host_name,
                            COALESCE(l.title, 'No listing attached') AS listing_title,
                            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS msg_count
                     FROM conversations c
                     JOIN users t ON t.id = c.user_id
                     JOIN users h ON h.id = c.host_id
                     LEFT JOIN listings l ON l.id = c.listing_id
                     ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
                     LIMIT 100"
                );

                foreach ($conversations as $c) {
                    if ((int) $c['id'] === $selectedId) {
                        $selected = $c;
                        $threadHostId = (int) $c['host_id'];
                        break;
                    }
                }

                if ($selected && $selectedId > 0) {
                    $thread = $this->db->fetchAllAssociative(
                        "SELECT m.body, m.sender_id, m.$msgTime AS msg_time, u.name AS sender_name
                         FROM messages m JOIN users u ON u.id = m.sender_id
                         WHERE m.conversation_id = :c
                         ORDER BY m.$msgTime ASC",
                        ['c' => $selectedId]
                    );
                }
            } catch (\Throwable $e) {
                error_log('adminmessages: conversations query failed: ' . $e->getMessage());
                $conversations = [];
            }
        }

        /* ---- Notification count for the bell badge ---- */
        $notificationCount = $this->countPending();

        return $this->render('admin/messages.html.twig', [
            'admin_name'         => $adminName,
            'admin_email'        => $adminEmail,
            'notification_count' => $notificationCount,
            'admin_csrf'         => $adminCsrf,

            'tab'                => $tab,
            'support_id'         => $supportId,

            'inquiries'          => $inquiries,
            'inq_counts'         => $inqCounts,
            'selected_inquiry'   => $selectedInquiry,

            'chats'              => $chats,

            'conversations'      => $conversations,
            'thread'             => $thread,
            'selected_convo'      => $selected,
            'selected_convo_id'   => $selectedId,
            'thread_host_id'     => $threadHostId,

            'ticket_badge_map'   => self::TICKET_BADGE_MAP,
        ]);
    }

    /* =====================================================================
     *  SUPPORT USER — RoomHive Admin / support@roomhive.local.
     *  Auto-created on first run so admin-side chats have a stable sender.
     * ================================================================= */

    private function ensureSupportUser(): int
    {
        try {
            $id = (int) $this->db->fetchOne(
                'SELECT id FROM users WHERE email = :e LIMIT 1',
                ['e' => self::SUPPORT_EMAIL]
            );
            if ($id > 0) {
                return $id;
            }

            $this->db->executeStatement(
                "INSERT INTO users (name, email, password, is_host, status)
                 VALUES (:n, :e, :p, 0, 'active')",
                [
                    'n' => 'RoomHive Admin',
                    'e' => self::SUPPORT_EMAIL,
                    'p' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                ]
            );
            return (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('adminmessages: could not create support user: ' . $e->getMessage());
            return 0;
        }
    }

    /* =====================================================================
     *  MESSAGES SCHEMA AUTO-DETECT
     *  Returns [timeColumn, unreadCondition].
     *  Mirrors the legacy SHOW COLUMNS probe on the messages table.
     * ================================================================= */

    private function detectMessagesSchema(): array
    {
        try {
            $cols = $this->db->fetchFirstColumn("SHOW COLUMNS FROM messages");
            $has  = static fn(string $c): bool => in_array($c, $cols, true);

            $msgTime   = $has('sent_at') ? 'sent_at' : 'created_at';
            $unreadSql = $has('is_read') ? 'm.is_read = 0'
                : ($has('read_at') ? 'm.read_at IS NULL' : '1 = 0');
            return [$msgTime, $unreadSql];
        } catch (\Throwable $e) {
            /* table missing or columns renamed — fall back to legacy defaults */
            return ['created_at', '1 = 0'];
        }
    }

    /* =====================================================================
     *  NOTIFICATION COUNT — same helper used by every admin page so the
     *  bell badge stays consistent. Pending host applications + pending
     *  listings.
     * ================================================================= */

    private function countPending(): int
    {
        try {
            $hostApps = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM host_applications WHERE status = 'pending'"
            );
            $listings = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM listings WHERE status = 'pending'"
            );
            return $hostApps + $listings;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
