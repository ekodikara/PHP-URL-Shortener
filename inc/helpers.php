<?php
/*
 * Snip — small helpers: escaping, CSRF, redirects, JSON, code/slug handling.
 */

/** HTML-escape for safe output. */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Issue a redirect and stop. */
function redirect_to($path)
{
    header('Location: ' . $path);
    exit;
}

/** Emit JSON and stop. */
function json_response($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/** Whether the current request expects JSON (AJAX). */
function wants_json()
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// --- CSRF --------------------------------------------------------------------

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check()
{
    $sent = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $sent)) {
        if (wants_json()) {
            json_response(array('error' => 'Invalid session token. Please reload.'), 419);
        }
        // A stale token usually means the session expired — send the user back
        // to retry on a fresh form instead of a bare unthemed error page.
        set_flash('error', 'Your session expired — please try again.');
        $back = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/';
        if (strpos($back, BASE_HREF) !== 0) {
            $back = '/';   // only bounce back to our own pages
        }
        redirect_to($back);
    }
}

// --- Short codes / slugs -----------------------------------------------------

/** Generate a random short code from ALLOWED_CHARS. */
function random_code($length = CODE_LENGTH)
{
    $chars = ALLOWED_CHARS;
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

/** Generate a random code guaranteed unique in the urls table. */
function unique_random_code(PDO $pdo)
{
    $stmt = $pdo->prepare('SELECT 1 FROM urls WHERE code = ?');
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $code = random_code();
        $stmt->execute(array($code));
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not allocate a unique code.');
}

/** Validate a user-supplied custom slug. Returns true/false. */
function is_valid_slug($slug)
{
    if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $slug)) {
        return false;
    }
    return !in_array(strtolower($slug), $GLOBALS['RESERVED_SLUGS'], true);
}

/** Start of the current calendar month as a unix timestamp. */
function month_start_ts()
{
    return strtotime(date('Y-m-01 00:00:00'));
}

// --- Pricing -----------------------------------------------------------------

/** Price in whole currency units for a plan + interval (month|year). */
function plan_amount($plan, $interval)
{
    $monthly = plan_config($plan)['price'];
    if ($interval === 'year') {
        return round($monthly * 12 * (1 - YEARLY_DISCOUNT), 2);
    }
    return $monthly;
}

/** Price in cents (Stripe's smallest unit) for a plan + interval. */
function plan_amount_cents($plan, $interval)
{
    return (int) round(plan_amount($plan, $interval) * 100);
}

/** Format a money amount: "7" for whole values, "73.92" otherwise. */
function money($amount)
{
    return $amount == floor($amount) ? (string) (int) $amount : number_format($amount, 2);
}

// --- Flash messages ----------------------------------------------------------

function set_flash($type, $message)
{
    $_SESSION['flash'][] = array('type' => $type, 'message' => $message);
}

function take_flashes()
{
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : array();
    unset($_SESSION['flash']);
    return $f;
}
