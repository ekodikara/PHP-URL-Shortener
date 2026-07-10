<?php
/*
 * Snip — Enterprise SSO (OIDC + SAML 2.0). Env-gated.
 *
 * Security model:
 *  - OIDC: the cryptographically-signed id_token is the authoritative identity.
 *    We validate signature (JWKS), iss, aud, exp and nonce, and require
 *    email_verified. The access-token/userinfo path is NOT trusted for identity.
 *  - SAML: assertions+messages must be signed (saml_settings security block) and
 *    bound to our AuthnRequest (InResponseTo).
 *  - Provisioning matches on (provider, subject); it NEVER silently logs into a
 *    pre-existing password account that merely shares the email.
 */

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

function oidc_enabled()
{
    return OIDC_CLIENT_ID !== '' && OIDC_CLIENT_SECRET !== ''
        && (OIDC_ISSUER !== '' || OIDC_AUTH_URL !== '')
        && class_exists('phpseclib3\\Crypt\\RSA');
}

/** base64url-decode. */
function oidc_b64url($s)
{
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/**
 * Verify an RS256 id_token against a JWKS and return its claims (assoc array).
 * Throws on any signature/format failure. Does NOT check iss/aud/nonce/email
 * (the caller does that); DOES enforce exp/nbf/iat with leeway.
 */
function oidc_verify_jwt($idToken, array $jwks)
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        throw new \RuntimeException('malformed JWT');
    }
    list($h64, $p64, $s64) = $parts;
    $header = json_decode(oidc_b64url($h64), true);
    $payload = json_decode(oidc_b64url($p64), true);
    $sig = oidc_b64url($s64);
    if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'RS256') {
        throw new \RuntimeException('unsupported or malformed token');
    }

    // Pick the JWK by kid (or the sole RSA key).
    $jwk = null;
    foreach (($jwks['keys'] ?? array()) as $k) {
        if (($k['kty'] ?? '') !== 'RSA') { continue; }
        if (!isset($header['kid']) || ($k['kid'] ?? null) === $header['kid']) { $jwk = $k; break; }
    }
    if (!$jwk) {
        throw new \RuntimeException('no matching signing key');
    }

    $key = PublicKeyLoader::load(json_encode($jwk));
    if (!($key instanceof RSA)) {
        throw new \RuntimeException('non-RSA key');
    }
    $ok = $key->withHash('sha256')->withPadding(RSA::SIGNATURE_PKCS1)->verify($h64 . '.' . $p64, $sig);
    if (!$ok) {
        throw new \RuntimeException('signature verification failed');
    }

    $now = time();
    $leeway = 60;
    if (isset($payload['exp']) && $now >= ((int) $payload['exp'] + $leeway)) {
        throw new \RuntimeException('token expired');
    }
    if (isset($payload['nbf']) && $now < ((int) $payload['nbf'] - $leeway)) {
        throw new \RuntimeException('token not yet valid');
    }
    if (isset($payload['iat']) && $now < ((int) $payload['iat'] - $leeway)) {
        throw new \RuntimeException('token issued in the future');
    }
    return $payload;
}

function saml_enabled()
{
    return SAML_IDP_ENTITY_ID !== '' && SAML_IDP_SSO_URL !== '' && SAML_IDP_CERT !== ''
        && class_exists('OneLogin\\Saml2\\Auth');
}

function sso_enabled()
{
    return oidc_enabled() || saml_enabled();
}

/**
 * Find-or-create an SSO user, matching on (provider, subject). Returns
 * [user_id, null] or [null, error]. Never takes over a password account.
 */
function sso_provision(PDO $pdo, $email, $provider, $subject)
{
    $email = strtolower(trim((string) $email));
    $subject = (string) $subject;
    if ($email === '' || $subject === '') {
        return array(null, 'Your identity provider did not return the required claims.');
    }

    // Optional email-domain allowlist.
    if (SSO_ALLOWED_DOMAINS !== '') {
        $dom = substr(strrchr($email, '@'), 1);
        $allowed = array_filter(array_map('trim', explode(',', strtolower(SSO_ALLOWED_DOMAINS))));
        if (!in_array($dom, $allowed, true)) {
            return array(null, 'Your email domain is not permitted for SSO.');
        }
    }

    // 1) Match by stable provider+subject.
    $stmt = $pdo->prepare('SELECT id, blocked FROM users WHERE auth_provider = ? AND sso_subject = ?');
    $stmt->execute(array($provider, $subject));
    $row = $stmt->fetch();
    if ($row) {
        return !empty($row['blocked']) ? array(null, 'This account has been suspended.') : array((int) $row['id'], null);
    }

    // 2) Existing account with this email?
    $stmt = $pdo->prepare('SELECT id, auth_provider, sso_subject, blocked FROM users WHERE email = ?');
    $stmt->execute(array($email));
    $ex = $stmt->fetch();
    if ($ex) {
        if (!empty($ex['blocked'])) {
            return array(null, 'This account has been suspended.');
        }
        // Same provider, subject not yet recorded → first SSO login of an
        // SSO-provisioned account: link the subject.
        if ($ex['auth_provider'] === $provider && empty($ex['sso_subject'])) {
            $pdo->prepare('UPDATE users SET sso_subject = ? WHERE id = ?')->execute(array($subject, $ex['id']));
            return array((int) $ex['id'], null);
        }
        // A password (or other-provider) account — refuse silent takeover.
        return array(null, 'An account with this email already exists. Sign in with your password, then link SSO from account settings.');
    }

    // 3) Auto-provision a fresh SSO account.
    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $plan = in_array(SSO_DEFAULT_PLAN, array('free', 'pro', 'premium', 'enterprise'), true) ? SSO_DEFAULT_PLAN : 'enterprise';
    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, plan, auth_provider, sso_subject, created) VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute(array($email, $hash, $plan, $provider, $subject, time()));
    return array((int) $pdo->lastInsertId(), null);
}

// --- OIDC --------------------------------------------------------------------

function oidc_redirect_uri()
{
    return rtrim(BASE_HREF, '/') . '/sso?provider=oidc&action=callback';
}

function oidc_discovery()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = array();
    if (OIDC_ISSUER !== '') {
        $ch = curl_init(rtrim(OIDC_ISSUER, '/') . '/.well-known/openid-configuration');
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8));
        $cache = json_decode((string) curl_exec($ch), true) ?: array();
        curl_close($ch);
    }
    return $cache;
}

function oidc_endpoints()
{
    $d = oidc_discovery();
    return array(
        'auth'     => OIDC_AUTH_URL !== '' ? OIDC_AUTH_URL : (isset($d['authorization_endpoint']) ? $d['authorization_endpoint'] : ''),
        'token'    => OIDC_TOKEN_URL !== '' ? OIDC_TOKEN_URL : (isset($d['token_endpoint']) ? $d['token_endpoint'] : ''),
        'userinfo' => OIDC_USERINFO_URL !== '' ? OIDC_USERINFO_URL : (isset($d['userinfo_endpoint']) ? $d['userinfo_endpoint'] : ''),
        'jwks'     => OIDC_JWKS_URL !== '' ? OIDC_JWKS_URL : (isset($d['jwks_uri']) ? $d['jwks_uri'] : ''),
        'issuer'   => isset($d['issuer']) ? $d['issuer'] : OIDC_ISSUER,
    );
}

function oidc_authorize_url()
{
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['oidc_state'] = $state;
    $_SESSION['oidc_nonce'] = $nonce;
    $ep = oidc_endpoints();
    return $ep['auth'] . '?' . http_build_query(array(
        'client_id'     => OIDC_CLIENT_ID,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'redirect_uri'  => oidc_redirect_uri(),
        'state'         => $state,
        'nonce'         => $nonce,
    ));
}

/** Handle the OIDC callback. Returns [user_id, null] or [null, error]. */
function oidc_handle_callback(PDO $pdo)
{
    $state = isset($_GET['state']) ? $_GET['state'] : '';
    if ($state === '' || empty($_SESSION['oidc_state']) || !hash_equals($_SESSION['oidc_state'], $state)) {
        return array(null, 'Invalid SSO state — please retry.');
    }
    $nonce = isset($_SESSION['oidc_nonce']) ? $_SESSION['oidc_nonce'] : '';
    unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce']);

    $code = isset($_GET['code']) ? $_GET['code'] : '';
    if ($code === '') {
        return array(null, 'No authorization code returned.');
    }
    $ep = oidc_endpoints();
    if ($ep['token'] === '' || $ep['jwks'] === '') {
        return array(null, 'SSO is misconfigured (no token/JWKS endpoint).');
    }

    // Exchange the code for tokens.
    $ch = curl_init($ep['token']);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => oidc_redirect_uri(),
            'client_id'     => OIDC_CLIENT_ID,
            'client_secret' => OIDC_CLIENT_SECRET,
        )),
    ));
    $tok = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (empty($tok['id_token'])) {
        return array(null, 'No ID token returned by the identity provider.');
    }

    // Validate the id_token: RS256 signature (JWKS) + exp/nbf/iat.
    try {
        $ch = curl_init($ep['jwks']);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8));
        $jwks = json_decode((string) curl_exec($ch), true) ?: array();
        curl_close($ch);
        $claims = oidc_verify_jwt($tok['id_token'], $jwks);
    } catch (\Throwable $e) {
        error_log('OIDC id_token validation failed: ' . $e->getMessage());
        return array(null, 'Could not verify the identity token.');
    }

    // iss / aud / nonce checks.
    $aud = isset($claims['aud']) ? (is_array($claims['aud']) ? $claims['aud'] : array($claims['aud'])) : array();
    if ($ep['issuer'] !== '' && (($claims['iss'] ?? '') !== $ep['issuer'])) {
        return array(null, 'ID token issuer mismatch.');
    }
    if (!in_array(OIDC_CLIENT_ID, $aud, true)) {
        return array(null, 'ID token audience mismatch.');
    }
    if ($nonce !== '' && (!isset($claims['nonce']) || !hash_equals($nonce, (string) $claims['nonce']))) {
        return array(null, 'ID token nonce mismatch.');
    }
    $email = isset($claims['email']) ? (string) $claims['email'] : '';
    $verified = isset($claims['email_verified']) ? filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN) : false;
    if ($email === '' || !$verified) {
        return array(null, 'Your identity provider did not return a verified email.');
    }
    $sub = isset($claims['sub']) ? (string) $claims['sub'] : '';
    return sso_provision($pdo, $email, 'oidc', $sub);
}

// --- SAML --------------------------------------------------------------------

function saml_settings()
{
    return array(
        'strict' => true,
        'sp' => array(
            'entityId' => rtrim(BASE_HREF, '/') . '/sso?provider=saml&action=metadata',
            'assertionConsumerService' => array(
                'url' => rtrim(BASE_HREF, '/') . '/sso?provider=saml&action=acs',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            ),
        ),
        'idp' => array(
            'entityId' => SAML_IDP_ENTITY_ID,
            'singleSignOnService' => array(
                'url' => SAML_IDP_SSO_URL,
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ),
            'x509cert' => SAML_IDP_CERT,
        ),
        // Require signed assertions + messages and reject unsolicited responses.
        'security' => array(
            'wantMessagesSigned'   => true,
            'wantAssertionsSigned' => true,
            'wantXMLValidation'    => true,
            'rejectUnsolicitedResponsesWithInResponseTo' => true,
            'signatureAlgorithm'   => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        ),
    );
}

function saml_auth()
{
    return new OneLogin\Saml2\Auth(saml_settings());
}
