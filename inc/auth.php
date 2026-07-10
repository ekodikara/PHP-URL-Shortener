<?php
/*
 * Snip — authentication: registration, login, sessions.
 */

/** Return the logged-in user row, or null. Cached per request. */
function current_user()
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    global $pdo;
    if (empty($_SESSION['uid'])) {
        return $cache = null;
    }
    $stmt = $pdo->prepare('SELECT id, email, plan, billing_interval, stripe_customer_id, stripe_subscription_id, is_admin, blocked, blocked_reason, email_verified, created FROM users WHERE id = ?');
    $stmt->execute(array($_SESSION['uid']));
    $row = $stmt->fetch();
    return $cache = ($row ?: null);
}

/** Require a logged-in, non-blocked user or bounce to login. */
function require_login()
{
    $u = current_user();
    if (!$u) {
        redirect_to('login');
    }
    if (!empty($u['blocked'])) {
        logout_user();
        set_flash('error', 'Your account has been suspended.'
            . (!empty($u['blocked_reason']) ? ' Reason: ' . $u['blocked_reason'] : ''));
        redirect_to('login');
    }
}

/** Is this user an admin (column flag or configured admin email)? */
function is_admin(array $user)
{
    if (!empty($user['is_admin'])) {
        return true;
    }
    $admins = array_filter(array_map('trim', explode(',', strtolower(ADMIN_EMAILS))));
    return in_array(strtolower($user['email']), $admins, true);
}

/** True if the user is on an active paid plan (Pro or Premium). */
function plan_is_paid(array $user)
{
    return in_array($user['plan'], array('pro', 'premium', 'enterprise'), true);
}

// --- Trial state -------------------------------------------------------------

/** True if the user is on a trial plan (key 'free'). */
function is_on_trial(array $user)
{
    $cfg = plan_config($user['plan']);
    return !empty($cfg['is_trial']);
}

/** Unix timestamp when this user's trial ends. */
function trial_ends_ts(array $user)
{
    return (int) $user['created'] + TRIAL_DAYS * 86400;
}

/** True while the trial is still running. */
function trial_active(array $user)
{
    return is_on_trial($user) && time() < trial_ends_ts($user);
}

/** True once the trial has run out. */
function trial_expired(array $user)
{
    return is_on_trial($user) && time() >= trial_ends_ts($user);
}

/** Whole days left in the trial (0 if expired or not on trial). */
function trial_days_left(array $user)
{
    if (!is_on_trial($user)) {
        return 0;
    }
    return max(0, (int) ceil((trial_ends_ts($user) - time()) / 86400));
}

/**
 * Register a new user. Returns [user_id, null] on success or [null, error].
 */
function register_user(PDO $pdo, $email, $password)
{
    $email = trim((string) $email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array(null, 'Please enter a valid email address.');
    }
    if (strlen($password) < 8) {
        return array(null, 'Password must be at least 8 characters.');
    }
    // Cap length: bcrypt silently truncates at 72 bytes, and unbounded input is a
    // hashing-DoS vector. Reject overly long passwords explicitly.
    if (strlen($password) > 200) {
        return array(null, 'Password must be at most 200 characters.');
    }

    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute(array($email));
    if ($stmt->fetch()) {
        return array(null, 'An account with that email already exists.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, plan, created) VALUES (?, ?, ?, ?)');
    $stmt->execute(array($email, $hash, 'free', time()));
    return array((int) $pdo->lastInsertId(), null);
}

/**
 * Attempt login. Returns [user_id, null] on success or [null, error].
 */
function login_user(PDO $pdo, $email, $password)
{
    $stmt = $pdo->prepare('SELECT id, password_hash, blocked, blocked_reason FROM users WHERE email = ?');
    $stmt->execute(array(trim((string) $email)));
    $row = $stmt->fetch();
    // Always run a FULL hash verify (same algo+cost as real hashes) so the
    // unknown-account path costs the same as the known-account path — no timing
    // oracle. Generated once per request from PASSWORD_DEFAULT so cost tracks it.
    static $dummy_hash = null;
    if ($dummy_hash === null) {
        $dummy_hash = password_hash('snip-timing-equalizer', PASSWORD_DEFAULT);
    }
    $hash = $row ? $row['password_hash'] : $dummy_hash;
    if (password_verify((string) $password, $hash) && $row) {
        if (!empty($row['blocked'])) {
            return array(null, 'This account has been suspended.'
                . (!empty($row['blocked_reason']) ? ' Reason: ' . $row['blocked_reason'] : ''));
        }
        return array((int) $row['id'], null);
    }
    return array(null, 'Incorrect email or password.');
}

/** Persist the logged-in user id, regenerating the session id. */
function establish_session($user_id)
{
    // Drop any pre-auth session data, then rotate the id (fixation defense).
    $_SESSION = array();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user_id;
    $_SESSION['login_at'] = time();
    $_SESSION['last_seen'] = time();
}

function logout_user()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// --- Single-use auth tokens (password reset + email verification) ------------

/** Issue a raw token for $kind ('reset'|'verify'); stores only its SHA-256. */
function issue_auth_token(PDO $pdo, $user_id, $kind, $ttl_seconds)
{
    $raw = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO auth_tokens (user_id, kind, token_hash, expires, used, created) VALUES (?, ?, ?, ?, 0, ?)')
        ->execute(array($user_id, $kind, hash('sha256', $raw), time() + $ttl_seconds, time()));
    return $raw;
}

/** Look up a valid (unused, unexpired) token WITHOUT consuming it → user_id|null. */
function peek_auth_token(PDO $pdo, $raw, $kind)
{
    $stmt = $pdo->prepare('SELECT user_id FROM auth_tokens WHERE token_hash = ? AND kind = ? AND used = 0 AND expires >= ?');
    $stmt->execute(array(hash('sha256', (string) $raw), $kind, time()));
    $row = $stmt->fetch();
    return $row ? (int) $row['user_id'] : null;
}

/** Atomically consume a valid token → user_id|null (marks it used). */
function consume_auth_token(PDO $pdo, $raw, $kind)
{
    $stmt = $pdo->prepare('UPDATE auth_tokens SET used = 1 WHERE token_hash = ? AND kind = ? AND used = 0 AND expires >= ?');
    $stmt->execute(array(hash('sha256', (string) $raw), $kind, time()));
    if ($stmt->rowCount() === 0) {
        return null;
    }
    $sel = $pdo->prepare('SELECT user_id FROM auth_tokens WHERE token_hash = ? AND kind = ?');
    $sel->execute(array(hash('sha256', (string) $raw), $kind));
    $row = $sel->fetch();
    return $row ? (int) $row['user_id'] : null;
}

/** Compose + send an email-verification link to the user. */
function send_verification_email(PDO $pdo, $user_id, $email)
{
    $raw = issue_auth_token($pdo, $user_id, 'verify', 86400); // 24h
    $link = BASE_HREF . 'verify?t=' . $raw;
    send_mail(
        $email,
        'Verify your ' . APP_NAME . ' email',
        "Welcome to " . APP_NAME . "!\n\nConfirm this email address by opening:\n" . $link
            . "\n\nThis link expires in 24 hours. If you didn't sign up, ignore this message."
    );
}

/** Compose + send a password-reset link to the user. */
function send_password_reset_email(PDO $pdo, $user_id, $email)
{
    $raw = issue_auth_token($pdo, $user_id, 'reset', 3600); // 1h
    $link = BASE_HREF . 'reset?t=' . $raw;
    send_mail(
        $email,
        'Reset your ' . APP_NAME . ' password',
        "Someone (hopefully you) asked to reset your " . APP_NAME . " password.\n\nSet a new one:\n" . $link
            . "\n\nThis link expires in 1 hour and can be used once. If you didn't ask, ignore this message — your password is unchanged."
    );
}
