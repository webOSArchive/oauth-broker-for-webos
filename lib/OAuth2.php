<?php
/**
 * OAuth2 — server-side authorization-code client.
 *
 * The broker performs every network call to the provider (building the
 * authorize URL, exchanging the code, refreshing) so that:
 *   1. the client secret never leaves the server, and
 *   2. the modern TLS handshake happens here, not on the legacy device
 *      (whose 2009-era TLS stack cannot reach today's OAuth endpoints).
 *
 * Standard RFC 6749 authorization-code + refresh-token grants. Providers that
 * deviate (extra params, non-standard field names) are accommodated through
 * the per-app config (see apps/_example/config.php).
 */
class OAuth2 {

    private $cfg;

    /** @param array $cfg The app config array (see apps/_example/config.php). */
    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    /**
     * Build the provider's authorize URL to send the browser to.
     *
     * @param string $redirectUri The broker callback (must match provider registration).
     * @param string $state       Opaque value returned to the callback.
     * @return string
     */
    public function authorizeUrl($redirectUri, $state) {
        $params = array(
            'client_id'     => $this->cfg['client_id'],
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'state'         => $state,
        );
        if (!empty($this->cfg['scope'])) {
            $params['scope'] = $this->cfg['scope'];
        }
        if (!empty($this->cfg['authorize_extra']) && is_array($this->cfg['authorize_extra'])) {
            $params = array_merge($params, $this->cfg['authorize_extra']);
        }
        $sep = (strpos($this->cfg['authorize_url'], '?') === false) ? '?' : '&';
        return $this->cfg['authorize_url'] . $sep . http_build_query($params);
    }

    /**
     * Exchange an authorization code for tokens.
     * @return array|false Decoded token response, or false on failure.
     */
    public function exchangeCode($code, $redirectUri) {
        return $this->tokenRequest(array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'redirect_uri'  => $redirectUri,
        ));
    }

    /**
     * Exchange a refresh token for a fresh access token.
     * @return array|false Decoded token response, or false on failure.
     */
    public function refresh($refreshToken) {
        return $this->tokenRequest(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
        ));
    }

    private function tokenRequest(array $params) {
        $ch = curl_init($this->cfg['token_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('OAuth2 tokenRequest: curl error: ' . $curlErr);
            return false;
        }
        $json = json_decode($response, true);
        if (!is_array($json)) {
            error_log('OAuth2 tokenRequest: non-JSON response (HTTP ' . $httpCode . '): ' . $response);
            return false;
        }
        if ($httpCode < 200 || $httpCode >= 300 || isset($json['error'])) {
            error_log('OAuth2 tokenRequest: provider error (HTTP ' . $httpCode . '): ' . $response);
            // Return the error body so callers can distinguish "expired refresh
            // token" (a real logout) from a transient failure.
            $json['_http'] = $httpCode;
            return $json;
        }
        $json['_http'] = $httpCode;
        return $json;
    }
}
