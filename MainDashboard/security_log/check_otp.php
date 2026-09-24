<?php
session_start();
require_once __DIR__ . '/otp_functions.php';

if (isset($_POST['verify'])) {
    $user_entered_otp = $_POST['user_otp'];
    $otpCheck = verify_otp(trim($user_entered_otp));

    if ($otpCheck['valid']) {
        $success = true;
    } else {
        $error = $otpCheck['reason'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .result-container {
            text-align: center;
        }
        .result-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        .result-message {
            font-size: 18px;
            margin-bottom: 30px;
        }
        .back-link {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
        }
        .back-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <?php if (isset($success) && $success): ?>
            <div class="result-container">
                <div class="result-icon">✓</div>
                <h2>Success!</h2>
                <p class="result-message">OTP Verified Successfully.<br>You are now logged in.</p>
                <a href="login_register.html" class="back-link">Go to Dashboard</a>
            </div>
        <?php elseif (isset($error)): ?>
            <div class="result-container">
                <div class="result-icon" style="color: #dc3545;">✗</div>
                <h2>Verification Failed</h2>
                <p class="result-message"><?php echo $error; ?></p>
                <a href="verify_otp.php" class="back-link">Try Again</a>
            </div>
        <?php else: ?>
            <div class="result-container">
                <p class="result-message">Invalid access. Please start from the login page.</p>
                <a href="login_register.html" class="back-link">Go to Login</a>
            </div>
        <?php endif; ?>
    </div>
    
    <?php
    // verify_otp() already clears the OTP after successful verification.
    ?>
</body>
</html>

