<?php
/*
 * Snip — minimal outbound mail.
 *
 * Uses PHP mail() when MAIL_FROM is configured (point it at an SMTP relay /
 * sendmail in prod, or swap in an SMTP library later). When MAIL_FROM is empty
 * (dev), messages are written to cache/mail.log so links (verify / reset) are
 * retrievable without a mail server. Every send is logged there regardless, so
 * there's an audit trail.
 */

/** Send a plain-text email. Returns true if handed off (or logged in dev). */
function send_mail($to, $subject, $body)
{
    $logged = false;
    $line = '[' . gmdate('c') . "] TO={$to}\nSUBJECT: {$subject}\n\n{$body}\n"
        . str_repeat('-', 60) . "\n";
    $logdir = __DIR__ . '/../cache';
    if (is_dir($logdir) && is_writable($logdir)) {
        $logged = (bool) @file_put_contents($logdir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
    }

    if (MAIL_FROM === '') {
        error_log("MAIL (dev; MAIL_FROM unset, not delivered) to={$to} subject={$subject}");
        return $logged;
    }

    $headers = 'From: ' . MAIL_FROM . "\r\n"
        . 'Reply-To: ' . (MAIL_REPLY_TO !== '' ? MAIL_REPLY_TO : MAIL_FROM) . "\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n";
    $ok = @mail($to, $subject, $body, $headers);
    if (!$ok) {
        error_log("MAIL send failed to={$to} subject={$subject}");
    }
    return $ok || $logged;
}
