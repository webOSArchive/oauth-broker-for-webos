<?php
/**
 * OAuth1 — minimal OAuth 1.0a HMAC-SHA1 client for the server side.
 *
 * Used for "xAuth" style providers (e.g. Instapaper) where a username +
 * password are exchanged directly for a long-lived access token/secret.
 * All signing happens here on the broker so the consumer secret never
 * reaches the legacy device.
 *
 * Generalized from webOSArchive/instapaper-auth's InstapaperAuth.php.
 */
class OAuth1 {

    private $consumerKey;
    private $consumerSecret;

    public function __construct($consumerKey, $consumerSecret) {
        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
    }

    /**
     * xAuth: exchange username + password for an access token.
     *
     * @param string $url         Provider access-token endpoint.
     * @param string $username
     * @param string $password
     * @return array|false        ['oauth_token','oauth_token_secret', ...] or false.
     */
    public function xAuth($url, $username, $password) {
        $bodyParams = array(
            'x_auth_mode'     => 'client_auth',
            'x_auth_password' => $password,
            'x_auth_username' => $username,
        );
        $oauthParams = $this->baseParams();
        $allParams   = array_merge($oauthParams, $bodyParams);
        $oauthParams['oauth_signature'] = $this->sign('POST', $url, $allParams, '');

        $response = $this->httpPost(
            $url,
            http_build_query($bodyParams),
            $this->authHeader($oauthParams)
        );
        if ($response === false) {
            return false;
        }

        $result = array();
        parse_str($response, $result);
        if (!isset($result['oauth_token'], $result['oauth_token_secret'])) {
            error_log('OAuth1 xAuth: unexpected response: ' . $response);
            return false;
        }
        if (!isset($result['username'])) {
            $result['username'] = $username;
        }
        return $result;
    }

    // ---- signing helpers ----

    private function baseParams($token = '') {
        $params = array(
            'oauth_consumer_key'     => $this->consumerKey,
            'oauth_nonce'            => md5(uniqid(mt_rand(), true)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_version'          => '1.0',
        );
        if ($token !== '') {
            $params['oauth_token'] = $token;
        }
        return $params;
    }

    private function sign($method, $url, $params, $tokenSecret) {
        ksort($params);
        $parts = array();
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $base = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $parts));
        $key  = rawurlencode($this->consumerSecret) . '&' . rawurlencode($tokenSecret);
        return base64_encode(hash_hmac('sha1', $base, $key, true));
    }

    private function authHeader($params) {
        $parts = array();
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode($k) . '="' . rawurlencode($v) . '"';
        }
        return 'OAuth ' . implode(', ', $parts);
    }

    private function httpPost($url, $body, $authHeader) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: ' . $authHeader,
            'Content-Type: application/x-www-form-urlencoded',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            error_log("OAuth1 httpPost: HTTP $httpCode from $url — $response");
            return false;
        }
        return $response;
    }
}
