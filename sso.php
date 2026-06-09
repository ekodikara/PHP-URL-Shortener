<?php
/*
 * Snip — SSO entry point. Routes:
 *   /sso?provider=oidc&action=login|callback
 *   /sso?provider=saml&action=login|acs|metadata
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sso.php';

$provider = isset($_GET['provider']) ? $_GET['provider'] : '';
$action   = isset($_GET['action']) ? $_GET['action'] : 'login';

if ($provider === 'oidc') {
    if (!oidc_enabled()) {
        set_flash('error', 'OIDC SSO is not configured.');
        redirect_to('login');
    }
    if ($action === 'login') {
        redirect_to(oidc_authorize_url());
    }
    if ($action === 'callback') {
        list($uid, $err) = oidc_handle_callback($pdo);
        if ($uid) {
            log_security_event($pdo, 'sso_login', 'oidc', $uid);
            establish_session($uid);
            redirect_to('dashboard');
        }
        set_flash('error', $err);
        redirect_to('login');
    }
}

if ($provider === 'saml') {
    if (!saml_enabled()) {
        set_flash('error', 'SAML SSO is not configured.');
        redirect_to('login');
    }
    $auth = saml_auth();
    if ($action === 'metadata') {
        header('Content-Type: application/xml');
        echo OneLogin\Saml2\Metadata::builder($auth->getSettings()->getSPData());
        exit;
    }
    if ($action === 'login') {
        $auth->login(rtrim(BASE_HREF, '/') . '/dashboard');   // redirects to IdP
        exit;
    }
    if ($action === 'acs') {
        $auth->processResponse();
        if (!empty($auth->getErrors()) || !$auth->isAuthenticated()) {
            set_flash('error', 'SAML authentication failed.');
            redirect_to('login');
        }
        $attrs = $auth->getAttributes();
        $email = $auth->getNameId();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($attrs['email'][0])) {
            $email = $attrs['email'][0];
        }
        $uid = sso_provision($pdo, $email);
        if ($uid) {
            log_security_event($pdo, 'sso_login', 'saml', $uid);
            establish_session($uid);
            redirect_to('dashboard');
        }
        set_flash('error', 'This account is suspended.');
        redirect_to('login');
    }
}

set_flash('error', 'Unknown SSO request.');
redirect_to('login');
