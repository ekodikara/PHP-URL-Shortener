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
    $stmt = $pdo->prepare('SELECT id, email, plan, billing_interval, stripe_customer_id, stripe_subscription_id, is_admin, blocked, blocked_reason, created FROM users WHERE id = ?');
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
    return in_array($user['plan'], array('pro', 'premium'), true);
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
    // Always run a hash verify to keep timing roughly constant.
    $hash = $row ? $row['password_hash'] : '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinv';
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
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user_id;
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
