<?php
namespace App\Models;

use App\Services\AuthMailer;
use RuntimeException;

class AuthSecurity {
    const PURPOSE_EMAIL_VERIFICATION = 'email_verification';
    const PURPOSE_LOGIN_OTP = 'login_otp';
    const PURPOSE_PASSWORD_RESET = 'password_reset';

    private $db;
    private $mailer;

    public function __construct($mysqli) {
        $this->db = $mysqli;
        $this->mailer = new AuthMailer();

        if (!$this->db->query("CREATE TABLE IF NOT EXISTS auth_security (
            user_id INT NOT NULL PRIMARY KEY,
            email_verified_at DATETIME DEFAULT NULL,
            challenge_type ENUM('email_verification', 'login_otp', 'password_reset') DEFAULT NULL,
            challenge_hash VARCHAR(255) DEFAULT NULL,
            challenge_expires_at DATETIME DEFAULT NULL,
            challenge_sent_at DATETIME DEFAULT NULL,
            challenge_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_auth_security_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB")) {
            throw new RuntimeException('Authentication security storage could not be initialized.');
        }

        if (!$this->db->query("CREATE TABLE IF NOT EXISTS auth_rate_limits (
            bucket_key CHAR(64) NOT NULL PRIMARY KEY,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            blocked_until DATETIME DEFAULT NULL
        ) ENGINE=InnoDB")) {
            throw new RuntimeException('Authentication rate limiting could not be initialized.');
        }

        if (!$this->db->query(
            'DELETE FROM auth_rate_limits
             WHERE window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)'
        )) {
            throw new RuntimeException('Authentication rate limiting could not be initialized.');
        }
    }

    public function ensureUser($userId) {
        $stmt = $this->db->prepare('INSERT IGNORE INTO auth_security (user_id) VALUES (?)');
        if (!$stmt) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
    }

    public function isEmailVerified($userId) {
        $this->ensureUser($userId);
        $stmt = $this->db->prepare('SELECT email_verified_at FROM auth_security WHERE user_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
        $state = $stmt->get_result()->fetch_assoc();
        return $state && $state['email_verified_at'] !== null;
    }

    public function allowLoginAttempt($email, $ipAddress) {
        return $this->allowRateLimitedAction('login', $email, $ipAddress, 10, 900);
    }

    public function allowPasswordResetRequest($email, $ipAddress) {
        return $this->allowRateLimitedAction('password_reset_request', $email, $ipAddress, 1, 300);
    }

    public function allowOtpAttempt($email, $ipAddress) {
        return $this->allowRateLimitedAction('otp_attempt', $email, $ipAddress, 10, 900);
    }

    public function assertEmailDeliveryReady() {
        $this->mailer->assertConfigured();
    }

    public function issueChallenge($userId, $purpose, $email, $name, $ipAddress) {
        $allowedPurposes = [
            self::PURPOSE_EMAIL_VERIFICATION,
            self::PURPOSE_LOGIN_OTP,
            self::PURPOSE_PASSWORD_RESET
        ];
        if (!in_array($purpose, $allowedPurposes, true)) {
            throw new RuntimeException('Invalid authentication challenge.');
        }

        $this->mailer->assertConfigured();
        $this->ensureUser($userId);
        $cooldownSeconds = $purpose === self::PURPOSE_PASSWORD_RESET ? 300 : 60;
        $stmt = $this->db->prepare(
            'SELECT challenge_type, challenge_sent_at
             FROM auth_security WHERE user_id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
        $state = $stmt->get_result()->fetch_assoc();

        if ($state && $state['challenge_type'] === $purpose && $state['challenge_sent_at'] !== null) {
            $sentAt = strtotime($state['challenge_sent_at'] . ' UTC');
            if ($sentAt !== false && time() - $sentAt < $cooldownSeconds) {
                return 'cooldown';
            }
        }

        if (!$this->allowRateLimitedAction('send_' . $purpose, $email, $ipAddress, 5, 3600)) {
            return 'rate_limited';
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        if ($otpHash === false) {
            throw new RuntimeException('Could not generate an authentication code.');
        }

        $stmt = $this->db->prepare(
            "UPDATE auth_security
             SET challenge_type = ?, challenge_hash = ?,
                 challenge_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE),
                 challenge_sent_at = UTC_TIMESTAMP(), challenge_attempts = 0
             WHERE user_id = ?
               AND (challenge_type IS NULL OR challenge_type <> ? OR challenge_sent_at IS NULL
                    OR challenge_sent_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$cooldownSeconds} SECOND))"
        );
        if (!$stmt) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
        $stmt->bind_param('ssis', $purpose, $otpHash, $userId, $purpose);
        if (!$stmt->execute()) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
        if ($stmt->affected_rows !== 1) {
            return 'cooldown';
        }

        try {
            $this->mailer->sendCode($email, $name, $purpose, $otp);
        } catch (\Throwable $error) {
            error_log('Authentication email delivery failed: ' . $error->getMessage());
            $this->clearChallenge($userId);
            $message = $error instanceof RuntimeException
                ? $error->getMessage()
                : 'The email could not be sent. Check the SMTP settings and app password, then try again.';
            throw new RuntimeException($message);
        }

        return 'sent';
    }

    public function verifyChallenge($userId, $purpose, $otp) {
        if (!preg_match('/^\d{6}$/', $otp)) {
            return 'invalid';
        }

        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('Could not verify the authentication code.');
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT challenge_type, challenge_hash, challenge_expires_at, challenge_attempts
                 FROM auth_security WHERE user_id = ? FOR UPDATE'
            );
            if (!$stmt) {
                throw new RuntimeException('Authentication security storage is unavailable.');
            }
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Authentication security storage is unavailable.');
            }
            $state = $stmt->get_result()->fetch_assoc();

            if (!$state || $state['challenge_type'] !== $purpose || !$state['challenge_hash']) {
                $this->db->commit();
                return 'missing';
            }

            $expiresAt = strtotime($state['challenge_expires_at'] . ' UTC');
            if ($expiresAt === false || $expiresAt <= time()) {
                $this->clearChallenge($userId);
                $this->db->commit();
                return 'expired';
            }

            if ((int)$state['challenge_attempts'] >= 5) {
                $this->clearChallenge($userId);
                $this->db->commit();
                return 'locked';
            }

            if (!password_verify($otp, $state['challenge_hash'])) {
                $attempts = (int)$state['challenge_attempts'] + 1;
                if ($attempts >= 5) {
                    $this->clearChallenge($userId);
                    $this->db->commit();
                    return 'locked';
                }

                $stmt = $this->db->prepare(
                    'UPDATE auth_security SET challenge_attempts = ? WHERE user_id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException('Authentication security storage is unavailable.');
                }
                $stmt->bind_param('ii', $attempts, $userId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Authentication security storage is unavailable.');
                }
                $this->db->commit();
                return 'invalid';
            }

            if ($purpose === self::PURPOSE_EMAIL_VERIFICATION) {
                $stmt = $this->db->prepare(
                    'UPDATE auth_security SET email_verified_at = UTC_TIMESTAMP() WHERE user_id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException('Authentication security storage is unavailable.');
                }
                $stmt->bind_param('i', $userId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Authentication security storage is unavailable.');
                }
            }

            $this->clearChallenge($userId);
            if (!$this->db->commit()) {
                throw new RuntimeException('Could not complete authentication code verification.');
            }
            return 'verified';
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function allowRateLimitedAction($action, $email, $ipAddress, $limit, $windowSeconds) {
        $buckets = [
            $action . ':email:' . strtolower(trim($email)),
            $action . ':ip:' . ($ipAddress !== '' ? $ipAddress : 'unknown')
        ];

        foreach ($buckets as $bucket) {
            if (!$this->consumeRateLimit(hash('sha256', $bucket), $limit, $windowSeconds)) {
                return false;
            }
        }

        return true;
    }

    private function consumeRateLimit($bucketKey, $limit, $windowSeconds) {
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('Authentication rate limiting is unavailable.');
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO auth_rate_limits (bucket_key, attempts, window_started_at)
                 VALUES (?, 0, UTC_TIMESTAMP())'
            );
            if (!$stmt) {
                throw new RuntimeException('Authentication rate limiting is unavailable.');
            }
            $stmt->bind_param('s', $bucketKey);
            if (!$stmt->execute()) {
                throw new RuntimeException('Authentication rate limiting is unavailable.');
            }

            $stmt = $this->db->prepare(
                'SELECT attempts, window_started_at, blocked_until
                 FROM auth_rate_limits WHERE bucket_key = ? FOR UPDATE'
            );
            if (!$stmt) {
                throw new RuntimeException('Authentication rate limiting is unavailable.');
            }
            $stmt->bind_param('s', $bucketKey);
            if (!$stmt->execute()) {
                throw new RuntimeException('Authentication rate limiting is unavailable.');
            }
            $state = $stmt->get_result()->fetch_assoc();
            $now = time();
            $windowStartedAt = $state ? strtotime($state['window_started_at'] . ' UTC') : false;
            $blockedUntil = $state && $state['blocked_until']
                ? strtotime($state['blocked_until'] . ' UTC')
                : false;

            if ($blockedUntil !== false && $blockedUntil > $now) {
                $this->db->commit();
                return false;
            }

            if ($windowStartedAt === false || $now - $windowStartedAt >= $windowSeconds) {
                $attempts = 1;
                $update = $this->db->prepare(
                    'UPDATE auth_rate_limits
                     SET attempts = ?, window_started_at = UTC_TIMESTAMP(), blocked_until = NULL
                     WHERE bucket_key = ?'
                );
            } elseif ((int)$state['attempts'] >= $limit) {
                $update = $this->db->prepare(
                    'UPDATE auth_rate_limits
                     SET blocked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)
                     WHERE bucket_key = ?'
                );
                if (!$update) {
                    throw new RuntimeException('Authentication rate limiting is unavailable.');
                }
                $lockSeconds = $windowSeconds;
                $update->bind_param('is', $lockSeconds, $bucketKey);
                if (!$update->execute()) {
                    throw new RuntimeException('Authentication rate limiting is unavailable.');
                }
                $this->db->commit();
                return false;
            } else {
                $attempts = (int)$state['attempts'] + 1;
                $update = $this->db->prepare(
                    'UPDATE auth_rate_limits SET attempts = ? WHERE bucket_key = ?'
                );
            }

            if (!$update) {
                throw new RuntimeException('Authentication rate limiting is unavailable.');
            }
            $update->bind_param('is', $attempts, $bucketKey);
            if (!$update->execute()) {
                throw new RuntimeException('Authentication rate limiting is unavailable.');
            }
            if (!$this->db->commit()) {
                throw new RuntimeException('Authentication rate limiting is unavailable.');
            }
            return true;
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function clearChallenge($userId) {
        $stmt = $this->db->prepare(
            'UPDATE auth_security
             SET challenge_type = NULL, challenge_hash = NULL,
                 challenge_expires_at = NULL, challenge_sent_at = NULL,
                 challenge_attempts = 0
             WHERE user_id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Authentication security storage is unavailable.');
        }
    }
}
