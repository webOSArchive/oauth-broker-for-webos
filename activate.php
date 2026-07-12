<?php
/**
 * activate.php  (user's real browser)
 *
 * The page the user opens on a modern computer/phone. The device told them to
 * come here and gave them a code. This renders the right form for the app's
 * flow:
 *   - oauth2_authcode : just the code, then "Continue to <provider>"
 *   - oauth1_xauth    : code + provider username/password (entered on THIS
 *                       trusted browser, never on the device or the wire to it)
 *
 * Pretty URL: GET /box  → (rewrite) → activate.php?app=box
 */
require __DIR__ . '/common.php';

$app = resolveAppName();
$cfg = loadApp($app);

$prefill = isset($_GET['code']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code'])) : '';
$err     = isset($_GET['err']) ? $_GET['err'] : '';

$codeField =
    '<label for="code">Activation code from your device</label>'
  . '<input type="text" id="code" name="code" autocomplete="off" autocapitalize="characters"'
  . ' spellcheck="false" value="' . htmlspecialchars($prefill) . '" required>';

if ($cfg['flow'] === 'oauth1_xauth') {
    $userLabel = isset($cfg['username_label']) ? $cfg['username_label'] : 'Username';
    $body =
        ($err ? '<p class="err">' . htmlspecialchars($err) . '</p>' : '')
      . '<p>Enter the code shown on your webOS device, then sign in to '
      . htmlspecialchars($cfg['title']) . '.</p>'
      . '<form method="POST" action="start.php?app=' . urlencode($app) . '">'
      . $codeField
      . '<label for="u">' . htmlspecialchars($userLabel) . '</label>'
      . '<input type="text" id="u" name="username" autocomplete="username" required>'
      . '<label for="p">Password</label>'
      . '<input type="password" id="p" name="password" autocomplete="current-password" required>'
      . '<button type="submit">Sign in</button>'
      . '</form>'
      . '<p><small>Your password is sent only to ' . htmlspecialchars($cfg['title'])
      . ' to obtain a token. The device never sees it.</small></p>';
} else { // oauth2_authcode
    $body =
        ($err ? '<p class="err">' . htmlspecialchars($err) . '</p>' : '')
      . '<p>Enter the code shown on your webOS device, then continue to '
      . htmlspecialchars($cfg['title']) . ' to approve access.</p>'
      . '<form method="POST" action="start.php?app=' . urlencode($app) . '">'
      . $codeField
      . '<button type="submit">Continue to ' . htmlspecialchars($cfg['title']) . '</button>'
      . '</form>';
}

renderPage($cfg['title'], $body, $cfg);
