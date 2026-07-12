<?php
/**
 * callback.php  (OAuth2 provider → user's browser → here)
 *
 * The single redirect target registered with every OAuth2 provider. The app +
 * device code travel in the `state` parameter. We verify the CSRF nonce,
 * exchange the authorization code for tokens (server-side, using the client
 * secret), and park the tokens against the device code for pickup.
 *
 *   GET /callback.php?code=AUTH_CODE&state=BASE64URL
 */
require __DIR__ . '/common.php';

// Provider-side error (user declined, etc.)
if (isset($_GET['error'])) {
    renderPage('Sign-in cancelled',
        '<p class="err">' . htmlspecialchars($_GET['error']
            . (isset($_GET['error_description']) ? ': ' . $_GET['error_description'] : '')) . '</p>'
      . '<p>You can close this page and try again from your device.</p>', null);
    exit;
}

$authCode = isset($_GET['code'])  ? $_GET['code']  : '';
$stateRaw = isset($_GET['state']) ? $_GET['state'] : '';
$state    = json_decode(base64url_decode($stateRaw), true);

if ($authCode === '' || !is_array($state) || empty($state['app']) || empty($state['code'])) {
    renderPage('Something went wrong',
        '<p class="err">Missing or malformed authorization response.</p>', null);
    exit;
}

$app  = preg_replace('/[^a-z0-9_-]/i', '', $state['app']);
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $state['code']));
$cfg  = loadApp($app);

// CSRF: the nonce must match the one we stashed in this browser's session.
$expected = isset($_SESSION['broker_nonce'][$app . '_' . $code])
          ? $_SESSION['broker_nonce'][$app . '_' . $code] : null;
if (!$expected || empty($state['nonce']) || !hash_equals($expected, $state['nonce'])) {
    renderPage('Something went wrong',
        '<p class="err">Security check failed. Please start again from your device.</p>', $cfg);
    exit;
}
unset($_SESSION['broker_nonce'][$app . '_' . $code]);

$cache = new Cache($GLOBALS['CACHE_PATH'], $GLOBALS['CACHE_TTL']);
if (!$cache->exists($app, $code)) {
    renderPage($cfg['title'],
        '<p class="err">Your activation code expired before sign-in finished. '
      . 'Get a fresh code on your device and try again.</p>', $cfg);
    exit;
}

// Exchange the authorization code for tokens — server-side, modern TLS.
$oauth  = new OAuth2($cfg);
$tokens = $oauth->exchangeCode($authCode, callbackUrl());

if (!is_array($tokens) || empty($tokens['access_token'])) {
    dbg('Token exchange response: ' . json_encode($tokens));
    renderPage($cfg['title'],
        '<p class="err">Could not complete sign-in with ' . htmlspecialchars($cfg['title'])
      . '. Please try again.</p>', $cfg);
    exit;
}

// Park the device-facing token payload. Pass the provider's fields straight
// through (access_token, refresh_token, expires_in, token_type, …) minus our
// internal marker; the device reads what it needs.
unset($tokens['_http']);
$cache->fulfill($app, $code, $tokens);

renderPage($cfg['title'],
    '<p class="ok">Access approved!</p>'
  . '<p>Return to your webOS device — it will finish signing in automatically. '
  . 'If it doesn\'t, press <b>Verify</b> in the app.</p>', $cfg);

function base64url_decode($s) {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($s);
}
