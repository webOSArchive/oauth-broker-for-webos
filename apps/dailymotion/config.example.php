<?php
/**
 * Dailymotion — OAuth 2.0 authorization-code (legacy login system).
 *
 * Standard oauth2_authcode shape, same as apps/ebay or apps/box - no broker
 * code changes needed at all. get-code.php / check-code.php / refresh.php
 * already handle this generically via ?app=dailymotion.
 *
 * Powers My Videos, Favorites, and Subscriptions (my-videos.php,
 * favorites.php, subscriptions.php) - all real per-user data via
 * Dailymotion's OLD (pre-Studio) OAuth system, since API v2 has no
 * end-user login and no favorites/subscriptions data model at all.
 * featured.php (Home) needs none of this - unauthenticated public API.
 *
 * https://www.dailymotion.com/oauth/authorize and
 * https://api.dailymotion.com/oauth/token - undocumented/unsupported by
 * current Dailymotion docs, confirmed live during development. The same
 * client_id/client_secret as the Studio API key works here.
 */
return array(
    'flow'  => 'oauth2_authcode',
    'title' => 'Dailymotion',

    'client_id'     => '<CLIENT-ID>',
    'client_secret' => '<CLIENT-SECRET>',

    'authorize_url' => 'https://www.dailymotion.com/oauth/authorize',
    'token_url'     => 'https://api.dailymotion.com/oauth/token',

    // Best-effort - NOT confirmed against a real consent screen. See
    // config.example.php's comment for the reasoning; adjust here if a
    // specific endpoint 403s after a real login.
    'scope' => 'read manage_favorites manage_subscriptions',
);
