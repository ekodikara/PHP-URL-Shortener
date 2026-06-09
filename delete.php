<?php
/*
 * Snip — delete one of the current user's links.
 */
require __DIR__ . '/inc/bootstrap.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('dashboard');
}

csrf_check();

$user = current_user();
$code = isset($_POST['code']) ? (string) $_POST['code'] : '';

// Scope the delete to the owner so users can't remove other people's links.
$stmt = $pdo->prepare('DELETE FROM urls WHERE code = ? AND user_id = ?');
$stmt->execute(array($code, $user['id']));

set_flash($stmt->rowCount() ? 'success' : 'error',
    $stmt->rowCount() ? 'Link deleted.' : 'Link not found.');
redirect_to('dashboard');
