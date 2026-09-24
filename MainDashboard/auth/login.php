<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../security_log/otp_functions.php';

if (isLoggedIn()) {
    redirect('/modules/dashboard/index.php');
}

$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['lockout_count']  = $_SESSION['lockout_count']  ?? 0;
$_SESSION['lockout_until']  = $_SESSION['lockout_until']  ?? 0;

$maxAttempts    = 5;
$baseLockoutSec = 30;
$stepSec        = 15;

// =====================================================================
// OTP (2FA) stage — the security_log flow connected to this login page.
// After a valid username/password the login is put on hold in
// $_SESSION['2fa_pending'] until the e-mailed 6-digit code is checked.
// =====================================================================
$pending      = $_SESSION['2fa_pending'] ?? null;
$stage        = $_GET['stage'] ?? '';
$showOtpStage = $pending !== null && $stage === 'otp';

// Epoch second until which the OTP can be verified (2-minute validity).
$otpVerifyDeadline = $showOtpStage
    ? (int) ($_SESSION['session_otp_expires'] ?? 0)
    : 0;

// "Use a different account" — abandon the held login.
if ($stage === 'cancel') {
    unset(
        $_SESSION['2fa_pending'],
        $_SESSION['otp_dev_fallback'],
        $_SESSION['otp_error'],
        $_SESSION['session_otp'],
        $_SESSION['session_otp_expires'],
        $_SESSION['session_email']
    );
    redirect('/auth/login.php');
}

// A login already on hold must always be finished through the OTP screen.
if ($pending !== null && $stage !== 'otp' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    redirect('/auth/login.php?stage=otp');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (time() < $_SESSION['lockout_until']) {
        $remaining = $_SESSION['lockout_until'] - time();
        $error = "Too many failed attempts. Try again in {$remaining} second(s).";
    } elseif (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {

        // ------------------ Step 2: check the OTP ------------------
        if (isset($_POST['verify_otp'])) {
            if ($pending === null) {
                $error = 'Your login session expired. Please enter your credentials again.';
                $showOtpStage = false;
            } else {
                $otpCheck = verify_otp(trim($_POST['user_otp'] ?? ''));

                if ($otpCheck['valid']) {
                    unset(
                        $_SESSION['2fa_pending'],
                        $_SESSION['otp_dev_fallback'],
                        $_SESSION['otp_error'],
                        $_SESSION['session_email']
                    );

                    session_regenerate_id(true);

                    $_SESSION['user_id']     = $pending['user_id'];
                    $_SESSION['username']    = $pending['username'];
                    $_SESSION['role']        = $pending['role'];
                    $_SESSION['employee_id'] = $pending['employee_id'];
                    $_SESSION['full_name']   = $pending['full_name'];
                    require_once __DIR__ . '/../config/security.php';
                    audit($pdo,'LOGIN_SUCCESS','users',$pending['user_id'],'Successful login with OTP');
                    redirect('/modules/dashboard/index.php');
                } else {
                    $error = $otpCheck['reason'];
                }
            }

        // ------------------- Resend the OTP -------------------
        } elseif (isset($_POST['resend_otp'])) {
            if ($pending === null) {
                $error = 'Your login session expired. Please enter your credentials again.';
                $showOtpStage = false;
            } else {
                $send = send_otp_email($pending['email']);
                $_SESSION['otp_dev_fallback'] = !$send['success'];

                if ($send['success']) {
                    unset($_SESSION['otp_error']);
                } else {
                    $_SESSION['otp_error'] = 'OTP e-mail could not be delivered: ' . $send['message'];
                }

                redirect('/auth/login.php?stage=otp');
            }

        // ------------ Step 1: username / password login ------------
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($username === '' || $password === '') {
                $error = 'Please enter both username and password.';
            } else {
                $stmt = $pdo->prepare("SELECT u.*, e.first_name, e.last_name, e.email FROM users u
                                        LEFT JOIN employees e ON u.employee_id = e.employee_id
                                        WHERE u.username = ? AND u.status = 'Active'");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['lockout_count']  = 0;
                    $_SESSION['lockout_until']  = 0;

                    $email = trim($user['email'] ?? '');
                    if (($user['role'] ?? '') === 'Super Admin') {
                        try {
                            $settingStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='superadmin_gmail' LIMIT 1");
                            $settingStmt->execute();
                            $configuredGmail = trim((string)$settingStmt->fetchColumn());
                            if ($configuredGmail !== '') {
                                $email = $configuredGmail;
                            }
                        } catch (Throwable $e) {
                            // Keep the profile email as fallback; the Gmail-only check below applies.
                        }
                    }

                    // Super Admin must always use Gmail OTP verification.
                    // Other roles continue through the existing e-mail OTP flow when an
                    // e-mail address is available.
                    $isSuperAdminLogin = (($user['role'] ?? '') === 'Super Admin');

                    if ($isSuperAdminLogin && ($email === '' || !preg_match('/@gmail\.com$/i', $email))) {
                        $error = 'Super Admin login requires a valid Gmail address for verification.';
                    } elseif ($email === '') {
                        session_regenerate_id(true);

                        $_SESSION['user_id']     = $user['user_id'];
                        $_SESSION['username']    = $user['username'];
                        $_SESSION['role']        = $user['role'];
                        $_SESSION['employee_id'] = $user['employee_id'];
                        $_SESSION['full_name']   = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                        require_once __DIR__ . '/../config/security.php';
                        audit($pdo,'LOGIN_SUCCESS','users',$user['user_id'],'Successful login without OTP (no email on profile)');
                        redirect('/modules/dashboard/index.php');
                    }

                    // Hold the login until the 6-digit e-mail code is verified.
                    $_SESSION['2fa_pending'] = [
                        'user_id'     => $user['user_id'],
                        'username'    => $user['username'],
                        'role'        => $user['role'],
                        'employee_id' => $user['employee_id'],
                        'full_name'   => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                        'email'       => $email,
                    ];

                    $send = send_otp_email($email);
                    $_SESSION['otp_dev_fallback'] = !$send['success'];

                    if ($send['success']) {
                        unset($_SESSION['otp_error']);
                    } else {
                        $_SESSION['otp_error'] = 'OTP e-mail could not be delivered: ' . $send['message'];
                    }

                    redirect('/auth/login.php?stage=otp');
                } else {
                    $_SESSION['login_attempts']++;

                    if ($_SESSION['login_attempts'] >= $maxAttempts) {
                        $lockoutSecs = $baseLockoutSec + ($_SESSION['lockout_count'] * $stepSec);
                        $_SESSION['lockout_until']  = time() + $lockoutSecs;
                        $_SESSION['lockout_count']++;
                        $_SESSION['login_attempts'] = 0;

                        $error = "Too many failed attempts. Try again in {$lockoutSecs} second(s).";
                    } else {
                        $error = 'Invalid username or password.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | GREATE SOLOMON MANPOWER SERVICES INC.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    <style>
        :root {
            --deep-950: #1e1b4b;
            --deep-800: #312e81;
            --deep-700: #4338ca;
            --accent: #6366f1;
            --accent-light: #a5b4fc;
            --cream: #f8f8fc;
            --ink: #1e1b3a;
            --muted: #6b7280;
            --line: #e5e3f5;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --danger-text: #b91c1c;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            color: var(--ink);
            background: var(--cream);
        }

        .auth-shell {
            display: flex;
            min-height: 100vh;
        }

        /* ---------- LEFT: SLIDESHOW ---------- */
        .auth-stage {
            position: relative;
            flex: 1.1;
            background: linear-gradient(155deg, var(--deep-950) 0%, var(--deep-800) 55%, var(--deep-700) 100%);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 48px;
            color: #fff;
        }

        .auth-stage .net-layer {
            position: absolute;
            inset: 0;
            opacity: .5;
            pointer-events: none;
        }

        .auth-stage .net-layer svg {
            width: 100%;
            height: 100%;
        }

        .net-line {
            stroke: rgba(165, 180, 252, .35);
            stroke-width: 1;
            stroke-dasharray: 6 10;
            animation: dash 14s linear infinite;
        }

        .net-dot {
            fill: rgba(199, 210, 254, .6);
        }

        .net-dot.pulse {
            animation: pulse 3.2s ease-in-out infinite;
        }

        @keyframes dash {
            to {
                stroke-dashoffset: -320;
            }
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: .5;
                transform: scale(1);
            }

            50% {
                opacity: 1;
                transform: scale(1.4);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .net-line {
                animation: none;
            }

            .net-dot.pulse {
                animation: none;
            }
        }

        .stage-brand {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stage-brand .mark {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--deep-950);
            font-size: 14px;
        }

        .stage-brand .name {
            font-weight: 600;
            font-size: 15px;
            letter-spacing: .02em;
        }

        .stage-content {
            position: relative;
            z-index: 2;
            max-width: 460px;
        }

        .slide {
            display: none;
        }

        .slide.active {
            display: block;
            animation: fadeUp .5s ease both;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .slide .slide-icon {
            width: 52px;
            height: 52px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 22px;
            background: rgba(255, 255, 255, .05);
        }

        .slide h1 {
            font-family: 'Instrument Serif', serif;
            font-weight: 400;
            font-style: italic;
            font-size: 38px;
            line-height: 1.15;
            margin: 0 0 14px;
        }

        .slide p {
            font-size: 15px;
            line-height: 1.6;
            color: rgba(255, 255, 255, .7);
            margin: 0;
            max-width: 380px;
        }

        .stage-footer {
            position: relative;
            z-index: 2;
        }

        .dots {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
        }

        .dot {
            width: 22px;
            height: 3px;
            border-radius: 2px;
            background: rgba(255, 255, 255, .22);
            border: none;
            cursor: pointer;
            padding: 0;
            transition: background .2s ease;
        }

        .dot.active {
            background: var(--accent-light);
        }

        .stage-footer .tagline {
            font-size: 12.5px;
            color: rgba(255, 255, 255, .45);
            letter-spacing: .02em;
        }

        /* ---------- RIGHT: FORM ---------- */
        .auth-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 32px;
            min-width: 380px;
        }

        .auth-form-wrap {
            width: 100%;
            max-width: 360px;
        }

        .form-eyebrow {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--accent);
            margin: 0 0 8px;
        }

        .form-title {
            font-size: 26px;
            font-weight: 700;
            margin: 0 0 6px;
            color: var(--ink);
        }

        .form-sub {
            font-size: 14px;
            color: var(--muted);
            margin: 0 0 32px;
        }

        .alert {
            padding: 11px 14px;
            border-radius: 8px;
            font-size: 13.5px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .alert-error {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
        }

        .alert-warn {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
        }

        .dev-otp {
            display: block;
            margin-top: 8px;
            font-size: 13px;
        }

        .dev-otp strong {
            font-size: 18px;
            letter-spacing: .12em;
            color: var(--deep-700);
        }

        #user_otp {
            text-align: center;
            font-size: 18px;
            font-weight: 600;
            letter-spacing: .35em;
        }

        .otp-actions {
            margin-top: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .otp-actions form {
            margin: 0;
        }

        .countdown {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            margin-bottom: 20px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
        }

        .countdown-icon {
            color: var(--accent);
            flex-shrink: 0;
        }

        .countdown-label {
            flex: 1;
            font-size: 13px;
            color: var(--muted);
        }

        .countdown-time {
            font-size: 20px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: var(--deep-700);
        }

        .countdown.is-expiring .countdown-time {
            color: var(--danger-text);
        }

        .countdown.is-expired .countdown-time {
            display: none;
        }

        .countdown-expired {
            font-size: 13px;
            font-weight: 600;
            color: var(--danger-text);
        }

        .link-btn {
            background: none;
            border: none;
            padding: 0;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--accent);
            cursor: pointer;
            text-decoration: none;
        }

        .link-btn:hover {
            text-decoration: underline;
            color: var(--deep-700);
        }

        .field {
            margin-bottom: 18px;
        }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 7px;
        }

        .field .control {
            position: relative;
        }

        .field input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--line);
            border-radius: 9px;
            font-size: 14.5px;
            font-family: inherit;
            color: var(--ink);
            background: #fff;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .field input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .15);
        }

        .field.has-toggle input {
            padding-right: 42px;
        }

        .toggle-eye {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted);
            border-radius: 6px;
        }

        .toggle-eye:hover {
            color: var(--ink);
            background: #f0efff;
        }

        .btn-primary {
            width: 100%;
            padding: 12px 16px;
            border: none;
            border-radius: 9px;
            background: var(--accent);
            color: #fff;
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s ease;
        }

        .btn-primary:hover {
            background: var(--deep-700);
        }

        .form-foot {
            margin-top: 24px;
            text-align: center;
            font-size: 12.5px;
            color: var(--muted);
        }

        @media (max-width:860px) {
            .auth-stage {
                display: none;
            }

            .auth-panel {
                min-width: 0;
                padding: 32px 20px;
            }
        }

        /* Great Solomon Manpower Services Inc. logo */
        .stage-logo {
            width: 46px;
            height: 46px;
            object-fit: contain;
            display: block;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, .18));
        }

        .brand-icon-image {
            width: 64px;
            height: 64px;
            object-fit: contain;
            display: block;
            margin: 0 auto 8px;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, .18));
        }
    </style>
</head>

<body>
    <div class="auth-shell">

        <div class="auth-stage">
            <div class="net-layer">
                <svg viewBox="0 0 500 700" preserveAspectRatio="xMidYMid slice">
                    <line class="net-line" x1="40" y1="80" x2="220" y2="180" />
                    <line class="net-line" x1="220" y1="180" x2="120" y2="320" />
                    <line class="net-line" x1="220" y1="180" x2="380" y2="240" />
                    <line class="net-line" x1="120" y1="320" x2="60" y2="470" />
                    <line class="net-line" x1="120" y1="320" x2="260" y2="440" />
                    <line class="net-line" x1="380" y1="240" x2="420" y2="400" />
                    <line class="net-line" x1="260" y1="440" x2="420" y2="400" />
                    <line class="net-line" x1="260" y1="440" x2="200" y2="600" />
                    <circle class="net-dot pulse" cx="40" cy="80" r="4" />
                    <circle class="net-dot" cx="220" cy="180" r="4" />
                    <circle class="net-dot pulse" cx="380" cy="240" r="3.5" />
                    <circle class="net-dot" cx="120" cy="320" r="4" />
                    <circle class="net-dot pulse" cx="420" cy="400" r="4" />
                    <circle class="net-dot" cx="60" cy="470" r="3.5" />
                    <circle class="net-dot pulse" cx="260" cy="440" r="4" />
                    <circle class="net-dot" cx="200" cy="600" r="3.5" />
                </svg>
            </div>

            <div class="stage-brand">
                <img class="stage-logo"
                    src="<?= assetUrl('assets/images/gsm-logo-mark.png') ?>"
                    alt="Great Solomon Manpower Services Inc.">
                <div class="name">Great Solomon Manpower Services Inc.</div>
            </div>

            <div class="stage-content">
                <div class="slide active" data-slide="0">
                    <div class="slide-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="1.6">
                            <rect x="3" y="6" width="18" height="12" rx="2" />
                            <path d="M3 10h18" />
                            <circle cx="8" cy="14.5" r="1.2" fill="#a5b4fc" stroke="none" />
                        </svg>
                    </div>
                    <h1>Payday, without the guesswork.</h1>
                    <p>Basic pay, overtime, deductions, and holiday rules computed automatically from attendance on every payslip and every cycle.</p>
                </div>
                <div class="slide" data-slide="1">
                    <div class="slide-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="1.6">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 7v5l3.5 2" />
                        </svg>
                    </div>
                    <h1>Attendance, tracked to the minute.</h1>
                    <p>Late arrivals, overtime, and absences flow straight into payroll and no manual reconciling at month-end.</p>
                </div>
                <div class="slide" data-slide="2">
                    <div class="slide-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="1.6">
                            <rect x="3" y="4" width="18" height="17" rx="2" />
                            <path d="M3 9h18M8 2v4M16 2v4" />
                        </svg>
                    </div>
                    <h1>Leave requests, sorted.</h1>
                    <p>Employees files, balances update automatically, and HR approves are all in one place, no paper forms.</p>
                </div>
                <div class="slide" data-slide="3">
                    <div class="slide-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#a5b4fc" stroke-width="1.6">
                            <circle cx="9" cy="8" r="3" />
                            <path d="M2 21v-1a6 6 0 0 1 12 0v1" />
                            <circle cx="18" cy="9" r="2.5" />
                            <path d="M15.5 21v-1a4.5 4.5 0 0 1 7 0v1" />
                        </svg>
                    </div>
                    <h1>Every employee record, current.</h1>
                    <p>From date hired to department history, one source of truth your whole HR team can trust.</p>
                </div>
            </div>

            <div class="stage-footer">
                <div class="dots" id="slideDots">
                    <button class="dot active" data-goto="0"></button>
                    <button class="dot" data-goto="1"></button>
                    <button class="dot" data-goto="2"></button>
                    <button class="dot" data-goto="3"></button>
                </div>
                <div class="tagline">Human Resource Information System</div>
            </div>
        </div>

        <div class="auth-panel">
            <div class="auth-form-wrap">

                <?php if ($showOtpStage): ?>

                    <p class="form-eyebrow">Security verification</p>
                    <h2 class="form-title">Enter your OTP</h2>
                    <p class="form-sub">We sent a 6-digit code to <strong><?= htmlspecialchars(mask_email($pending['email'])) ?></strong>. Enter it below to finish logging in.</p>

                    <div class="countdown" id="otpCountdownBox" data-deadline="<?= (int) $otpVerifyDeadline ?>">
                        <svg class="countdown-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 7v5l3.5 2" />
                        </svg>
                        <span class="countdown-label">Time remaining to verify</span>
                        <strong class="countdown-time" id="otpCountdown">--:--</strong>
                        <span class="countdown-expired" id="otpCountdownExpired" hidden>Code expired — please resend a new code.</span>
                    </div>

                    <?php if (!empty($_SESSION['otp_error'])): ?>
                        <div class="alert alert-warn">
                            <?= htmlspecialchars($_SESSION['otp_error']) ?>
                            <?php if (!empty($_SESSION['otp_dev_fallback'])): ?>
                                <span class="dev-otp">Demo fallback — use this code instead:
                                    <strong><?= htmlspecialchars($_SESSION['session_otp'] ?? '') ?></strong></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                        <div class="field">
                            <label for="user_otp">6-digit code</label>
                            <div class="control">
                                <input type="text" id="user_otp" name="user_otp" inputmode="numeric" maxlength="6" pattern="\d{6}" required autofocus placeholder="123456">
                            </div>
                        </div>

                        <button type="submit" name="verify_otp" class="btn-primary">Verify &amp; Log In</button>
                    </form>

                    <div class="otp-actions">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" name="resend_otp" class="link-btn">Resend code</button>
                        </form>
                        <a class="link-btn" href="<?= siteUrl('auth/login.php?stage=cancel') ?>">Use a different account</a>
                    </div>

                <?php else: ?>

                    <p class="form-eyebrow">Welcome back</p>
                    <h2 class="form-title">Log in to your account</h2>
                    <p class="form-sub">Enter your credentials to continue.</p>

                    <?php if ($error): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                        <div class="field">
                            <label for="username">Username</label>
                            <div class="control">
                                <input type="text" id="username" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="field has-toggle">
                            <label for="password">Password</label>
                            <div class="control">
                                <input type="password" id="password" name="password" required>
                                <button type="button" class="toggle-eye" id="togglePassword" aria-label="Show password">
                                    <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary">Log In</button>
                    </form>

                    <p class="form-foot"></p>

                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        (function() {
            const btn = document.getElementById('togglePassword');
            const input = document.getElementById('password');
            const eye = document.getElementById('eyeIcon');
            if (!btn || !input || !eye) {
                return; // OTP screen — there is no password field.
            }
            const eyeOpen = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/>';
            const eyeClosed = '<path d="M3 3l18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.1A10.9 10.9 0 0 1 12 5c7 0 11 7 11 7a17.6 17.6 0 0 1-3.2 4.1M6.1 6.1C3.4 7.9 1 12 1 12s4 7 11 7a10.6 10.6 0 0 0 4.2-.9"/>';
            btn.addEventListener('click', function() {
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                eye.innerHTML = isHidden ? eyeClosed : eyeOpen;
                btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        })();

        (function() {
            const box = document.getElementById('otpCountdownBox');
            if (!box) {
                return; // Not the OTP screen.
            }

            const timeEl   = document.getElementById('otpCountdown');
            const expiredEl = document.getElementById('otpCountdownExpired');
            const verifyBtn = document.querySelector('button[name="verify_otp"]');
            const deadline  = parseInt(box.dataset.deadline, 10);
            if (!timeEl || !deadline) {
                return;
            }

            const WARN_AFTER = 30; // turn red during the final 30 seconds
            let timer = null;

            function pad(n) {
                return String(n).padStart(2, '0');
            }

            function render() {
                const remaining = Math.max(0, deadline - Math.floor(Date.now() / 1000));
                const minutes   = Math.floor(remaining / 60);
                const seconds   = remaining % 60;

                timeEl.textContent = pad(minutes) + ':' + pad(seconds);

                if (remaining <= 0) {
                    clearInterval(timer);
                    box.classList.remove('is-expiring');
                    box.classList.add('is-expired');
                    timeEl.textContent = '00:00';
                    if (expiredEl) {
                        expiredEl.hidden = false;
                    }
                    if (verifyBtn) {
                        verifyBtn.disabled = true;
                        verifyBtn.setAttribute('aria-disabled', 'true');
                    }
                    return;
                }

                box.classList.toggle('is-expiring', remaining <= WARN_AFTER);
            }

            render();
            timer = setInterval(render, 1000);
        })();

        (function() {
            const slides = document.querySelectorAll('.slide');
            const dots = document.querySelectorAll('.dot');
            let current = 0;
            let timer;

            function goTo(i) {
                slides[current].classList.remove('active');
                dots[current].classList.remove('active');
                current = i;
                slides[current].classList.add('active');
                dots[current].classList.add('active');
            }

            function next() {
                goTo((current + 1) % slides.length);
            }

            function startAuto() {
                clearInterval(timer);
                timer = setInterval(next, 5000);
            }

            dots.forEach(function(dot) {
                dot.addEventListener('click', function() {
                    goTo(parseInt(dot.dataset.goto, 10));
                    startAuto();
                });
            });

            startAuto();
        })();
    </script>
</body>

</html>