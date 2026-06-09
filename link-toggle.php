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

set_flash($stmt->rowCount() ? 'success' : 'error',
    $stmt->rowCount() ? 'Link updated.' : 'Link not found.');
redirect_to('dashboard');
