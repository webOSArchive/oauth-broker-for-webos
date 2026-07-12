<?php
/**
 * get-code.php  (device → broker)
 *
 * The legacy device calls this to start a login. We mint a short, human-
 * readable code, park a pending record against it, and hand the device the
 * code plus the URL the user should visit in a real browser.
 *
 *   GET /get-code.php?app=box     (or pretty: GET /box/get-code)
 *   → { "code":"BKF7Q", "useUrl":"https://oauth.wosa.link/box",
 *       "pollSeconds":3, "flow":"oauth2_authcode" }
 */
require __DIR__ . '/common.php';

$app = resolveAppName();
$cfg = loadApp($app);

$cache = new Cache($GLOBALS['CACHE_PATH'], $GLOBALS['CACHE_TTL']);
$code  = $cache->generateCode($app);

if (!$cache->create($app, $code)) {
    jsonError('Could not allocate a login code. Please try again.', 500);
}

jsonOut(array(
    'code'        => $code,
    'useUrl'      => activateUrl($app),
    'pollSeconds' => 3,
    'flow'        => $cfg['flow'],
    'appTitle'    => isset($cfg['title']) ? $cfg['title'] : $app,
));
