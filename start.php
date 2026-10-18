<?php
/**
 * start.php  (user's real browser, form POST target)
 *
 * Validates the activation code the user typed, then drives the app's flow:
 *   - oauth2_authcode : stash a CSRF nonce, redirect the browser to the
 *                       provider's consent page. The result comes back to
 *                       callback.php.
 *   - oauth1_xauth    : do the username/password → token exchange right here,
 *                       park the token against the code, show "return to
 *                       device".
 *   - oauth1_3legged  : obtain a temporary request token, stash it (and the
 *                       app + code) in the session, redirect to the
 *                       provider's real consent screen. The result comes
 *                       back to callback.php - but unlike oauth2_authcode,
 *                       with no query string of ours on it at all; see the
 *                       oauth1_3legged block below for why.
 *   - apple_musickit  : no redirect at all. Serves a page that runs MusicKit
 *                       JS authorize() in the browser; that page posts the
 *                       Music User Token to musickit-callback.php.
 */
require __DIR__ . '/common.php';

$app = resolveAppName();
$cfg = loadApp($app);

$code = isset($_POST['code']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['code'])) : '';

$cache = new Cache($GLOBALS['CACHE_PATH'], $GLOBALS['CACHE_TTL']);

// The device must have registered this code first (via get-code.php).
if ($code === '' || !$cache->exists($app, $code)) {
    header('Location: ' . activateUrl($app)
        . '?err=' . urlencode('That code is unknown or has expired. Get a fresh code on your device and try again.'));
    exit;
}

if ($cfg['flow'] === 'oauth1_xauth') {
    // ---- xAuth: exchange credentials for a token, server-side ----
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    if ($username === '' || $password === '') {
        header('Location: ' . activateUrl($app) . '?code=' . urlencode($code)
            . '&err=' . urlencode('Please enter your username and password.'));
        exit;
    }

    $oauth  = new OAuth1($cfg['consumer_key'], $cfg['consumer_secret']);
    $result = $oauth->xAuth($cfg['access_token_url'], $username, $password);

    if (!$result || empty($result['oauth_token']) || empty($result['oauth_token_secret'])) {
        header('Location: ' . activateUrl($app) . '?code=' . urlencode($code)
            . '&err=' . urlencode('Login failed. Check your ' . $cfg['title'] . ' username and password.'));
        exit;
    }

    $cache->fulfill($app, $code, array(
        'oauth_token'        => $result['oauth_token'],
        'oauth_token_secret' => $result['oauth_token_secret'],
        'username'           => isset($result['username']) ? $result['username'] : $username,
    ));

    renderPage($cfg['title'],
        '<p class="ok">Signed in!</p>'
      . '<p>Return to your webOS device — it will finish signing in automatically. '
      . 'If it doesn\'t, press <b>Verify</b> in the app.</p>', $cfg);
    exit;
}

if ($cfg['flow'] === 'oauth1_3legged') {
    // ---- three-legged OAuth1: get a request token, send the browser to the
    // provider's real consent screen ----
    //
    // oauth_callback is the single fixed callbackUrl(), no query string - the
    // spec allows a dynamic one, but real providers commonly don't: Tumblr
    // rejects anything but its pre-registered callback with "Disallowed
    // oauth_callback specified" (confirmed against the live API, not just
    // documented behavior). So nothing about *which* pending login this is
    // can ride on the callback URL, unlike the OAuth2 path's state param.
    // Everything callback.php needs - app, code, the request token and its
    // secret - goes in the session instead, all under one key: a session
    // only ever has one oauth1_3legged sign-in in flight at a time, which is
    // what "one browser tab, one attempt" actually looks like.
    $oauth = new OAuth1($cfg['consumer_key'], $cfg['consumer_secret']);
    $reqToken = $oauth->requestToken($cfg['request_token_url'], callbackUrl());

    if (!$reqToken || empty($reqToken['oauth_token']) || empty($reqToken['oauth_token_secret'])) {
        header('Location: ' . activateUrl($app) . '?code=' . urlencode($code)
            . '&err=' . urlencode('Could not start sign-in with ' . $cfg['title'] . '. Please try again.'));
        exit;
    }

    $_SESSION['broker_oauth1_pending'] = array(
        'app'    => $app,
        'code'   => $code,
        'token'  => $reqToken['oauth_token'],
        'secret' => $reqToken['oauth_token_secret'],
    );

    header('Location: ' . $oauth->authorizeUrl($cfg['authorize_url'], $reqToken['oauth_token']));
    exit;
}

if ($cfg['flow'] === 'apple_musickit') {
    // ---- Apple Music: there is no provider redirect. Serve a page that runs
    // MusicKit JS's authorize() in this browser and posts the Music User Token
    // to musickit-callback.php. The nonce ties that POST to this browser
    // session, so only the page we just served can fulfil this code.
    if (empty($cfg['developer_token'])) {
        renderPage($cfg['title'],
            '<p class="err">This app is missing its developer token on the broker.</p>', $cfg);
        exit;
    }

    $nonce = bin2hex(random_bytes(16));
    $_SESSION['broker_musickit'][$app . '_' . $code] = $nonce;

    $boot = json_encode(array(
        'developerToken' => $cfg['developer_token'],
        'appName'        => isset($cfg['app_name'])  ? $cfg['app_name']  : $cfg['title'],
        'appBuild'       => isset($cfg['app_build']) ? $cfg['app_build'] : '1.0',
        'callback'       => $GLOBALS['BROKER_BASE_URL'] . '/musickit-callback.php?app=' . urlencode($app),
        'code'           => $code,
        'nonce'          => $nonce,
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    header('Cache-Control: no-store');
    renderPage($cfg['title'],
        '<p id="intro">Sign in with your Apple ID to let your webOS device use Apple Music.</p>'
      . '<button type="button" id="go" disabled>Loading Apple Music&hellip;</button>'
      . '<p id="msg"></p>'
      . '<script>' . musickitPageScript($boot) . '</script>'
      . '<script src="https://js-cdn.music.apple.com/musickit/v3/musickit.js" async></script>', $cfg);
    exit;
}

// ---- oauth2_authcode: redirect to the provider's consent screen ----
$nonce = bin2hex(random_bytes(16));
$_SESSION['broker_nonce'][$app . '_' . $code] = $nonce;

$state = base64url_encode(json_encode(array(
    'app'   => $app,
    'code'  => $code,
    'nonce' => $nonce,
)));

$oauth = new OAuth2($cfg);
header('Location: ' . $oauth->authorizeUrl(callbackUrl(), $state));
exit;

function base64url_encode($s) {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * The inline script for the apple_musickit sign-in page. It must be defined
 * (listener attached) before musickit.js loads, which fires `musickitloaded`
 * as soon as it's ready.
 */
function musickitPageScript($bootJson) {
    return <<<JS
var BOOT = $bootJson;
var go = document.getElementById('go');
var msg = document.getElementById('msg');
function say(text, cls) { msg.className = cls || ''; msg.textContent = text; }
document.addEventListener('musickitloaded', function () {
    MusicKit.configure({
        developerToken: BOOT.developerToken,
        app: { name: BOOT.appName, build: BOOT.appBuild }
    }).then(function () {
        go.disabled = false;
        go.textContent = 'Sign in with Apple Music';
    }, function (e) {
        say('Apple Music could not start: ' + (e && e.message ? e.message : e), 'err');
        go.textContent = 'Unavailable';
    });
});
go.onclick = function () {
    var mk = MusicKit.getInstance();
    go.disabled = true;
    say('Waiting for Apple…');
    mk.authorize().then(function (mut) {
        say('Finishing up…');
        var body = 'code=' + encodeURIComponent(BOOT.code)
                 + '&nonce=' + encodeURIComponent(BOOT.nonce)
                 + '&music_user_token=' + encodeURIComponent(mut)
                 + '&storefront=' + encodeURIComponent(mk.storefrontId || '');
        return fetch(BOOT.callback, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); });
    }).then(function (res) {
        if (res && res.ok) {
            document.getElementById('intro').textContent = '';
            go.style.display = 'none';
            say('Signed in! Return to your webOS device - it will finish signing in automatically.', 'ok');
        } else {
            go.disabled = false;
            say((res && res.error) || 'Sign-in failed. Please try again.', 'err');
        }
    }, function (e) {
        go.disabled = false;
        say('Sign-in was cancelled or failed. ' + (e && e.message ? e.message : ''), 'err');
    });
};
JS;
}
