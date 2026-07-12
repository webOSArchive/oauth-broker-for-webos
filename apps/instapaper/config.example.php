<?php
/**
 * Instapaper — OAuth 1.0a xAuth (direct credential exchange).
 *
 * This is the same flow the standalone webOSArchive/instapaper-auth service
 * uses, expressed as a broker app so one deployment can serve it alongside
 * OAuth2 apps like Box. Instapaper tokens don't expire, so there's no refresh.
 *
 * Setup:
 *   1. Request a consumer key/secret: https://www.instapaper.com/main/request_oauth_consumer_token
 *   2. Copy this file to apps/instapaper/config.php and paste them in.
 */
return array(
    'flow'   => 'oauth1_xauth',
    'title'  => 'Instapaper',
    'accent' => '#333333',

    'consumer_key'     => 'YOUR_INSTAPAPER_CONSUMER_KEY',
    'consumer_secret'  => 'YOUR_INSTAPAPER_CONSUMER_SECRET',
    'access_token_url' => 'https://www.instapaper.com/api/1/oauth/access_token',
    'username_label'   => 'Instapaper email',
);
