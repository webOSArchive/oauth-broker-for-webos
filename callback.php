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

$authCode = isset($_GET['code'])  ? $_GET['code']  : '';
$stateRaw = isset($_GET['state']) ? $_GET['state'] : '';
$state    = json_decode(base64url_decode($stateRaw), true);
$provErr  = isset($_GET['error']) ? $_GET['error'] : '';
$provDesc = isset($_GET['error_description']) ? $_GET['error_description'] : '';

/** The page for a provider-side error (the user declined, etc.). */
function providerErrorPage($err, $desc) {
    renderPage('Sign-in cancelled',
        '<p class="err">' . htmlspecialchars($err . ($desc !== '' ? ': ' . $desc : '')) . '</p>'
      . '<p>You can close this page and try again from your device.</p>', null);
    exit;
}

if (!is_array($state) || empty($state['app']) || empty($state['code'])
    || !is_string($state['app']) || !is_string($state['code'])) {
    if ($provErr !== '') {
        providerErrorPage($provErr, $provDesc);
    }
    renderPage('Something went wrong',
        '<p class="err">Missing or malformed authorization response.</p>', null);
    exit;
}

$app  = preg_replace('/[^a-z0-9_-]/i', '', $state['app']);
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $state['code']));
$cfg  = loadApp($app);

// The app whose device code this sign-in answers. It is $app itself, except in
// a fallback round (see fallBack() below), where the device asked for one app
// and the account is being signed in with that app's fallback_app instead.
$owner = $app;
if (isset($state['for']) && is_string($state['for'])) {
    $owner    = preg_replace('/[^a-z0-9_-]/i', '', $state['for']);
    $ownerCfg = loadApp($owner);
    if (empty($ownerCfg['fallback_app']) || $ownerCfg['fallback_app'] !== $app) {
        renderPage('Something went wrong',
            '<p class="err">Unexpected callback for this app.</p>', $cfg);
        exit;
    }
}
$nonceKey = $owner . '_' . $code;

// CSRF: the nonce must match the one we stashed in this browser's session.
$expected = isset($_SESSION['broker_nonce'][$nonceKey])
          ? $_SESSION['broker_nonce'][$nonceKey] : null;
if (!$expected || empty($state['nonce']) || !is_string($state['nonce'])
    || !hash_equals($expected, $state['nonce'])) {
    if ($provErr !== '') {
        providerErrorPage($provErr, $provDesc);
    }
    renderPage('Something went wrong',
        '<p class="err">Security check failed. Please start again from your device.</p>', $cfg);
    exit;
}
unset($_SESSION['broker_nonce'][$nonceKey]);

// Provider-side error at the redirect (user declined, scope refused, etc.)
if ($provErr !== '') {
    fallBack($cfg, $owner, $code, $provErr, $provDesc);
    providerErrorPage($provErr, $provDesc);
}

if ($authCode === '') {
    renderPage('Something went wrong',
        '<p class="err">Missing or malformed authorization response.</p>', $cfg);
    exit;
}

$cache = new Cache($GLOBALS['CACHE_PATH'], $GLOBALS['CACHE_TTL']);
if (!$cache->exists($owner, $code)) {
    renderPage($cfg['title'],
        '<p class="err">Your activation code expired before sign-in finished. '
      . 'Get a fresh code on your device and try again.</p>', $cfg);
    exit;
}

// Exchange the authorization code for tokens — server-side, modern TLS.
$oauth  = new OAuth2($cfg);
$tokens = $oauth->exchangeCode($authCode, callbackUrl());

if (is_array($tokens) && empty($tokens['access_token'])) {
    fallBack($cfg, $owner, $code,
        isset($tokens['error']) && is_string($tokens['error']) ? $tokens['error'] : '',
        isset($tokens['error_description']) && is_string($tokens['error_description'])
            ? $tokens['error_description'] : '');
}

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
if ($owner !== $app) {
    // A fallback round: tell the device which app these tokens belong to, so
    // it refreshes them through that app and knows what the account can do.
    $tokens['broker_app'] = $app;
}
$cache->fulfill($owner, $code, $tokens);

renderPage($cfg['title'],
    '<p class="ok">Access approved!</p>'
  . '<p>Return to your webOS device — it will finish signing in automatically. '
  . 'If it doesn\'t, press <b>Verify</b> in the app.</p>', $cfg);

/**
 * Some providers refuse an app's scope for some accounts only, and say so
 * after the user has already signed in: Microsoft takes a personal account
 * through its page with Exchange's scope and refuses it at the code exchange
 * (AADSTS70011, invalid_scope). An app whose config names a fallback_app is
 * then signed in with that app instead, in the same browser visit and against
 * the same device code: the browser goes straight on to the fallback app's
 * authorize URL (plus the first app's fallback_extra params), and the tokens
 * are parked for the device's original code with broker_app naming the app
 * they are for.
 *
 * Returns (to let the caller show the error) unless the fallback applies, in
 * which case it redirects and exits. Only ever one round: the fallback app's
 * own refusal is an error.
 */
function fallBack(array $cfg, $owner, $code, $err, $desc) {
    if ($owner !== $cfg['name'] || empty($cfg['fallback_app'])) {
        return;
    }
    $refused = ($err === 'invalid_scope');
    if (!$refused && !empty($cfg['fallback_on']) && is_array($cfg['fallback_on'])) {
        foreach ($cfg['fallback_on'] as $needle) {
            if ($needle !== '' && strpos($desc, $needle) !== false) {
                $refused = true;
                break;
            }
        }
    }
    if (!$refused) {
        return;
    }
    $fbName = preg_replace('/[^a-z0-9_-]/i', '', $cfg['fallback_app']);
    $fbFile = $GLOBALS['APPS_PATH'] . '/' . $fbName . '/config.php';
    if ($fbName === '' || !is_file($fbFile)) {
        return;
    }
    $fbCfg = loadApp($fbName);
    if ($fbCfg['flow'] !== 'oauth2_authcode') {
        return;
    }

    $nonce = bin2hex(random_bytes(16));
    $_SESSION['broker_nonce'][$owner . '_' . $code] = $nonce;
    $state = base64url_encode(json_encode(array(
        'app'   => $fbName,
        'code'  => $code,
        'nonce' => $nonce,
        'for'   => $owner,
    )));
    $extra = (!empty($cfg['fallback_extra']) && is_array($cfg['fallback_extra']))
           ? $cfg['fallback_extra'] : array();
    $oauth = new OAuth2($fbCfg);
    header('Location: ' . $oauth->authorizeUrl(callbackUrl(), $state, $extra));
    exit;
}

function base64url_encode($s) {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function base64url_decode($s) {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($s);
}
