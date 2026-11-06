<?php
/**
 * AfterBill - Sending email (PHPMailer)
 *
 * send_mail() never throws: a mail failure is logged and reported as false, so callers can
 * show a friendly message. Settings: config/mail.php (+ config/mail.local.php).
 */

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/** Absolute link for emails, from the configured app URL (never from the request). */
function app_url(string $path): string
{
    global $MAIL_CONFIG;
    return rtrim($MAIL_CONFIG['app_url'], '/') . '/' . ltrim($path, '/');
}

function send_mail(string $toEmail, string $toName, string $subject, string $text): bool
{
    global $MAIL_CONFIG;

    // .test / .localhost can never receive mail (RFC 2606/6761), so they always go to the log.
    $domain = strtolower(substr(strrchr($toEmail, '@') ?: '', 1));
    $useLog = $MAIL_CONFIG['driver'] !== 'smtp'
        || str_ends_with($domain, '.test') || str_ends_with($domain, '.localhost');

    if ($useLog) {
        $entry = json_encode([
            'at' => date('c'), 'to' => $toEmail, 'subject' => $subject, 'body' => $text,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ok = @file_put_contents($MAIL_CONFIG['log_file'], $entry . "\n", FILE_APPEND | LOCK_EX) !== false;
        if (!$ok) {
            error_log('AfterBill mail log not writable: ' . $MAIL_CONFIG['log_file']);
        }
        return $ok;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $MAIL_CONFIG['smtp_host'];
        $mail->Port       = (int)$MAIL_CONFIG['smtp_port'];
        $mail->SMTPAuth   = true;
        $mail->SMTPSecure = (int)$MAIL_CONFIG['smtp_port'] === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Username   = $MAIL_CONFIG['smtp_username'];
        $mail->Password   = $MAIL_CONFIG['smtp_password'];
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        $mail->setFrom($MAIL_CONFIG['from_email'], $MAIL_CONFIG['from_name']);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body    = $text;   // plain text: no HTML to render or escape
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('AfterBill mail failed to ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    }
}
