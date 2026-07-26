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
 * Not wired into index.php/routing — deploy standalone if the maintainer is
 * willing to host it, or run it on any other PHP host, since it doesn't
 * depend on anything else in this repo.
 *
 * Setup:
 *   1. Copy this file to apps/ebay/deletion-notification.php (git-ignored,
 *      same pattern as apps/ebay/config.php) and fill in VERIFICATION_TOKEN
 *      and ENDPOINT_URL below.
 *   2. In the eBay dev portal (Alerts & Notifications tab for the keyset),
 *      set:
 *        - Marketplace account deletion notification endpoint: ENDPOINT_URL
 *        - Verification token: the same string as VERIFICATION_TOKEN
 *   3. Click "Send Test Notification" — eBay GETs this URL with a
 *      challenge_code param; a correct response is what clears the
 *      "Non Compliant" warning.
 *
 * Spec: https://developer.ebay.com/marketplace-account-deletion
 */

const VERIFICATION_TOKEN = 'YOUR_VERIFICATION_TOKEN';

// Must exactly match the endpoint URL entered in the eBay dev portal — the
// challenge hash below is computed over this literal string.
const ENDPOINT_URL = 'https://oauth.wosa.link/apps/ebay/deletion-notification.php';

$challengeCode = isset($_GET['challenge_code']) ? $_GET['challenge_code'] : null;

if ($challengeCode !== null) {
    // Verification handshake (GET) — eBay checks this on save and whenever
    // "Send Test Notification" is clicked.
    $hash = hash('sha256', $challengeCode . VERIFICATION_TOKEN . ENDPOINT_URL);
    header('Content-Type: application/json');
    echo json_encode(array('challengeResponse' => $hash));
    exit;
}

// Real deletion notification (POST). Nothing to delete server-side, but log
// it for the record and ack fast so eBay doesn't retry.
$body = file_get_contents('php://input');
error_log('eBay account-deletion notification: ' . $body);
http_response_code(200);
