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
