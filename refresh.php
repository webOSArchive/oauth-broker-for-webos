<?php
/**
 * refresh.php  (device → broker)
 *
 * OAuth2 access tokens expire; refreshing them requires the client secret,
 * which lives only here. The device sends its refresh_token and gets a fresh
 * token set back. (Not used by oauth1_xauth apps — those tokens don't expire.)
 *
 *   POST /refresh.php?app=box   body: refresh_token=…
 *   GET  /refresh.php?app=box&refresh_token=…      (also accepted)
 *   → {status:"ready", access_token:"…", refresh_token:"…", expires_in:3600, …}
 *   → {status:"invalid_grant"}   refresh token rejected — a real logout
 *   → {error:"…"}                transient/other failure — keep existing tokens
 */
require __DIR__ . '/common.php';

$app = resolveAppName();
$cfg = loadApp($app);

if ($cfg['flow'] !== 'oauth2_authcode') {
    jsonError('This app does not use refreshable tokens.', 400);
}

$refreshToken = '';
if (isset($_POST['refresh_token'])) {
    $refreshToken = trim($_POST['refresh_token']);
} elseif (isset($_GET['refresh_token'])) {
    $refreshToken = trim($_GET['refresh_token']);
}
if ($refreshToken === '') {
    jsonError('No refresh_token supplied.');
}

$oauth  = new OAuth2($cfg);
$tokens = $oauth->refresh($refreshToken);

if (!is_array($tokens)) {
    // Network/transport failure — inconclusive. Tell the device NOT to log out.
    jsonError('Temporary failure refreshing token. Try again later.', 503);
}

$http = isset($tokens['_http']) ? $tokens['_http'] : 0;
unset($tokens['_http']);

if (!empty($tokens['access_token'])) {
    $tokens['status'] = 'ready';
    jsonOut($tokens);
}

// Provider explicitly rejected the refresh token (expired/revoked) — a genuine
// logout, distinct from a transient error, so the device can clear its tokens.
$err = isset($tokens['error']) ? $tokens['error'] : '';
if ($http === 400 || $http === 401 || $err === 'invalid_grant') {
    jsonOut(array('status' => 'invalid_grant'));
}

jsonError('Could not refresh token.', 502);
