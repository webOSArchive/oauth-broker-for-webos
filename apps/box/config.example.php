<?php
/**
 * Box — OAuth 2.0 authorization-code.
 *
 * Setup:
 *   1. Box developer console → create a "Custom App" → "Standard OAuth 2.0".
 *   2. Redirect URI:  https://oauth.wosa.link/callback.php
 *   3. Copy this file to apps/box/config.php and paste in your client id/secret.
 *
 * Box access tokens last ~60 min and refresh tokens rotate on use; the device
 * refreshes through refresh.php so the secret stays on the server.
 */
return array(
    'flow'   => 'oauth2_authcode',
    'title'  => 'Box',
    'accent' => '#0061d5',

    'client_id'     => 'YOUR_BOX_CLIENT_ID',
    'client_secret' => 'YOUR_BOX_CLIENT_SECRET',

    'authorize_url' => 'https://account.box.com/api/oauth2/authorize',
    'token_url'     => 'https://api.box.com/oauth2/token',

    // Box grants the app's configured scopes; no scope string needed here.
    'scope'           => '',
    'authorize_extra' => array(),
);
