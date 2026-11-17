<?php
/**
 * Microsoft — OAuth 2.0 authorization-code, for mail over IMAP and SMTP with
 * XOAUTH2 (outlook.office365.com:993, smtp.office365.com:587).
 *
 * This is the mail-only sign-in. It is what a personal account (Outlook.com,
 * Hotmail, Live) gets, because Microsoft refuses those the Exchange scope:
 * apps/microsoft365 names this app as its fallback_app, so most people reach
 * it through that one and never open /microsoft themselves.
 *
 * Setup (one Entra app registration serves this app and apps/microsoft365):
 *   1. Entra admin center -> App registrations -> "webOS Email"
 *      (95d7ba4d-1ae2-44d2-a8ef-3b06a31345f6; accounts in any organizational
 *      directory and personal Microsoft accounts).
 *   2. Authentication -> add the platform "Web" with the redirect URI
 *      https://oauth.wosa.link/callback.php
 *   3. Certificates & secrets -> new client secret. Note its expiry: Entra
 *      secrets last at most 24 months, and every refresh fails once it lapses.
 *   4. API permissions (delegated, on "Office 365 Exchange Online", under
 *      "APIs my organization uses"): IMAP.AccessAsUser.All, SMTP.Send; and
 *      Microsoft Graph's offline_access, openid, email.
 *   5. Copy this file to apps/microsoft/config.php and paste in the secret.
 *
 * Microsoft gives a new refresh token with each refresh; the device must keep
 * the newest. A tenant that doesn't let its users consent to an unverified app
 * answers "Need admin approval" (error=access_denied at the callback).
 */
return array(
    'flow'   => 'oauth2_authcode',
    'title'  => 'Microsoft Outlook (mail only)',
    'accent' => '#0078d4',

    'client_id'     => '95d7ba4d-1ae2-44d2-a8ef-3b06a31345f6',
    'client_secret' => 'YOUR_CLIENT_SECRET',

    'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
    'token_url'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',

    'scope' => 'https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send offline_access openid email',

    // Microsoft wants the scope said again on the code exchange and on a
    // refresh (see lib/OAuth2.php).
    'scope_on_token' => true,

    'authorize_extra' => array('prompt' => 'select_account'),
);
