<?php
/**
 * callback.php  (provider → user's browser → here)
 *
 * The single redirect target registered with every OAuth2 provider, and also
 * the fixed callback three-legged OAuth1 providers (Tumblr) send the user
 * back to after they approve the request token. The two protocols hand back
 * entirely different query strings, so this file branches on shape before
 * doing anything else:
 *
 *   OAuth2          GET /callback.php?code=AUTH_CODE&state=BASE64URL
 *                   app + device code travel in `state`, checked against a
 *                   CSRF nonce stashed in the session by start.php.
 *
 *   OAuth1 3-legged GET /callback.php?oauth_token=&oauth_verifier=
 *                   No app/code on this one at all - unlike OAuth2's state,
 *                   OAuth1 has nowhere to put them: real providers commonly
 *                   reject a dynamic oauth_callback (Tumblr does, with
 *                   "Disallowed oauth_callback specified"), so the callback
 *                   URL has to be the fixed, pre-registered one, no query
 *                   string of ours attached. Everything needed - app, code,
 *                   the request token, its secret - comes from the session
 *                   start.php stashed it in instead.
 */
require __DIR__ . '/common.php';

if (isset($_GET['oauth_token']) && isset($_GET['oauth_verifier'])) {
    // ---- three-legged OAuth1: exchange the verifier for an access token ----
    //
    // Nothing here reads $_GET['app'] or $_GET['code'] - unlike the OAuth2
    // path below, this callback URL carries no query string of ours at all
    // (see start.php: real providers commonly reject a dynamic
    // oauth_callback, Tumblr included). app, code, and the request token +
    // secret all come from the session start.php stashed them in.
    $pending = isset($_SESSION['broker_oauth1_pending']) ? $_SESSION['broker_oauth1_pending'] : null;

    if (!$pending || !hash_equals($pending['token'], $_GET['oauth_token'])) {
        renderPage('Something went wrong',
            '<p class="err">Security check failed. Please start again from your device.</p>', null);
        exit;
    }
    unset($_SESSION['broker_oauth1_pending']);

    $app = $pending['app'];
    $code = $pending['code'];
    $cfg = loadApp($app);

    if ($cfg['flow'] !== 'oauth1_3legged') {
        renderPage('Something went wrong', '<p class="err">Unexpected callback for this app.</p>', $cfg);
        exit;
    }

    $cache = new Cache($GLOBALS['CACHE_PATH'], $GLOBALS['CACHE_TTL']);
    if (!$cache->exists($app, $code)) {
        renderPage($cfg['title'],
            '<p class="err">Your activation code expired before sign-in finished. '
          . 'Get a fresh code on your device and try again.</p>', $cfg);
        exit;
    }

    $oauth  = new OAuth1($cfg['consumer_key'], $cfg['consumer_secret']);
    $result = $oauth->accessToken($cfg['access_token_url'], $pending['token'], $pending['secret'], $_GET['oauth_verifier']);

    if (!$result || empty($result['oauth_token']) || empty($result['oauth_token_secret'])) {
        renderPage($cfg['title'],
            '<p class="err">Could not complete sign-in with ' . htmlspecialchars($cfg['title'])
          . '. Please try again.</p>', $cfg);
        exit;
    }

    $cache->fulfill($app, $code, array(
        'oauth_token'        => $result['oauth_token'],
        'oauth_token_secret' => $result['oauth_token_secret'],
    ));

    renderPage($cfg['title'],
        '<p class="ok">Access approved!</p>'
      . '<p>Return to your webOS device — it will finish signing in automatically. '
      . 'If it doesn\'t, press <b>Check now</b> in the app.</p>', $cfg);
    exit;
}

// ---- everything below is the existing OAuth2 authorization-code path ----

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
