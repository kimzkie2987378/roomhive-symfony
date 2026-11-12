<?php

namespace App\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin → Wallet (/admin/wallet).
 *
 * Mirrors host/payouts for the admin side: stat pills driven by admin
 * COMMISSION (Available / Pending / Paid Out), a Request-a-Payout card
 * with the same per-method destination fields as the host page, instant
 * processing (PRG + CSRF), the green receipt, a payout history table,
 * and a compact commission ledger underneath so the page stays
 * recognisably "the wallet".
 *
 * Porting notes:
 *  - Guard: session-based via assertAdmin() — this project does NOT
 *    register SecurityBundle (denyAccessUnlessGranted()/isGranted()
 *    throw "The SecurityBundle is not registered…").
 *  - admin_name/admin_email: resolveAdminIdentity() pulls them from the
 *    session and the render passes them per-render (the shell renders
 *    them in the topbar chip).
 *  - Commission source = wallet_transactions rows of type='commission'
 *    (signed: amount > 0). If your commission lives on a bookings
 *    column instead, edit the one marked "COMMISSION SOURCE" query in
 *    computeStats().
 *  - admin_payouts is auto-created/repaired by ensurePayoutsTable()
 *    (CREATE TABLE IF NOT EXISTS + per-column repair) — no phpMyAdmin
 *    step. Payouts are processed INSTANTLY: status='completed',
 *    processed_at set, the amount is gone from Available on redirect.
 *  - Card / account / mobile numbers are masked to the last 4 in the
 *    stored destination; the full number is never persisted.
 *  - DBAL 3.x+ API (executeStatement / fetchOne / fetchAssociative),
 *    consistent with the Symfony 6.1+/PHP 8.1+ attribute era.
 */
final class AdminWalletController extends AbstractController
{
    /** Where unauthenticated admins are sent (adjust if your login route differs). */
    private const ADMIN_LOGIN_PATH = '/admin/login';

    /** Fixed payout minimum (₱). No maximum — the server caps at Available. */
    private const PAYOUT_MIN = 100.0;

    private const PAYOUT_METHODS = ['GCash', 'PayMaya', 'Bank Transfer', 'Debit / Credit Card'];

    /** Columns admin_payouts must have (CREATE + the repair pass both use this). */
    private const PAYOUT_COLUMNS = [
        'id'           => 'INT AUTO_INCREMENT PRIMARY KEY',
        'reference'    => "VARCHAR(32) NOT NULL",
        'amount'       => "DECIMAL(12,2) NOT NULL",
        'method'       => "VARCHAR(40) NOT NULL",
        'destination'  => "VARCHAR(255) NOT NULL DEFAULT ''",
        'status'       => "VARCHAR(16) NOT NULL DEFAULT 'completed'",
        'requested_at' => "DATETIME NOT NULL",
        'processed_at' => "DATETIME NULL",
        'admin_id'     => "INT NULL",
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    #[Route('/admin/wallet', name: 'admin_wallet', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        // ── Guard: session-based (see assertAdmin). NO SecurityBundle calls
        //    here — the bundle is not registered in this application.
        if ($redirect = $this->assertAdmin($request)) {
            return $redirect;
        }

        $session = $request->getSession();

        if ($request->isMethod('POST')) {
            return $this->handlePayoutPost($request, $session);
        }

        return $this->renderWallet($session);
    }

    // ── GET render ────────────────────────────────────────────────────────
    private function renderWallet(SessionInterface $session): Response
    {
        [$admin_name, $admin_email] = $this->resolveAdminIdentity($session);

        $table_ready = $this->ensurePayoutsTable();

        [$commission_earned, $paid_out, $pending_out] = $this->computeStats();
        $available = max(0.0, $commission_earned - $paid_out - $pending_out);

        $payouts           = $table_ready ? $this->fetchHistory() : [];
        $commission_ledger = $this->fetchCommissionLedger();

        // Normalise history rows for the template (status pill class + label).
        foreach ($payouts as &$p) {
            $status = (string) ($p['status'] ?? 'completed');
            [$p['status_class'], $p['status_label']] = $this->statusPill($status);
            $p['amount'] = (float) $p['amount'];
        }
        unset($p);

        // One-render receipt + flash (consumed here, then cleared).
        $receipt = $session->get('admin_payout_receipt');
        $session->remove('admin_payout_receipt');
        $flash = $session->get('admin_payout_flash');
        $session->remove('admin_payout_flash');

        // CSRF token for the request form.
        $csrf = $session->get('admin_pay_csrf');
        if (!is_string($csrf) || $csrf === '') {
            $csrf = bin2hex(random_bytes(16));
            $session->set('admin_pay_csrf', $csrf);
        }

        return $this->render('admin/wallet.html.twig', [
            'admin_name'          => $admin_name,
            'admin_email'         => $admin_email,
            'available'           => $available,
            'pending_out'         => $pending_out,
            'paid_out'            => $paid_out,
            'commission_earned'   => $commission_earned,
            'min_amount'          => self::PAYOUT_MIN,
            'pay_csrf'            => $csrf,
            'payouts'             => $payouts,
            'payout_table_exists' => $table_ready,
            'session_flash'       => $flash,
            'payout_receipt'      => $receipt,
            'commission_ledger'   => $commission_ledger,
        ]);
    }

    // ── POST: validate + process the payout, then PRG back to GET ────────
    private function handlePayoutPost(Request $request, SessionInterface $session): RedirectResponse
    {
        // CSRF check (same flow as host/payouts' pay_csrf).
        $csrf  = (string) $request->request->get('pay_csrf', '');
        $known = (string) $session->get('admin_pay_csrf', '');
        if ($csrf === '' || $known === '' || !hash_equals($known, $csrf)) {
            $this->flash($session, 'error', 'Session expired — please retry the payout request.');
            return $this->redirectToRoute('admin_wallet');
        }

        if ((string) $request->request->get('request_payout', '') === '') {
            return $this->redirectToRoute('admin_wallet');
        }

        $table_ready = $this->ensurePayoutsTable();

        [$commission_earned, $paid_out, $pending_out] = $this->computeStats();
        $available = max(0.0, $commission_earned - $paid_out - $pending_out);

        // ── Amount ──
        $raw_amount = (string) $request->request->get('amount', '');
        $amount = is_numeric($raw_amount) ? round((float) $raw_amount, 2) : 0.0;

        if ($amount < self::PAYOUT_MIN) {
            $this->flash($session, 'error', sprintf('Minimum payout is ₱%.2f.', self::PAYOUT_MIN));
            return $this->redirectToRoute('admin_wallet');
        }
        if ($amount > $available + 0.0001) {
            $this->flash($session, 'error', sprintf('You can only withdraw up to ₱%.2f (your available commission).', $available));
            return $this->redirectToRoute('admin_wallet');
        }

        // ── Method + destination ──
        $method = (string) $request->request->get('method', '');
        if (!in_array($method, self::PAYOUT_METHODS, true)) {
            $this->flash($session, 'error', 'Choose a valid payout method.');
            return $this->redirectToRoute('admin_wallet');
        }

        [$dest_ok, $destination, $dest_err] = $this->validateDestination($method, $request);
        if (!$dest_ok) {
            $this->flash($session, 'error', $dest_err);
            return $this->redirectToRoute('admin_wallet');
        }

        if (!$table_ready) {
            $this->flash($session, 'error', 'The payouts table could not be created — check the database credentials and retry.');
            return $this->redirectToRoute('admin_wallet');
        }

        // ── Insert (instant completion) ──
        $now      = new DateTimeImmutable();
        $admin_id = $this->resolveAdminId($session);

        try {
            $this->connection->insert('admin_payouts', [
                'reference'    => 'AP-PENDING', // replaced with AP-<id> below
                'amount'       => $amount,
                'method'       => $method,
                'destination'  => $destination,
                'status'       => 'completed',
                'requested_at' => $now->format('Y-m-d H:i:s'),
                'processed_at' => $now->format('Y-m-d H:i:s'),
                'admin_id'     => $admin_id,
            ]);
            $id = (int) $this->connection->lastInsertId();
        } catch (\Throwable $e) {
            $this->flash($session, 'error', 'Could not record the payout — please try again.');
            return $this->redirectToRoute('admin_wallet');
        }

        $reference = 'AP-' . str_pad((string) ($id ?: 0), 6, '0', STR_PAD_LEFT);
        try {
            $this->connection->update('admin_payouts', ['reference' => $reference], ['id' => $id]);
        } catch (\Throwable) {
            // best-effort — the row is recorded even if the ref update fails.
        }

        $new_available = max(0.0, $available - $amount);

        $session->set('admin_payout_receipt', [
            'reference'     => $reference,
            'amount'        => $amount,
            'method'        => $method,
            'destination'   => $destination,
            'date'          => $now->format('M j, Y'),
            'time'          => $now->format('H:i'),
            'new_available' => $new_available,
        ]);

        return $this->redirectToRoute('admin_wallet');
    }

    // ── Stats: commission earned + payout sums ───────────────────────────
    private function computeStats(): array
    {
        // Platform Revenue / Commission from bookings (confirmed and completed)
        $commission_earned = $this->safeFloat(
            "SELECT COALESCE(SUM(amount_paid), 0) FROM bookings WHERE status IN ('confirmed','completed')"
        );

        $paid_out = $this->safeFloat(
            "SELECT COALESCE(SUM(amount), 0) FROM admin_payouts WHERE status = 'completed'"
        );
        $pending_out = $this->safeFloat(
            "SELECT COALESCE(SUM(amount), 0) FROM admin_payouts WHERE status = 'pending'"
        );

        return [$commission_earned, $paid_out, $pending_out];
    }

    private function fetchHistory(): array
    {
        try {
            return $this->connection->fetchAllAssociative(
                "SELECT reference, amount, method, destination, status,
                        DATE_FORMAT(requested_at, '%b %e, %Y') AS requested
                 FROM admin_payouts
                 ORDER BY requested_at DESC, id DESC
                 LIMIT 25"
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function fetchCommissionLedger(): array
    {
        try {
            return $this->connection->fetchAllAssociative(
                "SELECT b.id, b.amount_paid AS amount,
                        CONCAT('Booking #', b.id, ' — ', COALESCE(l.title, 'Listing Revenue')) AS description,
                        DATE_FORMAT(COALESCE(b.paid_at, b.created_at), '%b %e, %Y %H:%i') AS `when`
                 FROM bookings b
                 LEFT JOIN listings l ON l.id = b.listing_id
                 WHERE b.status IN ('confirmed', 'completed')
                 ORDER BY b.id DESC
                 LIMIT 15"
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Destination validation + masking (display-safe) ──────────────────
    private function validateDestination(string $method, Request $request): array
    {
        $post = $request->request;

        if ($method === 'GCash' || $method === 'PayMaya') {
            $field = $method === 'GCash' ? 'gcash_number' : 'paymaya_number';
            $num   = preg_replace('/\D+/', '', (string) $post->get($field, ''));
            if (!preg_match('/^09\d{9}$/', $num)) {
                return [false, '', 'Enter a valid 11-digit mobile number starting with 09.'];
            }
            return [true, $method . ' ••••' . substr($num, -4), ''];
        }

        if ($method === 'Bank Transfer') {
            $bank = trim((string) $post->get('bank_name', ''));
            $name = trim((string) $post->get('bank_account_name', ''));
            $acct = preg_replace('/\D+/', '', (string) $post->get('bank_account_number', ''));
            if ($bank === '' || $name === '') {
                return [false, '', 'Bank name and account name are required.'];
            }
            if (strlen($acct) < 6) {
                return [false, '', 'Enter a valid bank account number.'];
            }
            return [true, $bank . ' — ' . mb_substr($name, 0, 24) . ' ••••' . substr($acct, -4), ''];
        }

        if ($method === 'Debit / Credit Card') {
            $card = preg_replace('/\D+/', '', (string) $post->get('card_number', ''));
            $exp  = trim((string) $post->get('card_expiry', ''));
            $name = trim((string) $post->get('card_name', ''));
            if (strlen($card) < 13 || strlen($card) > 19) {
                return [false, '', 'Enter a valid card number (13–19 digits).'];
            }
            if (!preg_match('#^\d{2}/\d{2}$#', $exp)) {
                return [false, '', 'Enter the card expiry as MM/YY.'];
            }
            if ($name === '') {
                return [false, '', 'Enter the cardholder name.'];
            }
            return [true, 'Card ••••' . substr($card, -4), ''];
        }

        return [false, '', 'Unknown payout method.'];
    }

    private function statusPill(string $status): array
    {
        return match (true) {
            $status === 'completed'                       => ['hp-status-active', 'Completed'],
            $status === 'pending'                         => ['hp-status-pending', 'Pending'],
            $status === 'rejected', $status === 'failed'  => ['hp-status-rejected', ucfirst($status)],
            default                                       => ['hp-status-unlisted', ucfirst($status)],
        };
    }

    private function resolveAdminId(SessionInterface $session): ?int
    {
        foreach (['admin_id', 'admin_user_id'] as $key) {
            $v = $session->get($key);
            if (is_numeric($v)) {
                return (int) $v;
            }
        }
        $row = $session->get('admin');
        if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
            return (int) $row['id'];
        }
        return null;
    }

    // ── admin_payouts table: CREATE IF NOT EXISTS + column repair ────────
    private function ensurePayoutsTable(): bool
    {
        try {
            $cols = [];
            foreach (self::PAYOUT_COLUMNS as $name => $def) {
                $cols[] = "`{$name}` {$def}";
            }
            $this->connection->executeStatement(
                "CREATE TABLE IF NOT EXISTS `admin_payouts` (" . implode(', ', $cols) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable) {
            return false;
        }

        // Repair pass — add any missing columns (handles partial earlier creates).
        try {
            $existing = [];
            foreach ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM `admin_payouts`") as $c) {
                $existing[$c['Field']] = true;
            }
            foreach (self::PAYOUT_COLUMNS as $name => $def) {
                if ($name === 'id' || isset($existing[$name])) {
                    continue;
                }
                try {
                    $this->connection->executeStatement("ALTER TABLE `admin_payouts` ADD COLUMN `{$name}` {$def}");
                } catch (\Throwable) {
                    // best-effort repair
                }
            }
        } catch (\Throwable) {
            // SHOW COLUMNS unsupported (non-MySQL) — skip repair.
        }

        return true;
    }

    private function safeFloat(string $sql, array $params = []): float
    {
        try {
            $v = $this->connection->fetchOne($sql, $params);
            return is_numeric($v) ? (float) $v : 0.0;
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function flash(SessionInterface $session, string $type, string $message): void
    {
        $session->set('admin_payout_flash', ['type' => $type, 'message' => $message]);
    }

    // ── Guard + identity (session-based; no SecurityBundle) ──────────────
    private function assertAdmin(Request $request): ?RedirectResponse
    {
        $session = $request->getSession();

        // Staff members are not permitted to access the admin commission wallet
        if ($session->get('admin_role') === 'staff') {
            return new RedirectResponse('/staff');
        }

        // Legacy logins stashed the admin row in the session; accept the
        // common key shapes so the guard survives small naming differences.
        foreach (['admin_id', 'admin_user_id', 'admin_user', 'admin_name', 'admin'] as $key) {
            if ($session->get($key)) {
                return null;
            }
        }

        return new RedirectResponse(self::ADMIN_LOGIN_PATH);
    }

    private function resolveAdminIdentity(SessionInterface $session): array
    {
        // Whole-row stash: ['admin' => ['name' => …, 'email' => …]]
        $row = $session->get('admin');
        if (is_array($row)) {
            $name  = $row['name'] ?? $row['username'] ?? $row['email'] ?? null;
            $email = (string) ($row['email'] ?? '');
            if ($name !== null && (string) $name !== '') {
                return [(string) $name, $email];
            }
        }

        // Flat keys: ['admin_name' => …, 'admin_email' => …]
        $name = $session->get('admin_name') ?? $session->get('admin_user');
        if ($name !== null && (string) $name !== '') {
            return [(string) $name, (string) $session->get('admin_email', '')];
        }

        return ['Admin', ''];
    }
}
