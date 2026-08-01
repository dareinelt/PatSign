<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class MailService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function sendTestMail(string $to): void
    {
        $this->send($to, 'PatSign Testmail', "Diese Testnachricht bestätigt, dass die SMTP-Konfiguration funktioniert.\n\nPatSign");
    }

    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null): void
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->Host = $this->settings->getString('mail.host', 'localhost');
            $mailer->Port = $this->settings->getInt('mail.port', 25);

            $username = $this->settings->getString('mail.username');
            if ($username !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = $username;
                $mailer->Password = $this->settings->getString('mail.password');
            }

            $encryption = $this->settings->getString('mail.encryption');
            if ($encryption !== '') {
                $mailer->SMTPSecure = $encryption;
            }

            $mailer->setFrom(
                $this->settings->getString('mail.from', 'clinic@example.local'),
                $this->settings->getString('mail.from_name', 'PatSign')
            );
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $body;

            if ($attachmentPath !== null && is_file($attachmentPath)) {
                $mailer->addAttachment($attachmentPath);
            }

            $mailer->send();
        } catch (\Throwable $e) {
            throw new RuntimeException('E-Mail-Versand fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }
}
