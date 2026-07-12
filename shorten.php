<?php
/*
 * Snip — create a short link (AJAX or form POST). Requires login.
 */
require __DIR__ . '/inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('/');
}

$user = current_user();
if (!$user) {
    if (wants_json()) {
        json_response(array('error' => 'Please log in to create links.'), 401);
    }
    redirect_to('login');
}
// Suspended accounts cannot create links.
if (!empty($user['blocked'])) {
    log_security_event($pdo, 'blocked_action', 'shorten', $user['id']);
    if (wants_json()) {
        json_response(array('error' => 'Your account has been suspended.'), 403);
    }
    logout_user();
    redirect_to('login');
}

csrf_check();

// New links require a verified email (anti-bot / anti-abuse). Existing links
// keep redirecting; this only gates creating MORE.
if (REQUIRE_EMAIL_VERIFICATION && empty($user['email_verified'])) {
    $msg = 'Please verify your email to start creating links — check your inbox, or resend from your dashboard.';
    if (wants_json()) {
        json_response(array('error' => $msg, 'verify' => true), 403);
    }
    set_flash('error', $msg);
    redirect_to('dashboard');
}

// Suspicious clients must solve a captcha before creating links.
if (($gate = captcha_gate($pdo, 'shorten')) !== '') {
    $msg = $gate === 'captcha'
        ? 'Please complete the verification to continue.'
        : 'Suspicious activity detected. Please try again later.';
    if (wants_json()) {
        json_response(array('error' => $msg, 'captcha' => ($gate === 'captcha')), 403);
    }
    set_flash('error', $msg);
    redirect_to('dashboard');
}

// If created while browsing a verified custom domain, scope the link to it.
$cur_domain = current_domain($pdo);
$domain_id = ($cur_domain && (int) $cur_domain['user_id'] === (int) $user['id']) ? (int) $cur_domain['id'] : null;

list($row, $error) = create_short_url(
    $pdo,
    $user,
    isset($_POST['longurl']) ? $_POST['longurl'] : '',
    isset($_POST['slug']) ? $_POST['slug'] : '',
    $domain_id
);

if ($error) {
    if (wants_json()) {
        json_response(array('error' => $error), 422);
    }
    set_flash('error', $error);
    redirect_to('dashboard');
}

if (wants_json()) {
    json_response(array(
        'short_url' => $row['short_url'],
        'code'      => $row['code'],
        'reload'    => true,
    ));
}

set_flash('success', 'Short link created: ' . $row['short_url']);
redirect_to('dashboard');
