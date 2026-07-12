<?php
/**
 * Global broker configuration. Copy to config.php and edit.
 * config.php is git-ignored so real values never get committed.
 */

// Public base URL of this broker, no trailing slash.
// This is what the OAuth provider redirects back to and what devices call.
$BROKER_BASE_URL = 'https://oauth.wosa.link';

// Where pending-login code files are stored. Keep this OUTSIDE the web root
// if at all possible — the tokens live here briefly in the clear. If it must
// live under the web root, the shipped .htaccess / nginx snippet denies HTTP
// access to it.
$CACHE_PATH = __DIR__ . '/../oauth-broker-cache';

// How long a code (and any tokens parked against it) may live before reaping.
$CACHE_TTL = 7200; // 2 hours

// Directory holding per-app configs (apps/<name>/config.php).
$APPS_PATH = __DIR__ . '/apps';

// Show verbose diagnostics in browser pages. Never enable in production.
$DEBUG = false;
