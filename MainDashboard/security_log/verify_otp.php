<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .resend-link {
            margin-top: 15px;
            text-align: center;
            font-size: 14px;
        }
        .resend-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .resend-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>OTP Verification</h2>
        <p class="login-subtitle">We sent a 6-digit code to <?php echo isset($_SESSION['session_email']) ? $_SESSION['session_email'] : 'your email'; ?></p>
        
        <form class="login-form" action="check_otp.php" method="POST">
            <div class="form-group">
                <label for="user_otp">Enter OTP Code</label>
                <input type="text" id="user_otp" name="user_otp" required placeholder="123456" maxlength="6" pattern="\d{6}">
            </div>
            <button type="submit" name="verify" class="submit-btn">Verify & Login</button>
        </form>
        
        <div class="resend-link">
            <a href="login_register.html">Resend OTP</a>
        </div>
    </div>
</body>
</html>

