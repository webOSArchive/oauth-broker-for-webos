<?php
/**
 * applemusic — Apple Music (MusicKit), for Jukie and any other legacy-webOS
 * Apple Music client.
 *
 * Apple Music has no OAuth2 token endpoint. The one supported way to get a
 * per-user Music User Token is MusicKit JS's authorize(), which has to run in
 * a real modern browser. So this flow type (`apple_musickit`) has no redirect
 * to a provider and no code exchange: start.php serves a page that loads
 * MusicKit JS with the developer token below, the user signs in with their
 * Apple ID there, and the page posts the resulting Music User Token to
 * musickit-callback.php, which parks it against the device's code exactly like
 * every other flow. The device polls check-code.php and receives it once as
 * `access_token` (plus `music_user_token` and `storefront`).
 *
 * The developer token never reaches the device from here - it only goes to the
 * browser doing the sign-in, which is how every MusicKit JS web app works.
 *
 * Setup:
 *   1. Apple Developer account -> Certificates, Identifiers & Profiles ->
 *      Keys: create a key with "Media Services (MusicKit...)" enabled and
 *      download the .p8. Note the Key ID and your Team ID.
 *   2. Sign an ES256 JWT with it (kid = Key ID, iss = Team ID, exp <= 6 months).
 *      Do NOT add an `origin` claim unless it lists this broker's origin -
 *      MusicKit JS rejects a token whose origin doesn't match the page.
 *   3. Copy this file to apps/applemusic/config.php and paste the JWT in.
 *      Re-paste a fresh one before it expires (about every 6 months).
 */
return array(
    'flow'   => 'apple_musickit',
    'title'  => 'Apple Music',
    'accent' => '#fa243c',

    'developer_token' => 'PASTE_YOUR_MUSICKIT_DEVELOPER_TOKEN_JWT_HERE',

    // Shown by MusicKit JS / Apple's consent sheet.
    'app_name'  => 'Jukie',
    'app_build' => '1.0',
);
