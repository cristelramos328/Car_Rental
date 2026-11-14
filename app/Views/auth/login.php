<?php 
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    html, body {
        min-height: 100%;
        height: 100%;
        overflow: auto;
        padding-top: 0 !important;
    }

    body {
        display: flex;
        flex-direction: column;
        margin: 0;
    }

    .auth-container {
        position: relative;
        width: 100%;
        min-height: calc(100vh - 150px);
        background-color: #fff;
        z-index: 1;
        flex: 1 0 auto;
    }

    footer {
        position: static !important;
        width: 100%;
        background: #0f172a;
        color: rgba(255, 255, 255, 0.8);
        border: 1px solid rgba(148, 163, 184, 0.15);
        z-index: 1;
        pointer-events: auto;
        margin-top: 0 !important;
    }

    .otp-code-inputs {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 0.5rem;
    }

    .otp-code-input {
        height: 3.5rem;
        padding: 0.25rem;
        text-align: center;
        font-size: 1.35rem;
        font-weight: 700;
    }
</style>
<div class="container-fluid auth-container p-0">
    <div class="row g-0 w-100 h-100">
        <!-- Left Side: Animated Branding -->
        <div class="col-lg-7 d-none d-lg-flex flex-column align-items-center justify-content-center auth-left-panel text-white text-center">
            <!-- Animated Background Shapes -->
            <div class="circle-shape shape-1"></div>
            <div class="circle-shape shape-2"></div>
            <div class="circle-shape shape-3"></div>
            
            <div class="position-relative z-1 p-5">
                <div class="mb-4 animate-fade-up">
                    <i class="bi bi-car-front-fill display-1"></i>
                </div>
                <h1 class="display-4 fw-light animate-fade-up delay-100">CAR RENTAL</h1>
                <div class="mt-4 border-top border-white border-opacity-25 pt-4 animate-fade-up delay-300" style="max-width: 400px; margin: 0 auto;">
                    <p class="lead fs-5 text-white-50">Experience the future of mobility.</p>
                </div>
            </div>
        </div>

        <!-- Right Side: Login Form -->
        <div class="col-lg-5 d-flex align-items-center justify-content-center bg-white">
            <div class="auth-card border-0 shadow-none w-75 p-lg-5 p-3">
                <div class="mb-3 text-start w-100">
                    <a href="<?php echo BASE_URL; ?>index.php" class="text-decoration-none small fw-bold text-muted">
                        <i class="bi bi-arrow-left"></i> Return to Home
                    </a>
                </div>

                <div class="mb-4 text-center">
                    <h2 class="fw-bold display-6 mb-1"><?php
                        if ($authStep === 'verify') {
                            echo 'Verify Your Email';
                        } elseif ($authStep === 'otp') {
                            echo 'Two-Step Verification';
                        } elseif ($authMode === 'forgot') {
                            echo 'Forgot Password';
                        } elseif ($authMode === 'verify_reset') {
                            echo 'Verify Reset Code';
                        } elseif ($authMode === 'reset') {
                            echo 'Reset Password';
                        } else {
                            echo 'Welcome Back';
                        }
                    ?></h2>
                    <p class="text-muted small"><?php
                        if ($authStep === 'verify') {
                            echo 'Enter the verification code sent to ' . htmlspecialchars($pendingEmail ?: 'your email') . '.';
                        } elseif ($authStep === 'otp') {
                            echo 'Enter the sign-in code sent to ' . htmlspecialchars($pendingEmail ?: 'your email') . '.';
                        } elseif ($authMode === 'forgot') {
                            echo 'Enter your registered email to receive a password-reset code.';
                        } elseif ($authMode === 'verify_reset') {
                            echo 'Enter the password-reset code sent to ' . htmlspecialchars($resetEmail ?: 'your email') . '.';
                        } elseif ($authMode === 'reset') {
                            echo 'Choose a new password for ' . htmlspecialchars($resetEmail ?: 'your account') . '.';
                        } else {
                            echo 'Sign in to continue.';
                        }
                    ?></p>
                </div>
                
                <?php if(!empty($error)): ?>
                    <div class="alert alert-danger rounded-3 border-0 bg-danger-subtle text-danger mb-3 py-2 small"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if(!empty($success)): ?>
                    <div class="alert alert-success rounded-3 border-0 bg-success-subtle text-success mb-3 py-2 small"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if (in_array($authStep, ['verify', 'otp'], true)): ?>
                    <form action="<?php echo BASE_URL; ?>login.php?step=<?php echo htmlspecialchars($authStep); ?>" method="post" id="authOtpForm" data-code-form data-single-submit>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="auth_action" value="<?php echo $authStep === 'verify' ? 'verify_email' : 'verify_login_otp'; ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="authOtpDigit1">6-digit code</label>
                            <div class="otp-code-inputs" role="group" aria-label="6-digit code">
                                <?php for ($digit = 1; $digit <= 6; $digit++): ?>
                                    <input
                                        type="text"
                                        class="form-control otp-code-input"
                                        id="authOtpDigit<?php echo $digit; ?>"
                                        data-code-digit
                                        inputmode="numeric"
                                        pattern="[0-9]"
                                        maxlength="6"
                                        autocomplete="<?php echo $digit === 1 ? 'one-time-code' : 'off'; ?>"
                                        aria-label="Code digit <?php echo $digit; ?>"
                                        required>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="otp" data-code-value>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3 hover-float">Verify and Continue</button>
                    </form>
                    <form action="<?php echo BASE_URL; ?>login.php?step=<?php echo htmlspecialchars($authStep); ?>" method="post" class="text-center mb-3" data-single-submit>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="auth_action" value="resend_code">
                        <button type="submit" class="btn btn-link text-decoration-none small fw-bold" data-submitting-label="Sending...">Resend code</button>
                    </form>
                    <div class="text-center"><a href="<?php echo BASE_URL; ?>login.php" class="text-decoration-none small">Back to sign in</a></div>
                <?php elseif ($authMode === 'forgot'): ?>
                    <form action="<?php echo BASE_URL; ?>login.php?mode=forgot" method="post" data-single-submit>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="auth_action" value="forgot_password">
                        <div class="form-floating mb-3">
                            <input type="email" name="email" class="form-control" id="forgotEmail" placeholder="name@example.com" required>
                            <label for="forgotEmail">Email Address</label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3 hover-float" data-submitting-label="Sending...">Send Reset Code</button>
                    </form>
                    <div class="text-center"><a href="<?php echo BASE_URL; ?>login.php" class="text-decoration-none small">Back to sign in</a></div>
                <?php elseif ($authMode === 'verify_reset'): ?>
                    <form action="<?php echo BASE_URL; ?>login.php?mode=verify_reset" method="post" id="resetCodeForm" data-code-form>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="auth_action" value="verify_reset_otp">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="resetOtpDigit1">6-digit reset code</label>
                            <div class="otp-code-inputs" role="group" aria-label="6-digit reset code">
                                <?php for ($digit = 1; $digit <= 6; $digit++): ?>
                                    <input
                                        type="text"
                                        class="form-control otp-code-input"
                                        id="resetOtpDigit<?php echo $digit; ?>"
                                        data-code-digit
                                        inputmode="numeric"
                                        pattern="[0-9]"
                                        maxlength="6"
                                        autocomplete="<?php echo $digit === 1 ? 'one-time-code' : 'off'; ?>"
                                        aria-label="Code digit <?php echo $digit; ?>"
                                        required>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="otp" data-code-value>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3 hover-float">Verify Code</button>
                    </form>
                    <form action="<?php echo BASE_URL; ?>login.php?mode=verify_reset" method="post" class="text-center mb-3" data-single-submit>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="auth_action" value="resend_reset_code">
                        <button type="submit" class="btn btn-link text-decoration-none small fw-bold" data-submitting-label="Sending...">Resend reset code</button>
                    </form>
                    <div class="text-center"><a href="<?php echo BASE_URL; ?>login.php" class="text-decoration-none small">Back to sign in</a></div>
                <?php elseif ($authMode === 'reset'): ?>
                    <form action="<?php echo BASE_URL; ?>login.php?mode=reset" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="auth_action" value="reset_password">
                        <div class="password-wrapper mb-3">
                            <div class="form-floating">
                                <input type="password" name="password" class="form-control" id="resetPassword" placeholder="New password" minlength="8" required>
                                <label for="resetPassword">New Password</label>
                            </div>
                            <button type="button" class="eye-toggle btn btn-link p-0 text-decoration-none" onclick="togglePassword('resetPassword', 'toggleResetPasswordIcon')" aria-label="Toggle new password visibility">
                                <i class="bi bi-eye" id="toggleResetPasswordIcon"></i>
                            </button>
                        </div>
                        <div class="password-wrapper mb-3">
                            <div class="form-floating">
                                <input type="password" name="confirm_password" class="form-control" id="resetConfirmPassword" placeholder="Confirm password" minlength="8" required>
                                <label for="resetConfirmPassword">Confirm New Password</label>
                            </div>
                            <button type="button" class="eye-toggle btn btn-link p-0 text-decoration-none" onclick="togglePassword('resetConfirmPassword', 'toggleResetConfirmPasswordIcon')" aria-label="Toggle confirm password visibility">
                                <i class="bi bi-eye" id="toggleResetConfirmPasswordIcon"></i>
                            </button>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3 hover-float">Reset Password</button>
                    </form>
                    <div class="text-center"><a href="<?php echo BASE_URL; ?>login.php" class="text-decoration-none small">Back to sign in</a></div>
                <?php else: ?>
                    <form action="<?php echo BASE_URL; ?>login.php" method="post" data-single-submit>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="auth_action" value="login">
                        <div class="form-floating mb-3">
                            <input type="email" name="email" class="form-control" id="floatingInput" placeholder="name@example.com" required>
                            <label for="floatingInput">Email Address</label>
                        </div>

                        <div class="password-wrapper mb-3">
                            <div class="form-floating">
                                <input type="password" name="password" class="form-control" id="floatingPassword" placeholder="Password" required>
                                <label for="floatingPassword">Password</label>
                            </div>
                            <i class="bi bi-eye eye-toggle" id="toggleLogin" onclick="togglePassword('floatingPassword', 'toggleLogin')"></i>
                        </div>

                        <div class="d-flex justify-content-between mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="rememberMe">
                                <label class="form-check-label text-muted small" for="rememberMe">Remember me</label>
                            </div>
                            <a href="<?php echo BASE_URL; ?>login.php?mode=forgot" class="text-decoration-none small fw-bold">Forgot Password?</a>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3 hover-float" data-submitting-label="Signing in...">Sign In</button>

                        <div class="text-center">
                            <p class="text-muted mb-0 small">Don't have an account? <a href="<?php echo BASE_URL; ?>register.php" class="text-decoration-none fw-bold">Create Account</a></p>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('form[data-single-submit]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            form.dataset.submitting = 'true';
            var button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = button.dataset.submittingLabel || 'Sending...';
            }
        });
    });

    (function () {
        document.querySelectorAll('form[data-code-form]').forEach(function (form) {
            var digits = Array.prototype.slice.call(form.querySelectorAll('[data-code-digit]'));
            var combinedCode = form.querySelector('[data-code-value]');
            if (!digits.length || !combinedCode) {
                return;
            }

            function setCode(value) {
                var code = value.replace(/\D/g, '').slice(0, 6);
                digits.forEach(function (digit, index) {
                    digit.value = code.charAt(index) || '';
                });
                combinedCode.value = code;
            }

            function updateCombinedCode() {
                var values = digits.map(function (digit) {
                    return digit.value;
                });
                var code = values.join('').replace(/\D/g, '').slice(0, 6);
                if (values.some(function (value) {
                    return value.length > 1;
                })) {
                    setCode(code);
                    return;
                }
                combinedCode.value = code;
            }

            digits.forEach(function (digit, index) {
                digit.addEventListener('input', function () {
                    var value = digit.value.replace(/\D/g, '');
                    if (value.length > 1) {
                        setCode(value);
                        digits[Math.min(value.length, digits.length - 1)].focus();
                        return;
                    }

                    digit.value = value.slice(0, 1);
                    updateCombinedCode();
                    if (digit.value && index < digits.length - 1) {
                        digits[index + 1].focus();
                    }
                });

                digit.addEventListener('paste', function (event) {
                    event.preventDefault();
                    var clipboardText = event.clipboardData ? event.clipboardData.getData('text') : '';
                    setCode(clipboardText);
                    digits[Math.min(combinedCode.value.length, digits.length - 1)].focus();
                });

                digit.addEventListener('keydown', function (event) {
                    if (event.key === 'Backspace' && digit.value === '' && index > 0) {
                        event.preventDefault();
                        digits[index - 1].focus();
                    } else if (event.key === 'ArrowLeft' && index > 0) {
                        event.preventDefault();
                        digits[index - 1].focus();
                    } else if (event.key === 'ArrowRight' && index < digits.length - 1) {
                        event.preventDefault();
                        digits[index + 1].focus();
                    }
                });
            });

            form.addEventListener('submit', updateCombinedCode);
        });
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
