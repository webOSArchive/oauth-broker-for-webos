<?php
/**
 * check-code.php  (device → broker, polled)
 *
 * The device polls this with its code. While the user is still authenticating
 * we answer {status:"pending"}. Once tokens are parked we return them ONCE and
 * immediately delete the record (one-time pickup), so tokens don't linger.
 *
 *   GET /check-code.php?app=box&code=BKF7Q   (or /box/check-code?code=BKF7Q)
 *   → {status:"pending"}                         while waiting
 *   → {status:"ready", access_token:"…", …}      when done (then deleted)
 *   → {error:"…"}                                if unknown/expired
 */
require __DIR__ . '/common.php';

$app  = resolveAppName();
$cfg  = loadApp($app);
$code = isset($_GET['code']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code'])) : '';

if ($code === '') {
    jsonError('No activation code supplied.');
}

$cache = new Cache($GLOBALS['CACHE_PATH'], $GLOBALS['CACHE_TTL']);
$rec   = $cache->read($app, $code);

if ($rec === false) {
    jsonError('Activation code not found or expired.', 404);
}

if (empty($rec['status']) || $rec['status'] !== 'ready') {
    jsonOut(array('status' => 'pending'));
}

// Ready — strip bookkeeping fields, hand tokens over, then burn the record.
unset($rec['created'], $rec['fulfilled'], $rec['app'], $rec['code']);
$cache->remove($app, $code);

jsonOut($rec);
