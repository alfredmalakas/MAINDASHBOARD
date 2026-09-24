<?php
/**
 * Shared OTP helper.
 *
 * Used by auth/login.php (2FA step) and kept compatible with the standalone
 * security_log demo pages (send_otp.php / verify_otp.php / check_otp.php).
 *
 * Session keys used:
 *   $_SESSION['session_otp']          - the most recently issued 6-digit code
 *   $_SESSION['session_email']        - the address the code was sent to
 *   $_SESSION['session_otp_expires']  - Unix timestamp when the code expires
 *                                     (the code is valid only until this timestamp.)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Lifetime of an OTP from the moment it is issued (seconds). */
if (!defined('OTP_VALIDITY_SECONDS')) {
    define('OTP_VALIDITY_SECONDS', 120);
}

/** No grace period: the OTP expires exactly at its 2-minute deadline. */
if (!defined('OTP_GRACE_SECONDS')) {
    define('OTP_GRACE_SECONDS', 0);
}

/** True once PHPMailer has been loaded into this request. */
$otpMailerLoaded = false;

function otp_load_mailer()
{
    global $otpMailerLoaded;

    if ($otpMailerLoaded) {
        return;
    }

    require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';
    require_once __DIR__ . '/PHPMailer-master/src/Exception.php';

    $otpMailerLoaded = true;
}

/** Generate a random 6-digit one-time password. */
function generate_otp()
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Create an OTP, store it in the session and send it to $email.
 *
 * @param string $email Recipient e-mail address.
 * @return array{success: bool, message: string, otp: string}
 */
function send_otp_email($email)
{
    $otp = generate_otp();

    // A code is valid for exactly 2 minutes from the moment it is issued.
    $_SESSION['session_otp']          = $otp;
    $_SESSION['session_email']        = $email;
    $_SESSION['session_otp_expires']  = time() + OTP_VALIDITY_SECONDS;

    otp_load_mailer();

    // Declared outside the try so the catch blocks can safely reference it
    // even when the PHPMailer constructor itself throws.
    $mail = null;

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'cajotealfredourgel60@gmail.com';
        $mail->Password   = 'eednrwawvsoxyvhh';
        $mail->SMTPSecure = 'ssl';
        $mail->Port       = 465;

        $mail->setFrom('cajotealfredourgel60@gmail.com', 'Security Log System');
        $mail->addAddress($email);
        $mail->Subject = 'Your OTP Code';
        $mail->Body    = "Your One-Time Password is: {$otp}\n\n"
                       . "It will expire in 2 minutes.\n\n"
                       . "Please do not share this code with anyone.";

        // Fail fast when the SMTP server is unreachable (demo/dev machines
        // usually have no outbound mail, so we don't want login to hang).
        $mail->Timeout = 10;

        $mail->send();

        return ['success' => true, 'message' => 'OTP e-mailed successfully.', 'otp' => $otp];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        // Use the mailer's own SMTP error details where available; fall back to
        // the exception message when the failure happened before $mail was built.
        $errorInfo = ($mail instanceof \PHPMailer\PHPMailer\PHPMailer && $mail->ErrorInfo !== '')
            ? $mail->ErrorInfo
            : $e->getMessage();

        return ['success' => false, 'message' => $errorInfo, 'otp' => $otp];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage(), 'otp' => $otp];
    }
}

/**
 * Validate the code typed in by the user.
 *
 * @param string $userOtp
 * @return array{valid: bool, reason: string}
 */
function verify_otp($userOtp)
{
    $actual  = $_SESSION['session_otp'] ?? null;
    $expires = $_SESSION['session_otp_expires'] ?? 0;

    if ($actual === null) {
        return ['valid' => false, 'reason' => 'No OTP was issued for this session. Please log in again.'];
    }

    // OTP expires exactly at its deadline.
    if (time() >= $expires) {
        unset($_SESSION['session_otp'], $_SESSION['session_otp_expires']);
        return ['valid' => false, 'reason' => 'This OTP has expired. Please resend a new code.'];
    }

    if (!hash_equals((string) $actual, (string) trim($userOtp))) {
        return ['valid' => false, 'reason' => 'Invalid OTP. Please check the code and try again.'];
    }

    // Codes are single-use.
    unset($_SESSION['session_otp'], $_SESSION['session_otp_expires']);

    return ['valid' => true, 'reason' => 'OK'];
}

/**
 * Mask an e-mail address so the account owner can recognise it
 * (e.g. ju********hr@hrisdemo.local) without exposing the full address.
 *
 * @param string $email Full e-mail address to mask.
 * @return string Masked e-mail address.
 */
function mask_email($email)
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return $email;
    }

    $local  = substr($email, 0, $at);
    $domain = substr($email, $at);

    if (strlen($local) <= 2) {
        return $local[0] . '***' . $domain;
    }

    return substr($local, 0, 2) . str_repeat('*', max(3, strlen($local) - 2)) . $domain;
}