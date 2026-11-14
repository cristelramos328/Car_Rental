<?php
namespace App\Services;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

class AuthMailer {
    public function assertConfigured() {
        if (!defined('SMTP_HOST') || trim((string)SMTP_HOST) === '' ||
            !defined('SMTP_PORT') || SMTP_PORT < 1 || SMTP_PORT > 65535 ||
            !defined('SMTP_USERNAME') || !filter_var(SMTP_USERNAME, FILTER_VALIDATE_EMAIL) ||
            !defined('SMTP_PASSWORD') || trim((string)SMTP_PASSWORD) === '' ||
            !defined('SMTP_ENCRYPTION') || !in_array(SMTP_ENCRYPTION, ['tls', 'ssl'], true) ||
            !defined('SMTP_FROM_EMAIL') || !filter_var(SMTP_FROM_EMAIL, FILTER_VALIDATE_EMAIL) ||
            !defined('SMTP_FROM_NAME') || trim((string)SMTP_FROM_NAME) === '') {
            throw new RuntimeException(
                'Authentication email is not configured. Set SMTP_USERNAME and SMTP_PASSWORD in the local .env file.'
            );
        }

        foreach ($this->phpMailerFiles() as $file) {
            if (!is_file($file)) {
                throw new RuntimeException('The PHPMailer SMTP library is unavailable.');
            }
        }
    }

    public function sendCode($email, $name, $purpose, $otp) {
        $purposes = [
            'email_verification' => [
                'subject' => 'Verify your Vehicle Rental Management System account',
                'intro' => 'Use this code to verify your email address.'
            ],
            'login_otp' => [
                'subject' => 'Your Vehicle Rental Management System sign-in code',
                'intro' => 'Use this code to finish signing in.'
            ],
            'password_reset' => [
                'subject' => 'Reset your Vehicle Rental Management System password',
                'intro' => 'Use this code to reset your password.'
            ]
        ];
        if (!isset($purposes[$purpose])) {
            throw new RuntimeException('Invalid authentication email purpose.');
        }

        $this->assertConfigured();
        foreach ($this->phpMailerFiles() as $file) {
            require_once $file;
        }

        $htmlContent = '<h2>Vehicle Rental Management System</h2>' .
            '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>' .
            '<p>' . htmlspecialchars($purposes[$purpose]['intro'], ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p style="font-size:28px;font-weight:bold;letter-spacing:6px">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p>This code expires in 5 minutes.</p>' .
            '<p>For your security, never share this code with anyone. If you did not request it, you can ignore this email.</p>';
        $textContent = 'Vehicle Rental Management System. ' . $purposes[$purpose]['intro'] .
            ' Code: ' . $otp . '. It expires in 5 minutes. For your security, never share this code with anyone. ' .
            'If you did not request it, ignore this email.';

        $mailer = new PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = SMTP_HOST;
            $mailer->SMTPAuth = true;
            $mailer->Username = SMTP_USERNAME;
            $mailer->Password = SMTP_PASSWORD;
            $mailer->SMTPSecure = SMTP_ENCRYPTION === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->Port = SMTP_PORT;
            $mailer->Timeout = 5;
            $mailer->getSMTPInstance()->Timelimit = 5;
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mailer->addAddress($email, $name);
            $mailer->isHTML(true);
            $mailer->Subject = $purposes[$purpose]['subject'];
            $mailer->Body = $htmlContent;
            $mailer->AltBody = $textContent;
            $mailer->send();
        } catch (PHPMailerException $exception) {
            error_log('Authentication email delivery failed. Check the local SMTP configuration.');
            throw new RuntimeException(
                'The authentication email could not be sent. Verify the SMTP settings and app password.'
            );
        }
    }

    private function phpMailerFiles() {
        $sourceDirectory = __DIR__ . '/../../admin/PHPMailer/src/';
        return [
            $sourceDirectory . 'Exception.php',
            $sourceDirectory . 'PHPMailer.php',
            $sourceDirectory . 'SMTP.php'
        ];
    }
}
