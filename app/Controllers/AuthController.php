<?php
namespace App\Controllers;

use App\Models\User;
use App\Models\AuthSecurity;
use RuntimeException;

class AuthController {
    private $db;
    private $userModel;
    private $authSecurity;

    public function __construct($mysqli) {
        $this->db = $mysqli;
        $this->userModel = new User($mysqli);
        $this->authSecurity = new AuthSecurity($mysqli);
        if (empty($_SESSION['auth_csrf_token'])) {
            $_SESSION['auth_csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Handle user login
     */
    public function login() {
        $error = '';
        $success = '';
        $authMode = $_GET['mode'] ?? 'login';
        $authStep = $_GET['step'] ?? '';
        $csrfToken = $_SESSION['auth_csrf_token'];
        $pendingEmail = '';

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            if (!$this->validCsrfToken($_POST['csrf_token'] ?? '')) {
                $error = 'Your session expired. Refresh the page and try again.';
            } else {
                $action = $_POST['auth_action'] ?? 'login';
                try {
                    if ($action === 'login') {
                        $this->handlePasswordLogin($error, $success, $authStep);
                    } elseif ($action === 'verify_email' || $action === 'verify_login_otp') {
                        $authStep = $action === 'verify_email' ? 'verify' : 'otp';
                        $this->handleChallengeVerification($action, $error, $success);
                    } elseif ($action === 'resend_code') {
                        $authStep = ($_SESSION['auth_pending_purpose'] ?? '') === AuthSecurity::PURPOSE_LOGIN_OTP
                            ? 'otp'
                            : 'verify';
                        $this->handleChallengeResend($error, $success);
                    } elseif ($action === 'forgot_password') {
                        $authMode = 'forgot';
                        $this->handleForgotPassword($error, $success);
                        if (!empty($_SESSION['auth_reset_email'])) {
                            $authMode = 'verify_reset';
                        }
                    } elseif ($action === 'verify_reset_otp' || $action === 'resend_reset_code') {
                        $authMode = 'verify_reset';
                        if ($action === 'verify_reset_otp') {
                            $this->handleResetCodeVerification($error);
                        } else {
                            $this->handleResetCodeResend($error, $success);
                        }
                    } elseif ($action === 'reset_password') {
                        $authMode = 'reset';
                        $this->handlePasswordReset($error, $success);
                        if ($success !== '') {
                            $_SESSION['auth_notice'] = $success;
                            header('Location: ' . BASE_URL . 'login.php');
                            exit;
                        }
                    } else {
                        $error = 'Invalid request. Please try again.';
                    }
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                    $success = '';
                }
            }
        }

        if (isset($_SESSION['auth_notice'])) {
            $success = $_SESSION['auth_notice'];
            unset($_SESSION['auth_notice']);
        }
        if (isset($_SESSION['auth_error'])) {
            $error = $_SESSION['auth_error'];
            unset($_SESSION['auth_error']);
        }
        if (isset($_SESSION['auth_pending_user_id'])) {
            $pendingUser = $this->userModel->getById((int)$_SESSION['auth_pending_user_id']);
            if ($pendingUser) {
                $pendingEmail = $this->maskEmail($pendingUser['email']);
            }
        }
        $resetEmail = !empty($_SESSION['auth_reset_email'])
            ? $this->maskEmail($_SESSION['auth_reset_email'])
            : '';
        if ($authMode === 'reset') {
            $resetUserId = (int)($_SESSION['auth_reset_verified_user_id'] ?? 0);
            $resetVerifiedAt = (int)($_SESSION['auth_reset_verified_at'] ?? 0);
            $resetUser = $resetUserId > 0 ? $this->userModel->getById($resetUserId) : null;
            if (!$resetUser || $resetUser['status'] !== User::STATUS_ACTIVE ||
                $resetVerifiedAt < time() - 600) {
                unset($_SESSION['auth_reset_verified_user_id'], $_SESSION['auth_reset_verified_at']);
                $authMode = !empty($_SESSION['auth_reset_email']) ? 'verify_reset' : 'forgot';
                $error = 'Verify your email address before resetting your password.';
            } else {
                $resetEmail = $this->maskEmail($resetUser['email']);
            }
        } elseif ($authMode === 'verify_reset' && empty($_SESSION['auth_reset_email'])) {
            $authMode = 'forgot';
        }
        if (isset($_SESSION['auth_pending_purpose']) &&
            $_SESSION['auth_pending_purpose'] === AuthSecurity::PURPOSE_EMAIL_VERIFICATION &&
            in_array($_POST['auth_action'] ?? '', ['verify_email', 'verify_login_otp', 'resend_code'], true)) {
            $authStep = 'verify';
        }
        if ($authStep === 'otp' &&
            ($_SESSION['auth_pending_purpose'] ?? '') !== AuthSecurity::PURPOSE_LOGIN_OTP) {
            $authStep = '';
        }
        if ($authStep === 'verify' &&
            ($_SESSION['auth_pending_purpose'] ?? '') !== AuthSecurity::PURPOSE_EMAIL_VERIFICATION) {
            $authStep = '';
        }
        if (!in_array($authStep, ['verify', 'otp'], true)) {
            $authMode = in_array($authMode, ['forgot', 'verify_reset', 'reset'], true) ? $authMode : 'login';
        }

        require_once __DIR__ . '/../Views/auth/login.php';
    }

    /**
     * Handle user registration
     */
    public function register() {
        $error = '';
        $success = '';
        $csrfToken = $_SESSION['auth_csrf_token'];

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            if (!$this->validCsrfToken($_POST['csrf_token'] ?? '')) {
                $error = 'Your session expired. Refresh the page and try again.';
            } else {
                $name = $this->postString('name');
                $email = strtolower($this->postString('email'));
                $phone = $this->postString('phone');
                $address = $this->postString('address');
                $role = $this->postString('role') ?: User::ROLE_CUSTOMER;
                $password = $this->postString('password');
                $confirmPassword = $this->postString('confirm_password');

                if ($name === '' || $email === '' || $password === '' || $confirmPassword === '') {
                    $error = 'Name, email, and password are required.';
                } elseif ($password !== $confirmPassword) {
                    $error = 'Passwords do not match.';
                } elseif (strlen($password) < 6) {
                    $error = 'Password must be at least 6 characters.';
                } elseif (strlen($name) > 100 || strlen($email) > 254 ||
                    !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid name and email address.';
                } else {
                    if ($this->userModel->emailExists($email)) {
                        $error = 'This email is already registered.';
                    } else {
                        $allowedRoles = [User::ROLE_CUSTOMER, User::ROLE_OWNER];
                        if (!in_array($role, $allowedRoles, true)) {
                            $role = User::ROLE_CUSTOMER;
                        }

                        if ($this->userModel->register($name, $email, $password, $role, $phone, $address)) {
                            unset(
                                $_SESSION['auth_pending_user_id'],
                                $_SESSION['auth_pending_purpose'],
                                $_SESSION['auth_reset_email'],
                                $_SESSION['auth_reset_verified_user_id'],
                                $_SESSION['auth_reset_verified_at'],
                                $_SESSION['auth_error']
                            );
                            $_SESSION['auth_notice'] = 'Registration successful. You can now sign in.';
                            header('Location: ' . BASE_URL . 'login.php');
                            exit;
                        } else {
                            $error = 'Something went wrong. Please try again.';
                        }
                    }
                }
            }
        }

        require_once __DIR__ . '/../Views/auth/register.php';
    }

    private function handlePasswordLogin(&$error, &$success, &$authStep) {
        $email = strtolower($this->postString('email'));
        $password = $this->postString('password');
        unset($_SESSION['auth_pending_user_id'], $_SESSION['auth_pending_purpose']);
        unset($_SESSION['auth_reset_email'], $_SESSION['auth_reset_verified_user_id'], $_SESSION['auth_reset_verified_at']);

        if ($email === '' || $password === '') {
            $error = 'Please enter both email and password.';
            return;
        }
        if (!$this->authSecurity->allowLoginAttempt($email, $this->clientIp())) {
            $error = 'Too many sign-in attempts. Please wait and try again.';
            return;
        }

        $user = $this->userModel->findByEmail($email);
        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Invalid email or password.';
            return;
        }

        if ($user['role'] !== User::ROLE_CUSTOMER) {
            $this->completeLogin($user);
        }

        $result = $this->authSecurity->issueChallenge(
            (int)$user['id'],
            AuthSecurity::PURPOSE_LOGIN_OTP,
            $email,
            $user['name'],
            $this->clientIp()
        );
        if (!in_array($result, ['sent', 'cooldown'], true)) {
            $error = $this->challengeDeliveryMessage($result);
            return;
        }

        $this->setPendingChallenge((int)$user['id'], AuthSecurity::PURPOSE_LOGIN_OTP);
        $authStep = 'otp';
        $success = $result === 'sent'
            ? 'A sign-in code has been sent to your email.'
            : 'A recent sign-in code is still valid. Check your email.';
    }

    private function handleChallengeVerification($action, &$error, &$success) {
        $userId = (int)($_SESSION['auth_pending_user_id'] ?? 0);
        $purpose = $action === 'verify_email'
            ? AuthSecurity::PURPOSE_EMAIL_VERIFICATION
            : AuthSecurity::PURPOSE_LOGIN_OTP;
        if ($userId < 1 || ($_SESSION['auth_pending_purpose'] ?? '') !== $purpose) {
            $error = 'This code request has expired. Sign in again to request a new code.';
            return;
        }

        $user = $this->userModel->getById($userId);
        if (!$user || $user['status'] !== User::STATUS_ACTIVE) {
            $error = 'This account is unavailable. Please contact the system administrator.';
            return;
        }
        if ($purpose === AuthSecurity::PURPOSE_LOGIN_OTP &&
            $user['role'] !== User::ROLE_CUSTOMER) {
            unset($_SESSION['auth_pending_user_id'], $_SESSION['auth_pending_purpose']);
            $error = 'This code request has expired. Sign in again to continue.';
            return;
        }
        if (!$this->authSecurity->allowOtpAttempt($user['email'], $this->clientIp())) {
            $error = 'Too many code attempts. Please wait and try again later.';
            return;
        }

        $result = $this->authSecurity->verifyChallenge(
            $userId,
            $purpose,
            $this->postString('otp')
        );
        if ($result !== 'verified') {
            $error = $this->challengeVerificationMessage($result);
            return;
        }

        if (in_array($purpose, [
            AuthSecurity::PURPOSE_EMAIL_VERIFICATION,
            AuthSecurity::PURPOSE_LOGIN_OTP
        ], true)) {
            $this->completeLogin($user);
        }

        $error = 'This code request has expired. Sign in again to continue.';
    }

    private function handleChallengeResend(&$error, &$success) {
        $userId = (int)($_SESSION['auth_pending_user_id'] ?? 0);
        $purpose = $_SESSION['auth_pending_purpose'] ?? '';
        $user = $userId > 0 ? $this->userModel->getById($userId) : null;
        if (!$user || $user['status'] !== User::STATUS_ACTIVE ||
            !in_array($purpose, [
                AuthSecurity::PURPOSE_EMAIL_VERIFICATION,
                AuthSecurity::PURPOSE_LOGIN_OTP
            ], true)) {
            $error = 'Sign in again before requesting another code.';
            return;
        }
        if ($purpose === AuthSecurity::PURPOSE_LOGIN_OTP &&
            $user['role'] !== User::ROLE_CUSTOMER) {
            unset($_SESSION['auth_pending_user_id'], $_SESSION['auth_pending_purpose']);
            $error = 'Sign in again before requesting another code.';
            return;
        }

        $result = $this->authSecurity->issueChallenge(
            $userId,
            $purpose,
            $user['email'],
            $user['name'],
            $this->clientIp()
        );
        if ($result === 'sent') {
            $success = 'A new code has been sent to your email.';
        } else {
            $error = $this->challengeDeliveryMessage($result);
        }
    }

    private function handleForgotPassword(&$error, &$success) {
        $email = strtolower($this->postString('email'));
        if (($_SESSION['auth_reset_email'] ?? '') !== $email) {
            unset(
                $_SESSION['auth_reset_email'],
                $_SESSION['auth_reset_verified_user_id'],
                $_SESSION['auth_reset_verified_at']
            );
        } else {
            unset($_SESSION['auth_reset_verified_user_id'], $_SESSION['auth_reset_verified_at']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
            return;
        }

        if (!$this->authSecurity->allowPasswordResetRequest($email, $this->clientIp())) {
            $error = 'A reset email can only be sent once every five minutes. Please try again later.';
            return;
        }

        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            $error = 'No active account was found with that email address.';
            return;
        }

        $result = $this->authSecurity->issueChallenge(
            (int)$user['id'],
            AuthSecurity::PURPOSE_PASSWORD_RESET,
            $email,
            $user['name'],
            $this->clientIp()
        );
        if ($result === 'sent') {
            $_SESSION['auth_reset_email'] = $email;
            $success = 'A password-reset code has been sent to your email.';
        } elseif ($result === 'cooldown') {
            $_SESSION['auth_reset_email'] = $email;
            $success = 'A recent reset code is still valid. Check your email.';
        } else {
            $error = $this->challengeDeliveryMessage($result);
        }
    }

    private function handleResetCodeVerification(&$error) {
        $email = $_SESSION['auth_reset_email'] ?? '';
        $user = $email !== '' ? $this->userModel->findByEmail($email) : null;
        if (!$user) {
            $error = 'The code is invalid, expired, or has already been used.';
            return;
        }

        if (!$this->authSecurity->allowOtpAttempt($email, $this->clientIp())) {
            $error = 'Too many code attempts. Please wait and try again later.';
            return;
        }

        $result = $this->authSecurity->verifyChallenge(
            (int)$user['id'],
            AuthSecurity::PURPOSE_PASSWORD_RESET,
            $this->postString('otp')
        );
        if ($result !== 'verified') {
            $error = $this->challengeVerificationMessage($result);
            return;
        }

        $_SESSION['auth_reset_verified_user_id'] = (int)$user['id'];
        $_SESSION['auth_reset_verified_at'] = time();
        unset($_SESSION['auth_reset_email']);
        header('Location: ' . BASE_URL . 'login.php?mode=reset');
        exit;
    }

    private function handleResetCodeResend(&$error, &$success) {
        $email = $_SESSION['auth_reset_email'] ?? '';
        $user = $email !== '' ? $this->userModel->findByEmail($email) : null;
        $success = 'If an account exists for that email, a password-reset code has been sent.';
        if (!$user) {
            return;
        }
        $this->authSecurity->assertEmailDeliveryReady();
        if (!$this->authSecurity->allowPasswordResetRequest($email, $this->clientIp())) {
            $error = 'A reset email can only be sent once every five minutes. Please try again later.';
            $success = '';
            return;
        }
        $result = $this->authSecurity->issueChallenge(
            (int)$user['id'],
            AuthSecurity::PURPOSE_PASSWORD_RESET,
            $email,
            $user['name'],
            $this->clientIp()
        );
        if ($result !== 'sent') {
            $error = $this->challengeDeliveryMessage($result, AuthSecurity::PURPOSE_PASSWORD_RESET);
            $success = '';
        }
    }

    private function handlePasswordReset(&$error, &$success) {
        $userId = (int)($_SESSION['auth_reset_verified_user_id'] ?? 0);
        $verifiedAt = (int)($_SESSION['auth_reset_verified_at'] ?? 0);
        $user = $userId > 0 ? $this->userModel->getById($userId) : null;
        if (!$user || $user['status'] !== User::STATUS_ACTIVE || $verifiedAt < time() - 600) {
            unset($_SESSION['auth_reset_verified_user_id'], $_SESSION['auth_reset_verified_at']);
            $error = 'Verify your email address before resetting your password.';
            return;
        }

        $password = $this->postString('password');
        $confirmPassword = $this->postString('confirm_password');
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
            return;
        }
        if ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
            return;
        }

        if (!$this->userModel->changePassword($userId, $password)) {
            $error = 'Could not update the password. Request a new reset code and try again.';
            return;
        }

        unset($_SESSION['auth_reset_email'], $_SESSION['auth_reset_verified_user_id'], $_SESSION['auth_reset_verified_at']);
        $success = 'Password updated. You can now sign in with your new password.';
    }

    private function completeLogin($user) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        unset(
            $_SESSION['auth_pending_user_id'],
            $_SESSION['auth_pending_purpose'],
            $_SESSION['auth_reset_email'],
            $_SESSION['auth_reset_verified_user_id'],
            $_SESSION['auth_reset_verified_at']
        );

        header('Location: ' . BASE_URL .
            ($user['role'] === User::ROLE_OWNER ? 'admin/dashboard.php' : 'dashboard.php'));
        exit;
    }

    private function setPendingChallenge($userId, $purpose) {
        $_SESSION['auth_pending_user_id'] = (int)$userId;
        $_SESSION['auth_pending_purpose'] = $purpose;
    }

    private function validCsrfToken($token) {
        return is_string($token) && hash_equals($_SESSION['auth_csrf_token'], $token);
    }

    private function postString($key) {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function clientIp() {
        return filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'unknown';
    }

    private function challengeDeliveryMessage($result, $purpose = null) {
        if ($result === 'cooldown') {
            if ($purpose === AuthSecurity::PURPOSE_PASSWORD_RESET) {
                return 'Please wait five minutes before requesting another reset code.';
            }
            return 'Please wait 60 seconds before requesting another code.';
        }
        if ($result === 'rate_limited') {
            return 'Too many code requests. Please try again later.';
        }
        return 'The code could not be sent. Please try again.';
    }

    private function challengeVerificationMessage($result) {
        if ($result === 'expired') {
            return 'That code has expired. Request a new code.';
        }
        if ($result === 'locked') {
            return 'Too many incorrect attempts. Request a new code.';
        }
        if ($result === 'missing') {
            return 'That code is no longer valid. Request a new code.';
        }
        return 'The code is incorrect. Please check it and try again.';
    }

    private function maskEmail($email) {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '';
        }
        $name = $parts[0];
        return substr($name, 0, 1) . str_repeat('*', max(1, strlen($name) - 1)) . '@' . $parts[1];
    }

    /**
     * Handle user logout
     */
    public function logout() {
        $_SESSION = array();
        session_destroy();
        header("Location: " . BASE_URL . "login.php");
        exit;
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Check if user has specific role
     */
    public static function hasRole($role) {
        return self::isAuthenticated() && isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }

    /**
     * Check if user is owner or staff
     */
    public static function isAdmin() {
        return self::isAuthenticated() && isset($_SESSION['role']) &&
               in_array($_SESSION['role'], [User::ROLE_OWNER]);
    }

    /**
     * Require authentication
     */
    public static function requireAuth() {
        if (!self::isAuthenticated()) {
            header("Location: " . BASE_URL . "login.php");
            exit;
        }
    }

    /**
     * Require admin access
     */
    public static function requireAdmin() {
        if (!self::isAdmin()) {
            header("Location: " . BASE_URL . "login.php");
            exit;
        }
    }

    /**
     * Get current user ID
     */
    public static function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current user role
     */
    public static function getCurrentUserRole() {
        return $_SESSION['role'] ?? null;
    }
}
