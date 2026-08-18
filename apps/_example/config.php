<?php
/**
 * Per-app config template.
 *
 * To add an app called "myapp":
 *   1. mkdir apps/myapp
 *   2. copy this file to apps/myapp/config.php
 *   3. fill in the values below
 *   4. register  https://oauth.wosa.link/callback.php  as the redirect URI
 *      in the provider's developer console (OAuth2 apps only)
 *
 * The file must RETURN an associative array. It holds secrets, so real
 * configs (apps/<name>/config.php) are git-ignored.
 *
 * -------------------------------------------------------------------------
 * FLOW A — "oauth2_authcode": modern OAuth 2.0 authorization-code grant.
 *   The user approves access on the provider's consent screen (in their real
 *   browser). Best for almost every current service (Box, Google, Dropbox,
 *   Reddit, Microsoft, …). Tokens are refreshable via refresh.php.
 * -------------------------------------------------------------------------
 */
return array(
    'flow'  => 'oauth2_authcode',
    'title' => 'My Service',        // shown on the helper page + device
    'accent' => '#2b6cb0',          // optional brand colour

    'client_id'     => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',

    'authorize_url' => 'https://provider.example.com/oauth/authorize',
    'token_url'     => 'https://provider.example.com/oauth/token',

    'scope' => '',                  // optional, provider-specific

    // Optional extra query params appended to the authorize URL. Many providers
    // need these to actually issue a refresh token, e.g. Google:
    //   'authorize_extra' => array('access_type' => 'offline', 'prompt' => 'consent'),
    'authorize_extra' => array(),
);

/*
 * -------------------------------------------------------------------------
 * FLOW B — "oauth1_xauth": legacy OAuth 1.0a with direct credential exchange.
 *   The user types their provider username/password on the helper page (a
 *   trusted modern browser); the broker exchanges them for a non-expiring
 *   token/secret. Only for providers that still support xAuth (e.g.
 *   Instapaper). Return an array shaped like this instead:
 *
 * return array(
 *     'flow'  => 'oauth1_xauth',
 *     'title' => 'Instapaper',
 *     'accent' => '#333333',
 *     'consumer_key'     => 'YOUR_CONSUMER_KEY',
 *     'consumer_secret'  => 'YOUR_CONSUMER_SECRET',
 *     'access_token_url' => 'https://www.instapaper.com/api/1/oauth/access_token',
 *     'username_label'   => 'Instapaper email',   // optional
 * );
 * -------------------------------------------------------------------------
 *
 * FLOW C — "oauth1_3legged": standard OAuth 1.0a, request token + a real
 *   consent screen + verifier. For OAuth1 providers that do NOT support
 *   xAuth (e.g. Tumblr) and so cannot use Flow B — this is the fallback for
 *   "OAuth1, but the provider insists on a browser redirect". No refresh;
 *   OAuth1 access tokens don't expire.
 *
 *   Register https://oauth.wosa.link/callback.php as the callback URL in
 *   the provider's own console, same as an OAuth2 app - don't assume OAuth1
 *   providers accept a dynamically-supplied oauth_callback just because the
 *   spec allows it. Tumblr, notably, does not (confirmed against the live
 *   API): it rejects one with "Disallowed oauth_callback specified" and only
 *   honors whatever's pre-registered. Return an array shaped like this:
 *
 * return array(
 *     'flow'  => 'oauth1_3legged',
 *     'title' => 'wumblr',
 *     'accent' => '#35465c',
 *     'consumer_key'      => 'YOUR_CONSUMER_KEY',
 *     'consumer_secret'   => 'YOUR_CONSUMER_SECRET',
 *     'request_token_url' => 'https://provider.example.com/oauth/request_token',
 *     'authorize_url'     => 'https://provider.example.com/oauth/authorize',
 *     'access_token_url'  => 'https://provider.example.com/oauth/access_token',
 * );
 * -------------------------------------------------------------------------
 */
