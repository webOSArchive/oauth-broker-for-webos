<?php
/**
 * eBay — OAuth 2.0 authorization-code.
 *
 * Setup:
 *   1. eBay Developer Program account -> create/reuse a keyset (App ID / Cert ID / Dev ID).
 *   2. Create a RuName whose "Auth accepted URL" is https://oauth.wosa.link/callback.php
 *      (RuName creation is the same mechanism Auth'n'Auth and OAuth both use).
 *   3. Copy this file to apps/ebay/config.php, paste in the App ID / Cert ID, and set
 *      authorize_extra.redirect_uri below to the RuName string from step 2.
 *
 * eBay is unusual among OAuth2 providers this broker talks to: its redirect_uri parameter
 * must be that opaque RuName string, not the literal callback URL — see the top-level
 * README's "redirect_uri override" section for why lib/OAuth2.php needed a small change to
 * support this.
 *
 * eBay's traditional APIs (Trading API — My eBay, watchlist, bidding, messaging) accept this
 * same OAuth user token directly via an X-EBAY-API-IAF-TOKEN header, so the base scope below
 * covers those in addition to the REST Buy APIs; no separate Auth'n'Auth flow is needed.
 */
return array(
    'flow'   => 'oauth2_authcode',
    'title'  => 'eBay',
    'accent' => '#e53238',

    'client_id'     => 'YOUR_EBAY_APP_ID',
    'client_secret' => 'YOUR_EBAY_CERT_ID',

    'authorize_url' => 'https://auth.ebay.com/oauth2/authorize',
    'token_url'     => 'https://api.ebay.com/identity/v1/oauth2/token',

    // Base scope covers the Trading-API-over-OAuth bridge (traditional APIs don't scope-check
    // the token at all) and the Browse API. buy.order is needed for Order API checkout.
    'scope' => 'https://api.ebay.com/oauth/api_scope '
             . 'https://api.ebay.com/oauth/api_scope/buy.order',

    'authorize_extra' => array(
        // The RuName from step 2 above — NOT a literal URL.
        'redirect_uri' => 'YOUR_RUNAME',
    ),

    // Shared secret for the Marketplace Account Deletion notification
    // endpoint (apps/ebay/deletion-notification.php) — must match the
    // "Verification token" field entered for this keyset in the eBay dev
    // portal's Alerts & Notifications tab. Unrelated to OAuth; kept here
    // rather than hardcoded in the endpoint script so it's never committed.
    'deletion_verification_token' => 'YOUR_VERIFICATION_TOKEN',
);
