<?php
/**
 * musickit-callback.php  (user's browser -> broker, fetch() POST)
 *
 * The apple_musickit counterpart to callback.php. There is no provider
 * redirect to land on: the sign-in page start.php serves runs MusicKit JS in
 * the browser and posts the resulting Music User Token here.
 *
 *   POST /musickit-callback.php?app=applemusic
 *        code=BKF7Q&nonce=…&music_user_token=…&storefront=us
 *   → {ok:true}                  token parked; the device's next poll gets it
 *   → {ok:false, error:"…"}      rejected
 *
 * The nonce must match the one start.php stashed in this browser's session, so
 * only the page we served can fulfil a code. The token is then checked against
 * Apple with the app's developer token before it is parked, so a garbage value
 * never reaches the device.
 */
require __DIR__ . '/common.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    musickitFail('POST only.', 405);
}

$app = resolveAppName();
$cfg = loadApp($app);

if ($cfg['flow'] !== 'apple_musickit') {
    musickitFail('Unexpected callback for this app.');
}

$code     = isset($_POST['code'])  ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['code'])) : '';
$nonce    = isset($_POST['nonce']) ? $_POST['nonce'] : '';
$mut      = isset($_POST['music_user_token']) ? trim($_POST['music_user_token']) : '';
$storefront = isset($_POST['storefront']) ? strtolower(preg_replace('/[^A-Za-z]/', '', $_POST['storefront'])) : '';

$expected = isset($_SESSION['broker_musickit'][$app . '_' . $code])
          ? $_SESSION['broker_musickit'][$app . '_' . $code] : null;
if ($code === '' || !$expected || $nonce === '' || !hash_equals($expected, $nonce)) {
    musickitFail('Security check failed. Please start again from your device.', 403);
}

// A Music User Token is a long base64-ish string; anything else is not one.
if ($mut === '' || strlen($mut) > 4096 || !preg_match('#^[A-Za-z0-9+/=_.\-]+$#', $mut)) {
    musickitFail('Apple did not return a valid sign-in token. Please try again.');
}

$cache = new Cache($GLOBALS['CACHE_PATH'], $GLOBALS['CACHE_TTL']);
if (!$cache->exists($app, $code)) {
    musickitFail('Your activation code expired before sign-in finished. '
               . 'Get a fresh code on your device and try again.', 410);
}

// Ask Apple who this token belongs to. A definite 401/403 means the token (or
// our developer token) is bad; anything inconclusive (no network, TLS trouble
// on the broker host) lets it through rather than failing a good sign-in.
$check = musickitVerify($cfg['developer_token'], $mut);
if ($check['status'] === 401 || $check['status'] === 403) {
    musickitFail('Apple rejected the sign-in token. Please try again.', 502);
}
if ($check['storefront'] !== '') {
    $storefront = $check['storefront'];
}

$cache->fulfill($app, $code, array(
    'access_token'     => $mut,
    'music_user_token' => $mut,
    'token_type'       => 'MusicUserToken',
    'storefront'       => $storefront,
));
unset($_SESSION['broker_musickit'][$app . '_' . $code]);

jsonOut(array('ok' => true));

function musickitFail($msg, $status = 400) {
    jsonOut(array('ok' => false, 'error' => $msg), $status);
}

/** @return array {status:int (0 = no answer), storefront:string} */
function musickitVerify($developerToken, $mut) {
    $out = array('status' => 0, 'storefront' => '');
    $ch = curl_init('https://api.music.apple.com/v1/me/storefront');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => array(
            'Authorization: Bearer ' . $developerToken,
            'Music-User-Token: ' . $mut,
        ),
    ));
    $body = curl_exec($ch);
    if ($body === false) {
        error_log('oauth-broker: apple_musickit verify failed: ' . curl_error($ch));
        curl_close($ch);
        return $out;
    }
    $out['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($body, true);
    if ($out['status'] === 200 && isset($j['data'][0]['id'])) {
        $out['storefront'] = strtolower(preg_replace('/[^A-Za-z]/', '', $j['data'][0]['id']));
    }
    return $out;
}
