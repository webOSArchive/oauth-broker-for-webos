<?php
/**
 * Google — OAuth 2.0 authorization-code, for mail (IMAP and SMTP with XOAUTH2).
 *
 * Used by webOS CE's mail sign-in and by Lunacy's broker sign-in: the device
 * shows the code, the person signs in to Google on another machine, and
 * Palm's own mail services log in to imap.gmail.com and smtp.gmail.com with
 * the access token.
 *
 * Setup:
 *   1. Google Cloud console -> the project that already holds the "webOS
 *      Email" consent screen -> Credentials -> create an OAuth client of type
 *      "Web application". (The Desktop client Lunacy signs in with on the
 *      device can't be used here: it has no redirect to a server.)
 *   2. Authorized redirect URI:  https://oauth.wosa.link/callback.php
 *   3. Copy this file to apps/google/config.php and paste in the Web client's
 *      id and secret.
 *
 * https://mail.google.com/ is a restricted scope. While the project is
 * unverified it works for up to 100 users, who see Google's "unverified app"
 * warning first. Google's consent page lets a person untick the mail scope;
 * the device must check the granted `scope` for it and say so.
 *
 * access_type=offline with prompt=consent is what makes Google hand out a
 * refresh token every time, not only on an account's first sign-in. Google
 * keeps the same refresh token across refreshes (the reply has none): the
 * device keeps the one it has.
 */
return array(
    'flow'   => 'oauth2_authcode',
    'title'  => 'Google',
    'accent' => '#1a73e8',

    'client_id'     => 'YOUR_WEB_CLIENT_ID.apps.googleusercontent.com',
    'client_secret' => 'YOUR_WEB_CLIENT_SECRET',

    'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_url'     => 'https://oauth2.googleapis.com/token',

    // openid + email put the account's address in the id_token, which is
    // passed to the device with the tokens.
    'scope' => 'https://mail.google.com/ openid email',

    'authorize_extra' => array('access_type' => 'offline', 'prompt' => 'consent'),
);
