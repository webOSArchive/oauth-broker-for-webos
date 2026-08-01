<?php
/**
 * GET /apps/dailymotion/playback-url.php?video_id=x63h9jv
 *
 * The one part of video playback that has to go through the broker rather
 * than being called directly from the device (unlike Home/My Videos/
 * Favorites/Subscriptions, which all call Dailymotion's legacy API
 * directly - see DMBroker-lib.js).
 *
 * History/why this shape: earlier versions of this file tried Dailymotion
 * API v2's POST /videos/{id}/downloads. That turned out to be scoped to
 * videos the authenticated Studio account actually owns - confirmed
 * directly, even a bare GET /v2/videos/{id} for an arbitrary public video
 * 403s with UPSTREAM_ACCESS_DENIED regardless of a valid client_credentials
 * token. It's a content-management API for your own catalog, not a general
 * "fetch any video" API - useless for Home/Search/other users' content.
 *
 * What actually works, matching how anonymous playback on dailymotion.com
 * itself works (no login, no API key): the player's own internal metadata
 * endpoint, https://www.dailymotion.com/player/metadata/video/{id} - the
 * same thing Dailymotion's own JS player (and tools like yt-dlp) call to
 * get a stream URL. No auth needed at all. Only delivery format is HLS
 * (.m3u8) - Dailymotion has no progressive/direct-file option anymore for
 * ANY access method, confirmed across the legacy API, v2, and this. Fetched
 * server-side because this endpoint sends no CORS headers, and because it's
 * Cloudflare-fronted with bot detection that flagged a scraping-environment
 * IP during development - the broker's own server IP, already trusted for
 * the legacy API calls, has a much better chance of not being blocked than
 * an arbitrary device or sandbox would.
 *
 * webOS 3.0.5's WebKit can't run Dailymotion's own embed player (confirmed:
 * modern webpack/ES6+ bundle), so this app points its own minimal native
 * <video> page (player.html) straight at the manifest URL instead. Whether
 * this device's GStreamer 0.10 stack (which does have libgstfragmented.so,
 * i.e. hlsdemux - confirmed on the actual device, correcting an earlier,
 * wrong "no HLS support" conclusion from an incomplete plugin-directory
 * search) can actually play it natively is the remaining open question this
 * whole endpoint exists to let us test for real.
 *
 * Response: {"url": "https://...m3u8?...", "title": "..."}
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$videoId = isset($_GET['video_id']) ? $_GET['video_id'] : '';
if ($videoId === '') {
    http_response_code(400);
    echo json_encode(array('error' => 'missing_video_id'));
    exit;
}

$ch = curl_init('https://www.dailymotion.com/player/metadata/video/' . rawurlencode($videoId));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Accept: application/json',
    // Presenting as a real browser - this endpoint is Cloudflare-fronted
    // with bot detection that rejected a bare curl/no-UA request during
    // development.
    'User-Agent: Mozilla/5.0 (Linux; wOSBrowser) AppleWebKit/534.6 (KHTML, like Gecko) Version/1.0 Safari/534.6',
    'Referer: https://www.dailymotion.com/',
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    error_log("playback-url.php: metadata fetch HTTP $httpCode for $videoId: " . substr((string) $response, 0, 500));
    http_response_code(502);
    echo json_encode(array('error' => 'metadata_unavailable'));
    exit;
}

$meta = json_decode($response, true);
$url = isset($meta['qualities']['auto'][0]['url']) ? $meta['qualities']['auto'][0]['url'] : '';
if ($url === '') {
    error_log("playback-url.php: no qualities.auto url for $videoId: " . substr((string) $response, 0, 500));
    http_response_code(502);
    echo json_encode(array('error' => 'no_stream_available'));
    exit;
}

echo json_encode(array(
    'url'   => $url,
    'title' => isset($meta['title']) ? $meta['title'] : '',
));
