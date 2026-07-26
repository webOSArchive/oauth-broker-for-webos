<?php
/**
 * eBay — Marketplace Account Deletion / Closure notification endpoint.
 *
 * Every eBay Production keyset must register an HTTPS endpoint eBay can call
 * if a user asks eBay to delete their account/data — this is what flips a
 * keyset from "Non Compliant" to compliant in eBay's dev portal. This is not
 * part of the broker's OAuth flow (get-code/callback/check-code/refresh) —
 * it's a standalone webhook eBay requires per-keyset, unrelated to any device
 * or token exchange. Since the broker never stores eBay user data
 * server-side (it only relays an OAuth token to the device), there's nothing
 * to actually delete here; this endpoint exists purely to satisfy eBay's
 * compliance check and log notifications for the record.
 *
 * Not wired into index.php/routing, and — unlike apps/ebay/config.php — this
 * file holds no secret itself, so it's safe to commit and deploy as-is; no
 * copy-to-a-gitignored-file step needed here. It reads the one thing that IS
 * a shared secret, `deletion_verification_token`, out of apps/ebay/config.php
 * at request time, same as client_id/client_secret already are.
 *
 * Setup:
 *   1. Add `deletion_verification_token` to apps/ebay/config.php (git-ignored;
 *      see config.example.php for the field).
 *   2. In the eBay dev portal (Alerts & Notifications tab for the keyset),
 *      set:
 *        - Marketplace account deletion notification endpoint: ENDPOINT_URL
 *          (below) — keep it in sync if this file ever moves.
 *        - Verification token: the same string as
 *          apps/ebay/config.php's deletion_verification_token.
 *   3. Click "Send Test Notification" — eBay GETs this URL with a
 *      challenge_code param; a correct response is what clears the
 *      "Non Compliant" warning.
 *
 * Spec: https://developer.ebay.com/develop/guides-v2/marketplace-user-account-deletion
 */

$cfg = require __DIR__ . '/config.php';

// Not a secret — must exactly match the endpoint URL entered in the eBay dev
// portal, since the challenge hash below is computed over this literal string.
const ENDPOINT_URL = 'https://oauth.wosa.link/apps/ebay/deletion-notification.php';

$challengeCode = isset($_GET['challenge_code']) ? $_GET['challenge_code'] : null;

if ($challengeCode !== null) {
    // Verification handshake (GET) — eBay checks this on save and whenever
    // "Send Test Notification" is clicked.
    $hash = hash('sha256', $challengeCode . $cfg['deletion_verification_token'] . ENDPOINT_URL);
    header('Content-Type: application/json');
    echo json_encode(array('challengeResponse' => $hash));
    exit;
}

// Real deletion notification (POST). Nothing to delete server-side, but log
// it for the record and ack fast so eBay doesn't retry.
$body = file_get_contents('php://input');
error_log('eBay account-deletion notification: ' . $body);
http_response_code(200);
