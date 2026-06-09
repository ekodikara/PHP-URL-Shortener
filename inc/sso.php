<?php
/*
 * Snip — Enterprise SSO (OIDC + SAML 2.0). Env-gated: each provider is active
 * only when its config is present. SSO-provisioned users are auto-created and
 * placed on the Enterprise plan.
 */

function oidc_enabled()
{
    return OIDC_CLIENT_ID !== '' && OIDC_CLIENT_SECRET !== ''
        && (OIDC_ISSUER !== '' || OIDC_AUTH_URL !== '');
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

/** Find-or-create an SSO user by email; returns the user id. */
function sso_provision(PDO $pdo, $email)
{
    $email = strtolower(trim((string) $email));
    $stmt = $pdo->prepare('SELECT id, blocked FROM users WHERE email = ?');
    $stmt->execute(array($email));
    $row = $stmt->fetch();
    if ($row) {
        if (!empty($row['blocked'])) {
            return null;
        }
        return (int) $row['id'];
    }
    // Auto-provision: random password (SSO is the only login path), Enterprise plan.
    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, plan, created) VALUES (?, ?, ?, ?)');
    $ins->execute(array($email, $hash, 'enterprise', time()));
    return (int) $pdo->lastInsertId();
}

// --- OIDC --------------------------------------------------------------------

function oidc_redirect_uri()
{
    return rtrim(BASE_HREF, '/') . '/sso?provider=oidc&action=callback';
}

function oidc_endpoints()
{
    if (OIDC_AUTH_URL !== '') {
        return array('auth' => OIDC_AUTH_URL, 'token' => OIDC_TOKEN_URL, 'userinfo' => OIDC_USERINFO_URL);
    }
    $disc = @file_get_contents(rtrim(OIDC_ISSUER, '/') . '/.well-known/openid-configuration');
    $d = json_decode((string) $disc, true) ?: array();
    return array(
        'auth'     => isset($d['authorization_endpoint']) ? $d['authorization_endpoint'] : '',
        'token'    => isset($d['token_endpoint']) ? $d['token_endpoint'] : '',
        'userinfo' => isset($d['userinfo_endpoint']) ? $d['userinfo_endpoint'] : '',
    );
}

function oidc_authorize_url()
{
    $state = bin2hex(random_bytes(16));
    $_SESSION['oidc_state'] = $state;
    $ep = oidc_endpoints();
    return $ep['auth'] . '?' . http_build_query(array(
        'client_id'     => OIDC_CLIENT_ID,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'redirect_uri'  => oidc_redirect_uri(),
        'state'         => $state,
    ));
}

/** Handle the OIDC callback. Returns [user_id, null] or [null, error]. */
function oidc_handle_callback(PDO $pdo)
{
    $state = isset($_GET['state']) ? $_GET['state'] : '';
    if ($state === '' || !isset($_SESSION['oidc_state']) || !hash_equals($_SESSION['oidc_state'], $state)) {
        return array(null, 'Invalid SSO state — please retry.');
    }
    unset($_SESSION['oidc_state']);
    $code = isset($_GET['code']) ? $_GET['code'] : '';
    if ($code === '') {
        return array(null, 'No authorization code returned.');
    }
    $ep = oidc_endpoints();

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
    if (empty($tok['access_token'])) {
        return array(null, 'Token exchange failed.');
    }

    // Fetch the user's email from userinfo.
    $ch = curl_init($ep['userinfo']);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $tok['access_token']),
    ));
    $ui = json_decode((string) curl_exec($ch), true) ?: array();
    curl_close($ch);
    $email = isset($ui['email']) ? $ui['email'] : '';
    if ($email === '') {
        return array(null, 'The identity provider did not return an email.');
    }
    $uid = sso_provision($pdo, $email);
    return $uid ? array($uid, null) : array(null, 'This account is suspended.');
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
    );
}

/** Build a SAML Auth object (requires the onelogin library + config). */
function saml_auth()
{
    return new OneLogin\Saml2\Auth(saml_settings());
}
