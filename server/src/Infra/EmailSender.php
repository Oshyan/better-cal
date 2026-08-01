<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Reminder email transport: wraps phpmailer/phpmailer (SMTP + STARTTLS). The
 * library is a composer dependency installed on the server; this class only
 * touches it lazily so CLI tools and tests run without vendor/. SMTP settings
 * come from .env (BETTERCAL_SMTP_HOST/PORT/USER/PASS/FROM); everything is
 * optional and isConfigured() gates every send, so an unconfigured server
 * degrades to push-only without errors.
 */
final class EmailSender
{
    private const FROM_NAME = 'Better-Cal';

    public function __construct(private readonly array $cfg)
    {
    }

    public function isConfigured(): bool
    {
        $smtp = $this->cfg['smtp'] ?? [];
        return ($smtp['host'] ?? '') !== '' && ($smtp['from'] ?? '') !== '';
    }

    /**
     * Build the reminder email from a notification payload {title, body, url}.
     * Pure so tests can cover it without vendor/ or SMTP.
     *
     * @return array{subject:string, html:string, text:string}
     */
    public static function buildMessage(array $payload, string $baseUrl): array
    {
        $title = (string) ($payload['title'] ?? '') !== '' ? (string) $payload['title'] : '(untitled event)';
        $body = (string) ($payload['body'] ?? '');
        $link = rtrim($baseUrl, '/') . (string) ($payload['url'] ?? '/');
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:-apple-system,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;'
            . 'max-width:480px;margin:0 auto;padding:24px 16px;color:#1c1e22">'
            . '<p style="margin:0 0 4px;font-size:12px;letter-spacing:0.04em;text-transform:uppercase;color:#8a8f98">Reminder</p>'
            . '<h2 style="margin:0 0 6px;font-size:19px;line-height:1.3">' . $h($title) . '</h2>'
            . ($body !== '' ? '<p style="margin:0 0 20px;font-size:14px;color:#555b64">' . $h($body) . '</p>' : '')
            . '<p style="margin:0 0 24px"><a href="' . $h($link) . '" '
            . 'style="display:inline-block;background:#5b7fd4;color:#ffffff;text-decoration:none;'
            . 'padding:10px 18px;border-radius:8px;font-size:14px">Open event</a></p>'
            . '<p style="margin:0;font-size:12px;color:#8a8f98">Sent by Better-Cal because this event has a reminder.</p>'
            . '</div>';

        $text = $title . "\n" . ($body !== '' ? $body . "\n" : '') . "\n" . $link . "\n";

        return ['subject' => 'Reminder: ' . $title, 'html' => $html, 'text' => $text];
    }

    /**
     * Send one reminder email. Failures are logged and reported as false;
     * they must never block the reminder scan.
     *
     * @param array $payload {title, body, url} (Reminders::payload shape)
     */
    public function sendReminder(string $toEmail, array $payload): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log('email send skipped: phpmailer/phpmailer is not installed (run composer update on the server)');
            return false;
        }
        $smtp = $this->cfg['smtp'];
        $msg = self::buildMessage($payload, (string) ($this->cfg['base_url'] ?? ''));
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string) $smtp['host'];
            $mail->Port = (int) ($smtp['port'] ?? 587);
            $mail->SMTPSecure = $mail->Port === 465
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            if (($smtp['user'] ?? '') !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = (string) $smtp['user'];
                $mail->Password = (string) ($smtp['pass'] ?? '');
            }
            $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $mail->setFrom((string) $smtp['from'], self::FROM_NAME);
            $mail->addAddress($toEmail);
            $mail->Subject = $msg['subject'];
            $mail->isHTML(true);
            $mail->Body = $msg['html'];
            $mail->AltBody = $msg['text'];
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('email send error: ' . $e->getMessage());
            return false;
        }
    }
}
