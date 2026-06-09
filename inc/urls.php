<?php
/*
 * Snip — URL creation, quotas, and plan enforcement.
 */

/** New links created by this user in the current calendar month. */
function urls_this_month(PDO $pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM urls WHERE user_id = ? AND created >= ?');
    $stmt->execute(array($user_id, month_start_ts()));
    $row = $stmt->fetch();
    return (int) $row['c'];
}

/** All links ever created by this user (lifetime total). */
function urls_total(PDO $pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM urls WHERE user_id = ?');
    $stmt->execute(array($user_id));
    $row = $stmt->fetch();
    return (int) $row['c'];
}

/**
 * Current usage count for a user's plan, respecting its limit period
 * ('month' = this calendar month, 'total' = lifetime).
 */
function plan_usage(PDO $pdo, array $user)
{
    $period = plan_config($user['plan'])['limit_period'];
    return $period === 'total'
        ? urls_total($pdo, $user['id'])
        : urls_this_month($pdo, $user['id']);
}

/** Total custom-named links owned by this user. */
function custom_urls_count(PDO $pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM urls WHERE user_id = ? AND is_custom = 1');
    $stmt->execute(array($user_id));
    $row = $stmt->fetch();
    return (int) $row['c'];
}

/**
 * Create a short link for a user, enforcing the plan rules.
 * $slug is optional (custom name). Returns [row, null] or [null, errorMessage].
 */
function create_short_url(PDO $pdo, array $user, $long_url, $slug = '')
{
    $plan = plan_config($user['plan']);
    $long_url = trim((string) $long_url);
    $slug = trim((string) $slug);

    // An expired trial can't create new links (existing ones keep working).
    if (trial_expired($user)) {
        return array(null, 'Your ' . TRIAL_DAYS . '-day free trial has ended. '
            . 'Upgrade to Pro or Premium to create new links.');
    }

    // Validate the destination URL.
    if ($long_url === ''
        || !preg_match('|^https?://|i', $long_url)
        || !filter_var($long_url, FILTER_VALIDATE_URL)) {
        return array(null, 'Please enter a valid http(s) URL.');
    }
    if (strlen($long_url) > 2048) {
        return array(null, 'That URL is too long.');
    }

    // Link quota — monthly for the trial, lifetime total for paid plans.
    if ($plan['url_limit'] !== null && plan_usage($pdo, $user) >= $plan['url_limit']) {
        $msg = $plan['limit_period'] === 'total'
            ? 'You have reached your limit of ' . $plan['url_limit'] . ' links. Upgrade to Pro or Premium for more.'
            : 'You have reached your monthly limit of ' . $plan['url_limit'] . ' links. Upgrade your plan for more.';
        return array(null, $msg);
    }

    // Custom slug handling.
    $is_custom = 0;
    if ($slug !== '') {
        if ($plan['custom_slugs'] <= 0) {
            return array(null, 'Custom link names are a Premium feature. Upgrade to claim your own names.');
        }
        if (!is_valid_slug($slug)) {
            return array(null, 'Custom names must be 3–40 letters, numbers, hyphens or underscores (and not a reserved word).');
        }
        if (custom_urls_count($pdo, $user['id']) >= $plan['custom_slugs']) {
            return array(null, 'You have used all ' . $plan['custom_slugs'] . ' of your custom names.');
        }
        // Uniqueness.
        $stmt = $pdo->prepare('SELECT 1 FROM urls WHERE code = ?');
        $stmt->execute(array($slug));
        if ($stmt->fetch()) {
            return array(null, 'That custom name is already taken.');
        }
        $code = $slug;
        $is_custom = 1;
    } else {
        $code = unique_random_code($pdo);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO urls (user_id, code, long_url, is_custom, created) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(array($user['id'], $code, $long_url, $is_custom, time()));
    } catch (PDOException $e) {
        // Unique race on the code.
        return array(null, 'That name was just taken — try another.');
    }

    return array(array(
        'code'      => $code,
        'long_url'  => $long_url,
        'is_custom' => $is_custom,
        'short_url' => BASE_HREF . $code,
    ), null);
}
