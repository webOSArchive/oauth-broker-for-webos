<?php
/**
 * wumblr — Tumblr, OAuth 1.0a three-legged.
 *
 * Tumblr does not support xAuth, so this is the flow the request-token /
 * authorize-redirect / verifier dance added in OAuth1::requestToken() /
 * authorizeUrl() / accessToken() — not the direct credential exchange
 * oauth1_xauth uses for providers like Instapaper that still support it.
 *
 * The consumer_key/secret here MUST be the same consumer the wumblr client
 * uses to sign its own Tumblr API requests after sign-in (posts, likes,
 * reblogs, …) — this only brokers the login, not the ongoing API calls.
 *
 * Setup:
 *   1. Register an app at https://www.tumblr.com/oauth/apps to get a
 *      consumer key/secret pair.
 *   2. Set that app's default callback URL to
 *      https://oauth.wosa.link/callback.php — Tumblr rejects a dynamically
 *      supplied oauth_callback ("Disallowed oauth_callback specified",
 *      confirmed against the live API), so it has to be pre-registered here
 *      exactly like the OAuth2 providers' redirect URIs, not supplied at
 *      request-token time the way the generic OAuth 1.0a spec allows for.
 *   3. Copy this file to apps/wumblr/config.php and paste the key/secret in.
 */
return array(
    'flow'   => 'oauth1_3legged',
    'title'  => 'wumblr',
    'accent' => '#35465c',

    'consumer_key'      => 'YOUR_TUMBLR_CONSUMER_KEY',
    'consumer_secret'   => 'YOUR_TUMBLR_CONSUMER_SECRET',
    'request_token_url' => 'https://www.tumblr.com/oauth/request_token',
    'authorize_url'     => 'https://www.tumblr.com/oauth/authorize',
    'access_token_url'  => 'https://www.tumblr.com/oauth/access_token',
);
