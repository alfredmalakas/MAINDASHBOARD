<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';
require 'PHPMailer-master/src/Exception.php';

if (!defined('OTP_VALIDITY_SECONDS')) {
    define('OTP_VALIDITY_SECONDS', 120);
}

if (isset($_POST['send_otp'])) {
    $email = $_POST['email'];
    
    // Generate a random 6-digit OTP
    $otp = rand(100000, 999999);
    
    // Store the OTP and Email in session variables
    $_SESSION['session_otp'] = $otp;
    $_SESSION['session_email'] = $email;
    $_SESSION['session_otp_expires'] = time() + OTP_VALIDITY_SECONDS;
    
    $mail = new PHPMailer(true);
    $mail->SMTPDebug = 2; // Enable verbose debug output

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'cajotealfredourgel60@gmail.com'; 
        $mail->Password = 'eednrwawvsoxyvhh';
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;

        $mail->setFrom('Cajotealfredourgel60@gmail.com', 'Security Log System');
        $mail->addAddress($email);

        $mail->Subject = 'Your OTP Code';
        $mail->Body = "Your One-Time Password is: " . $otp . "\n\nIt will expire in 2 minutes.\n\nPlease do not share this code with anyone.";

        $mail->send();

        // If email sends successfully, redirect to the verification page
        header("Location: verify_otp.php");
        exit();

    } catch (Exception $e) {
        echo "Failed to send OTP email. Error: {$mail->ErrorInfo}";
    }
}
?>

