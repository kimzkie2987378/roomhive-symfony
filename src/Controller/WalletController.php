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
 * RoomHive — My Wallet (1:1 port of legacy user/wallet.php).
 *
 *   GET|POST /wallet   → balance card · GCash top-up · transaction history
 *
 * Legacy behaviour kept intact:
 *   - Balance lives on users.wallet_balance (the same column Hive Club
 *     rewards already credit); every money movement is logged in
 *     wallet_transactions with balance_after for a full audit trail.
 *   - SELF-HEAL: creates wallet_transactions and users.wallet_balance on
 *     first run, exactly like the legacy page did. PaymentController's
 *     wallet-spend ledger writes into this same table.
 *   - TOP-UP (INSTANT APPROVAL — the overlay checkout is simulated, so
 *     there is no real GCash step to wait for any more): the POST credits
 *     users.wallet_balance right away and writes the wallet_transactions
 *     row as payment_status='paid' with the real balance_after, then
 *     redirects back to /wallet?topup=success. No PENDING rows, no
 *     /wallet/gcash checkout in between.
 *   - APPROVE-ON-SIGHT SELF-HEAL: top-up rows left PENDING by the old
 *     GCash-checkout build are flipped to 'paid' on page load — the
 *     balance is credited once and balance_after is backfilled
 *     newest-first, so legacy PENDING rows become APPROVED without any
 *     manual SQL.
 *   - Limits: ₱50 min, ₱50,000 max per transaction.
 *
 * Port notes:
 *   - session_start() is Symfony's job now; the guard is the same
 *     logged_in / user_id pattern as PaymentController.
 *   - sync_user_session() is folded into the shared navbar partial,
 *     which takes nav_avatar directly.
 *   - csrf_verify()/csrf_field() become the per-key session token pair
 *     (wallet_topup) already used by the payment flow.
 */
class WalletController extends AbstractController
{
    private const TOPUP_MIN  = 50.00;
    private const TOPUP_MAX  = 50000.00;
    private const CSRF_TOPUP = 'wallet_topup';

    public function __construct(private readonly Connection $db) {}

    #[Route('/wallet', name: 'app_wallet', methods: ['GET', 'POST'], priority: 2)]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        if ($session->get('admin_role') === 'staff') {
            return $this->redirect('/staff');
        }

        $userId  = (int)$session->get('user_id');
        if (!$session->get('logged_in') || $userId <= 0) {
            return $this->redirectToRoute('app_login');
        }

        /* ---- user row (legacy select: id, name, email, phone,
           avatar_path, is_host, wallet_balance, created_at) ---- */
        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, phone, avatar_path, is_host, wallet_balance, created_at
               FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if (!$user) {
            /* legacy: session_destroy() + back to the login form */
            $session->clear();
            return $this->redirectToRoute('app_login');
        }
        $user['id'] = (int)$user['id'];

        if ((int)$user['is_host'] === 1) {
            return new RedirectResponse('/host/profile');
        }

        $walletBalance = round((float)($user['wallet_balance'] ?? 0), 2);

        /* =========================================================
           SELF-HEAL — wallet ledger table + wallet_balance column
        ========================================================== */
        try {
            $this->db->executeStatement("
                CREATE TABLE IF NOT EXISTS wallet_transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    reference VARCHAR(40) NOT NULL UNIQUE,
                    type ENUM('topup','spend','refund','reward') NOT NULL,
                    amount DECIMAL(12,2) NOT NULL,
                    balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
                    description VARCHAR(240) NOT NULL DEFAULT '',
                    payment_method VARCHAR(30) DEFAULT NULL,
                    payment_status ENUM('pending','paid','failed') NOT NULL DEFAULT 'paid',
                    gcash_reference VARCHAR(60) DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_wt_user (user_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            error_log('wallet: table self-heal failed: ' . $e->getMessage());
        }

        try {
            $colExists = $this->db->fetchOne("SHOW COLUMNS FROM users LIKE 'wallet_balance'");
            if ($colExists === false) {
                $this->db->executeStatement(
                    'ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0'
                );
            }
        } catch (\Throwable $e) {
            error_log('wallet: column self-heal failed: ' . $e->getMessage());
        }

        /* =========================================================
           APPROVE-ON-SIGHT SELF-HEAL — the simulated checkout settles
           instantly, so nothing should sit in PENDING any more. Flip
           old pending TOP-UP rows to paid, credit their sum into
           users.wallet_balance exactly once and backfill balance_after
           newest-first (the newest row lands on the final balance).
        ========================================================== */
        try {
            $stalePending = $this->db->fetchAllAssociative(
                "SELECT id, amount FROM wallet_transactions
                  WHERE user_id = :u AND type = 'topup' AND payment_status = 'pending'
                  ORDER BY id ASC",
                ['u' => $userId]
            );
            if ($stalePending) {
                $pendingSum = 0.0;
                foreach ($stalePending as $p) {
                    $pendingSum += (float)$p['amount'];
                }
                $pendingSum    = round($pendingSum, 2);
                $healedBalance = round($walletBalance + $pendingSum, 2);

                $running = $healedBalance;
                foreach (array_reverse($stalePending) as $p) {
                    $this->db->update('wallet_transactions', [
                        'payment_status' => 'paid',
                        'balance_after'  => $running,
                    ], ['id' => (int)$p['id']]);
                    $running = round($running - (float)$p['amount'], 2);
                }

                $this->db->executeStatement(
                    'UPDATE users SET wallet_balance = :b WHERE id = :u',
                    ['b' => $healedBalance, 'u' => $userId]
                );
                $walletBalance = $healedBalance;
            }
        } catch (\Throwable $e) {
            error_log('wallet: approve-on-sight heal failed: ' . $e->getMessage());
        }

        /* ---- unread bell count ---- */
        $notifCount = $this->notifCount($userId);

        /* =========================================================
           TOP-UP — INSTANT APPROVAL (simulated checkout)
           The payment overlay already collected the GCash number or
           the card details in-site, so confirming IS the payment:
           credit users.wallet_balance, write the ledger row PAID with
           the real balance_after, then return to /wallet?topup=success.
           No PENDING state, no /wallet/gcash checkout redirect.
        ========================================================== */
        $topupError = '';
        $topupFlag  = (string)$request->query->get('topup', '');

        if ($request->isMethod('POST') && $request->request->get('action') === 'topup') {

            if (!$this->csrfValid($session, self::CSRF_TOPUP, $this->postedToken($request))) {
                $topupError = 'Your session expired. Please try again.';
            } else {
                $amount = round((float)str_replace(',', '', (string)$request->request->get('amount', '0')), 2);

                if ($amount < self::TOPUP_MIN) {
                    $topupError = 'Minimum top-up is ₱50.00.';
                } elseif ($amount > self::TOPUP_MAX) {
                    $topupError = 'Maximum top-up is ₱50,000.00 per transaction.';
                } else {
                    /* method + payment detail — collected by the overlay
                       (gcash_number / card_*). Only masked traces are ever
                       stored: the full card number and CVV are NOT kept. */
                    $method = $request->request->get('method') === 'card' ? 'card' : 'gcash';

                    if ($method === 'card') {
                        $digits = preg_replace('/\D/', '', (string)$request->request->get('card_number', ''));
                        $last4  = substr($digits, -4);
                        $description    = 'Wallet top-up via Banking Card (**** ' . $last4 . ')';
                        $paymentMethod  = 'card';
                        $gcashReference = null;
                    } else {
                        $number = preg_replace('/\D/', '', (string)$request->request->get('gcash_number', ''));
                        if (strlen($number) === 12 && strpos($number, '639') === 0) {
                            $number = '0' . substr($number, 3);
                        }
                        $description    = 'Wallet top-up via GCash';
                        $paymentMethod  = 'gcash';
                        $gcashReference = $number !== ''
                            ? substr($number, 0, 4) . '***' . substr($number, -4)
                            : null;
                    }

                    try {
                        /* 1) credit the wallet balance ... */
                        $this->db->executeStatement(
                            'UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :u',
                            ['amt' => $amount, 'u' => $userId]
                        );

                        /* 2) ... read the fresh balance for the audit trail ... */
                        $newBalance = round(
                            (float)$this->db->fetchOne(
                                'SELECT wallet_balance FROM users WHERE id = :u',
                                ['u' => $userId]
                            ),
                            2
                        );

                        /* 3) ... and log the movement as PAID (approved). */
                        $reference = 'WH' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

                        $this->db->insert('wallet_transactions', [
                            'user_id'         => $userId,
                            'reference'       => $reference,
                            'type'            => 'topup',
                            'amount'          => $amount,
                            'balance_after'   => $newBalance,
                            'description'     => $description,
                            'payment_method'  => $paymentMethod,
                            'payment_status'  => 'paid',
                            'gcash_reference' => $gcashReference,
                            'created_at'      => date('Y-m-d H:i:s'),
                        ]);

                        return $this->redirectToRoute('app_wallet', ['topup' => 'success']);
                    } catch (\Throwable $e) {
                        error_log('wallet topup failed: ' . $e->getMessage());
                        $topupError = 'Could not complete the top-up. Please try again.';
                    }
                }
            }
        }

        /* =========================================================
           WALLET HISTORY — last 50 movements
        ========================================================== */
        $txns = [];
        try {
            $txns = $this->db->fetchAllAssociative(
                'SELECT id, reference, type, amount, balance_after, description,
                        payment_method, payment_status, gcash_reference, created_at
                   FROM wallet_transactions
                  WHERE user_id = :u
                  ORDER BY created_at DESC, id DESC
                  LIMIT 50',
                ['u' => $userId]
            );
        } catch (\Throwable $e) {
            $txns = [];
        }

        /* ---- stats (paid movements only — legacy rules) ---- */
        $totalTopups  = 0.0;
        $totalSpent   = 0.0;
        $totalRefunds = 0.0;
        $totalRewards = 0.0;

        foreach ($txns as $t) {
            if ((string)$t['payment_status'] !== 'paid') {
                continue;
            }
            $amt = (float)$t['amount'];
            if ($t['type'] === 'topup') {
                $totalTopups += $amt;
            } elseif ($t['type'] === 'spend') {
                $totalSpent += $amt;
            } elseif ($t['type'] === 'refund') {
                $totalRefunds += $amt;
            } elseif ($t['type'] === 'reward') {
                $totalRewards += $amt;
            }
        }

        /* ---- human label + lucide icon per row
           (legacy render-loop maps, applied server-side) ---- */
        $typeLabels = [
            'topup'  => ['Wallet Top-Up',    'wt-type-topup'],
            'spend'  => ['Booking Payment',  'wt-type-spend'],
            'refund' => ['Refund',           'wt-type-refund'],
            'reward' => ['Hive Club Reward', 'wt-type-reward'],
        ];
        $typeIcons = [
            'topup'  => 'arrow-down-to-line',
            'spend'  => 'shopping-basket',
            'refund' => 'rotate-ccw',
            'reward' => 'gift',
        ];

        $rows = [];
        foreach ($txns as $t) {
            $type = (string)$t['type'];
            [$label, $class] = $typeLabels[$type] ?? [ucfirst($type), ''];
            $rows[] = $t + [
                'label'      => $label,
                'type_class' => $class,
                'icon'       => $typeIcons[$type] ?? 'wallet',
                'is_in'      => in_array($type, ['topup', 'refund', 'reward'], true),
            ];
        }

        return $this->render('user/wallet.html.twig', [
            'user'          => $user,
            'wallet'        => $walletBalance,
            'total_topups'  => round($totalTopups, 2),
            'total_spent'   => round($totalSpent, 2),
            'total_refunds' => round($totalRefunds, 2),
            'total_rewards' => round($totalRewards, 2),
            'txns'          => $rows,
            'notif_count'   => $notifCount,
            'csrf_wallet'   => $this->ensureCsrfToken($session, self::CSRF_TOPUP),
            'topup_flag'    => $topupFlag,
            'topup_error'   => $topupError,
        ]);
    }

    /* =====================================================================
     |  HELPERS — same implementations as PaymentController (kept identical
     |  so both controllers behave exactly alike)
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
