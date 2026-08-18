<?php
/**
 * OAuth1 — minimal OAuth 1.0a HMAC-SHA1 client for the server side.
 *
 * Two exchange styles, both ending in an access token/secret:
 *   - xAuth() for providers (e.g. Instapaper) that accept a username +
 *     password directly, no browser redirect.
 *   - requestToken() / authorizeUrl() / accessToken() for providers that
 *     require the standard three-legged dance with a real consent screen
 *     (e.g. Tumblr, which does not support xAuth) — a temporary request
 *     token, a redirect to the provider to authorize it, then an exchange
 *     for the final access token using the verifier the provider hands back.
 *
 * Either way, all signing happens here on the broker so the consumer secret
 * never reaches the legacy device.
 *
 * xAuth() generalized from webOSArchive/instapaper-auth's InstapaperAuth.php.
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

    /**
     * Step 1 of three-legged OAuth1: obtain a temporary request token.
     *
     * oauth_callback is itself an oauth_* protocol parameter, so it is signed
     * and sent via the Authorization header like the rest — never as a query
     * string or body param. It carries the app + device code as its own query
     * string; the provider is required by spec to preserve that when it
     * appends oauth_token/oauth_verifier and redirects the user back, which
     * is what lets callback.php identify the pending login without a
     * broker-invented state blob (OAuth1 has no state parameter).
     *
     * @param string $url         Provider request-token endpoint.
     * @param string $callbackUrl Where the provider redirects after the user
     *                            approves — normally callbackUrl() with
     *                            ?app=&code= appended.
     * @return array|false        ['oauth_token','oauth_token_secret', ...] or false.
     */
    public function requestToken($url, $callbackUrl) {
        $oauthParams = $this->baseParams();
        $oauthParams['oauth_callback'] = $callbackUrl;
        $oauthParams['oauth_signature'] = $this->sign('POST', $url, $oauthParams, '');

        $response = $this->httpPost($url, '', $this->authHeader($oauthParams));
        if ($response === false) {
            return false;
        }

        $result = array();
        parse_str($response, $result);
        if (!isset($result['oauth_token'], $result['oauth_token_secret'])) {
            error_log('OAuth1 requestToken: unexpected response: ' . $response);
            return false;
        }
        return $result;
    }

    /** Step 2: where to send the user's browser to approve the request token. */
    public function authorizeUrl($url, $requestToken) {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $sep . 'oauth_token=' . rawurlencode($requestToken);
    }

    /**
     * Step 3: exchange the approved request token + the provider's verifier
     * for the final, long-lived access token/secret.
     *
     * @param string $url          Provider access-token endpoint.
     * @param string $requestToken The token from requestToken(), now approved.
     * @param string $requestSecret Its paired secret — needed to sign this
     *                              call, per spec, even though the token
     *                              itself is about to be discarded.
     * @param string $verifier      oauth_verifier from the provider's redirect.
     * @return array|false          ['oauth_token','oauth_token_secret', ...] or false.
     */
    public function accessToken($url, $requestToken, $requestSecret, $verifier) {
        $oauthParams = $this->baseParams($requestToken);
        $oauthParams['oauth_verifier'] = $verifier;
        $oauthParams['oauth_signature'] = $this->sign('POST', $url, $oauthParams, $requestSecret);

        $response = $this->httpPost($url, '', $this->authHeader($oauthParams));
        if ($response === false) {
            return false;
        }

        $result = array();
        parse_str($response, $result);
        if (!isset($result['oauth_token'], $result['oauth_token_secret'])) {
            error_log('OAuth1 accessToken: unexpected response: ' . $response);
            return false;
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
