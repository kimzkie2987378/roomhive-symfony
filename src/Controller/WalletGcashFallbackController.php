<?php

/*
 * RoomHive - safety net for the legacy GCash checkout URL.
 *
 * History: the old flat-PHP flow was wallet.php -> wallet-gcash.php
 * (?ref=WH-...). The Symfony port of WalletController still answers
 * the top-up POST with a redirect to /wallet/gcash?ref=..., but that
 * checkout page was never ported, so the redirect landed on a 404.
 *
 * The wallet page now settles top-ups directly on /wallet (the
 * overlay posts in the background and never navigates away), so this
 * route only exists to defuse whatever still points at the legacy
 * URL: old bookmarks, the currently-open 404 tab, JS-off submits.
 * It simply bounces back to the wallet instead of a 404.
 *
 * Once WalletController.php is updated to credit the wallet directly
 * (read method + gcash_number / card_* fields, no redirect), this
 * file can be deleted.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WalletGcashFallbackController extends AbstractController
{
    #[Route('/wallet/gcash', name: 'app_wallet_gcash_fallback', methods: ['GET', 'POST'])]
    public function __invoke(): Response
    {
        // Legacy GCash checkout page is retired - the wallet overlay
        // settles everything on /wallet now. Never show a 404 here.
        return $this->redirectToRoute('app_wallet');
    }
}
