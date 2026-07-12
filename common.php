<?php
/**
 * common.php — bootstrap included by every endpoint.
 *
 * Loads global + per-app config, wires the autoloader for lib/ classes,
 * resolves which app a request is for, and provides small shared helpers
 * (JSON responses, branded HTML pages, the canonical redirect URI).
 */

session_start();
// Exclude deprecations/notices so they never leak into a JSON body; genuine
// errors still surface (and are only *displayed* when $DEBUG is on, below).
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require __DIR__ . '/config.php';

$GLOBALS['BROKER_BASE_URL'] = rtrim($BROKER_BASE_URL, '/');
$GLOBALS['CACHE_PATH']      = $CACHE_PATH;
$GLOBALS['CACHE_TTL']       = $CACHE_TTL;
$GLOBALS['APPS_PATH']       = rtrim($APPS_PATH, '/');
$GLOBALS['DEBUG']           = $DEBUG;

ini_set('display_errors', $DEBUG ? '1' : '0');

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/lib/' . $class . '.php';
    if (is_file($file)) {
        include $file;
    }
});

/**
 * Figure out which app a request targets.
 * Preference order:
 *   1. explicit ?app=NAME query parameter (always works, no rewrite needed)
 *   2. first path segment of the URL (pretty form: /box/get-code)
 * Returns a validated app name, or null if none/invalid.
 */
function resolveAppName() {
    $name = null;
    if (isset($_GET['app']) && $_GET['app'] !== '') {
        $name = $_GET['app'];
    } else {
        $uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $segs = array_values(array_filter(explode('/', $uri)));
        // Skip a leading path segment if the broker is hosted in a subdir and
        // the first segment is a known endpoint file rather than an app.
        foreach ($segs as $seg) {
            if (substr($seg, -4) === '.php') {
                continue;
            }
            $name = $seg;
            break;
        }
    }
    if ($name === null) {
        return null;
    }
    $name = preg_replace('/[^a-z0-9_-]/i', '', $name);
    if ($name === '' || !is_dir($GLOBALS['APPS_PATH'] . '/' . $name)) {
        return null;
    }
    return $name;
}

/** Load and validate an app config array. Exits with an error page on failure. */
function loadApp($name) {
    if ($name === null) {
        jsonErrorOrPage('Unknown app. Check the address and try again.');
    }
    $file = $GLOBALS['APPS_PATH'] . '/' . $name . '/config.php';
    if (!is_file($file)) {
        jsonErrorOrPage('This app is not configured on the broker.');
    }
    $cfg = include $file;
    if (!is_array($cfg) || empty($cfg['flow'])) {
        jsonErrorOrPage('App configuration is invalid.');
    }
    $cfg['name'] = $name;
    return $cfg;
}

/** The provider redirect target for OAuth2 apps — a single shared callback. */
function callbackUrl() {
    return $GLOBALS['BROKER_BASE_URL'] . '/callback.php';
}

/** The human-facing activation URL shown on the device for an app. */
function activateUrl($name) {
    return $GLOBALS['BROKER_BASE_URL'] . '/' . $name;
}

/** Emit a JSON response and stop. */
function jsonOut($obj, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj);
    exit;
}

/** Convenience: JSON error for device-facing endpoints. */
function jsonError($msg, $status = 400) {
    jsonOut(array('error' => $msg), $status);
}

/**
 * If the current request looks like a device/API call, return JSON;
 * otherwise render a branded HTML error page. Used by loadApp() which can be
 * reached from both contexts.
 */
function jsonErrorOrPage($msg) {
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
    $isApi  = isset($_GET['app']) && (strpos($accept, 'application/json') !== false
              || basename($_SERVER['SCRIPT_NAME']) !== 'activate.php');
    if ($isApi && basename($_SERVER['SCRIPT_NAME']) !== 'activate.php'
        && basename($_SERVER['SCRIPT_NAME']) !== 'callback.php') {
        jsonError($msg, 400);
    }
    renderPage('Something went wrong', '<p class="err">' . htmlspecialchars($msg) . '</p>', null);
    exit;
}

/**
 * Render a minimal, self-contained branded page. No external assets so it
 * works even on a locked-down host, and reads fine in any browser.
 */
function renderPage($title, $bodyHtml, $cfg = null) {
    $appTitle = $cfg && !empty($cfg['title']) ? $cfg['title'] : 'webOS OAuth';
    $accent   = $cfg && !empty($cfg['accent']) ? $cfg['accent'] : '#2b6cb0';
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
    echo '<title>' . htmlspecialchars($appTitle) . '</title>';
    echo '<style>'
        . 'body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f5f7;'
        . 'color:#1a202c;margin:0;padding:32px 16px;}'
        . '.card{max-width:440px;margin:0 auto;background:#fff;border-radius:12px;'
        . 'box-shadow:0 1px 3px rgba(0,0,0,.12);padding:28px 26px;}'
        . 'h1{font-size:20px;margin:0 0 4px;color:' . $accent . ';}'
        . '.sub{color:#718096;font-size:13px;margin:0 0 20px;}'
        . 'label{display:block;font-size:13px;font-weight:600;margin:14px 0 4px;}'
        . 'input[type=text],input[type=password]{width:100%;box-sizing:border-box;'
        . 'font-size:16px;padding:10px 12px;border:1px solid #cbd5e0;border-radius:8px;}'
        . 'input#code{letter-spacing:3px;text-align:center;text-transform:uppercase;font-size:22px;}'
        . 'button,.btn{display:inline-block;background:' . $accent . ';color:#fff;border:0;'
        . 'font-size:16px;font-weight:600;padding:12px 18px;border-radius:8px;cursor:pointer;'
        . 'text-decoration:none;margin-top:20px;width:100%;box-sizing:border-box;text-align:center;}'
        . '.ok{color:#2f855a;font-weight:600;}.err{color:#c53030;font-weight:600;}'
        . 'p{line-height:1.5;font-size:15px;}small{color:#718096;}'
        . '</style></head><body><div class="card">';
    echo '<h1>' . htmlspecialchars($appTitle) . '</h1>';
    echo '<p class="sub">Sign-in helper for legacy webOS devices</p>';
    echo $bodyHtml;
    echo '</div></body></html>';
}

/** Verbose diagnostic line, only when $DEBUG is on. */
function dbg($msg) {
    if ($GLOBALS['DEBUG']) {
        echo '<pre style="font-size:11px;color:#a0aec0;white-space:pre-wrap;">'
            . htmlspecialchars($msg) . '</pre>';
    }
}
