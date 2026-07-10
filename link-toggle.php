<?php
/*
 * Snip — enable/disable one of the current user's own short links.
 */
require __DIR__ . '/inc/bootstrap.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('dashboard');
}
csrf_check();

$user = current_user();
$code = isset($_POST['code']) ? (string) $_POST['code'] : '';

// Flip the blocked flag, scoped to the owner.
$stmt = $pdo->prepare('UPDATE urls SET blocked = 1 - blocked WHERE code = ? AND user_id = ?');
$stmt->execute(array($code, $user['id']));

if ($stmt->rowCount()) {
    // Tell the user which state the link landed in — a paused link 410s for visitors.
    $st = $pdo->prepare('SELECT blocked FROM urls WHERE code = ? AND user_id = ?');
    $st->execute(array($code, $user['id']));
    $blocked = (int) $st->fetchColumn();
    set_flash('success', $blocked
        ? 'Link paused — visitors now see a disabled notice.'
        : 'Link re-enabled — visitors are redirected again.');
} else {
    set_flash('error', 'Link not found.');
}
redirect_to('dashboard');
