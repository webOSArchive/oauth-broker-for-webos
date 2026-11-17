<?php
/**
 * Microsoft 365 — OAuth 2.0 authorization-code, for Exchange ActiveSync
 * (outlook.office365.com): mail, calendar and contacts on Palm's own
 * mojomail-eas, which sends the access token as a Bearer.
 *
 * Work and school accounts only. Microsoft refuses this scope for a personal
 * account (Outlook.com), and only after the person has signed in: the code
 * exchange answers invalid_scope (AADSTS70011). The broker then signs the same
 * person in with apps/microsoft (IMAP and SMTP, mail only) in the same browser
 * visit, and the device's code is answered with those tokens plus
 * `"broker_app":"microsoft"`. A device that sees broker_app makes the account
 * as that app's kind and refreshes through /microsoft/refresh.
 *
 * Setup: the same Entra app registration and secret as apps/microsoft (see
 * that file), with one more delegated permission on "Office 365 Exchange
 * Online": EAS.AccessAsUser.All. Copy this file to
 * apps/microsoft365/config.php and paste in the secret. apps/microsoft must be
 * configured too, or personal accounts get an error instead of the fallback.
 */
return array(
    'flow'   => 'oauth2_authcode',
    'title'  => 'Microsoft Outlook',
    'accent' => '#0078d4',

    'client_id'     => '95d7ba4d-1ae2-44d2-a8ef-3b06a31345f6',
    'client_secret' => 'YOUR_CLIENT_SECRET',

    'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
    'token_url'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',

    'scope' => 'https://outlook.office.com/EAS.AccessAsUser.All offline_access openid email',
    'scope_on_token' => true,

    'authorize_extra' => array('prompt' => 'select_account'),

    // An account refused this scope signs in with apps/microsoft instead.
    // invalid_scope always counts as refused; fallback_on adds strings looked
    // for in the provider's error_description. domain_hint=consumers takes the
    // second round straight to the personal-account sign-in, without asking
    // the person to pick their account again.
    'fallback_app'   => 'microsoft',
    'fallback_on'    => array('AADSTS70011'),
    'fallback_extra' => array('domain_hint' => 'consumers'),
);
