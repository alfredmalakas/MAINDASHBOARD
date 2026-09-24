<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login / Register</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="login-container">
        <h2>OTP Verification</h2>
        <p class="login-subtitle">Enter your email to receive a verification code</p>
        
        <form class="login-form" action="send_otp.php" method="POST">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required placeholder="name@example.com">
            </div>
            <button type="submit" class="submit-btn" name="send_otp">Send OTP</button>
        </form>
    </div>
</body>
</html>
