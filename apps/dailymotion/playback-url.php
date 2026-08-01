<?php
/**
 * GET /apps/dailymotion/playback-url.php?video_id=x63h9jv
 *
 * The one part of video playback that has to go through the broker rather
 * than being called directly from the device (unlike Home/My Videos/
 * Favorites/Subscriptions, which all call Dailymotion's legacy API
 * directly - see DMBroker-lib.js). Dailymotion API v2 - the only place
 * that returns an actual playable progressive file, via
 * POST /v2/videos/{id}/downloads - sends no CORS headers on ANY response,
 * confirmed directly including the OPTIONS preflight (a bare 401, no
 * access-control-allow-origin at all), so the device's WebView JS
 * literally cannot call it, regardless of what token it holds.
 *
 * Why this endpoint even exists: webOS 3.0.5's WebKit (~2011) can't run
 * Dailymotion's current embed player at all (modern webpack/ES6+ bundle -
 * confirmed by pulling the actual page and inspecting it), so this app
 * builds its own minimal native <video> page (player.html) instead and
 * needs a plain, direct, low-resolution H.264 MP4 URL to point it at - not
 * an HLS/DASH adaptive manifest (this device's GStreamer 0.10 stack has no
 * HLS/DASH demuxer plugin either, confirmed by listing /usr/lib).
 *
 * Self-contained (no shared _lib.php/OAuth2.php - this is the only script
 * that needs v2 auth at all): mints a v2 client_credentials token
 * server-side (the client_secret from config.php never leaves the
 * broker - the same client_id/client_secret already used for the legacy
 * login), fetches every available download rendition, and returns just
 * the lowest-resolution video one.
 *
 * Response: {"url": "https://...mp4?...", "label": "240p"}
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cfg = require __DIR__ . '/config.php';

$videoId = isset($_GET['video_id']) ? $_GET['video_id'] : '';
if ($videoId === '') {
    http_response_code(400);
    echo json_encode(array('error' => 'missing_video_id'));
    exit;
}

$token = dm_v2_get_cached_token($cfg);
if (!$token) {
    http_response_code(502);
    echo json_encode(array('error' => 'token_unavailable'));
    exit;
}

$result = dm_v2_post('https://api.dailymotion.com/v2/videos/' . rawurlencode($videoId) . '/downloads', $token, array());
if (!is_array($result) || empty($result['downloads']) || !is_array($result['downloads'])) {
    http_response_code(502);
    echo json_encode(array('error' => 'no_downloads_available'));
    exit;
}

$best = dm_pick_lowest_quality($result['downloads']);
if (!$best) {
    http_response_code(502);
    echo json_encode(array('error' => 'no_video_rendition'));
    exit;
}

echo json_encode(array(
    'url'   => $best['download_url'],
    'label' => isset($best['label']) ? $best['label'] : '',
));

/**
 * v2 client_credentials token, cached to a file in the system temp dir
 * (keyed by client_id) for its expires_in window so concurrent/repeated
 * playback requests don't each mint a fresh token.
 */
function dm_v2_get_cached_token(array $cfg) {
    $cacheFile = sys_get_temp_dir() . '/dm_v2_token_' . md5($cfg['client_id']) . '.json';

    $cached = @file_get_contents($cacheFile);
    if ($cached !== false) {
        $data = json_decode($cached, true);
        if (is_array($data) && !empty($data['access_token']) && !empty($data['expires_at']) && $data['expires_at'] > time() + 60) {
            return $data['access_token'];
        }
    }

    $ch = curl_init('https://oauth2.dailymotion.com/v2/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
        'grant_type'    => 'client_credentials',
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'scope'         => 'video.read',
    )));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("dm_v2_get_cached_token: HTTP $httpCode: " . substr((string) $response, 0, 500));
        return false;
    }
    $json = json_decode($response, true);
    if (!is_array($json) || empty($json['access_token'])) {
        error_log('dm_v2_get_cached_token: bad response: ' . substr((string) $response, 0, 500));
        return false;
    }

    $expiresIn = isset($json['expires_in']) ? (int) $json['expires_in'] : 1800;
    @file_put_contents($cacheFile, json_encode(array(
        'access_token' => $json['access_token'],
        'expires_at'   => time() + $expiresIn,
    )));
    return $json['access_token'];
}

function dm_v2_post($url, $token, array $body) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("dm_v2_post: HTTP $httpCode from $url: " . substr((string) $response, 0, 500));
        return false;
    }
    return json_decode($response, true);
}

/**
 * Picks the smallest-resolution *video* rendition (ignores any non-video
 * entries) - "lowest that still plays" for a 2011-era mobile SoC, per the
 * explicit product decision to only pull what the TouchPad can decode.
 * Dailymotion's response doesn't include a numeric resolution field
 * directly (unconfirmed against a live response while writing this - no
 * v2 credentials were available), so this sorts by the leading number in
 * the "label" field (e.g. "240p" -> 240), falling back to treating an
 * unparseable label as the least-preferred (highest) option.
 */
function dm_pick_lowest_quality(array $downloads) {
    $videoOnly = array_values(array_filter($downloads, function ($d) {
        return !isset($d['type']) || $d['type'] === 'video';
    }));
    if (empty($videoOnly)) {
        return false;
    }
    usort($videoOnly, function ($a, $b) {
        return dm_label_to_number(isset($a['label']) ? $a['label'] : '') - dm_label_to_number(isset($b['label']) ? $b['label'] : '');
    });
    return $videoOnly[0];
}

function dm_label_to_number($label) {
    if (preg_match('/(\d+)/', $label, $m)) {
        return (int) $m[1];
    }
    return 999999;
}
