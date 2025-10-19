<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader
require_once __DIR__ . '/../plugins/PHPMailer/vendor/autoload.php';

class SendMail
{
    public function Send_Mail($conf, $mailContent)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host       = $conf['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $conf['smtp_user'];
            $mail->Password   = $conf['smtp_pass'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = $conf['smtp_port'];

            // Recipients
            $mail->setFrom($mailContent['email_from'], $mailContent['name_from']);
            $mail->addAddress($mailContent['email_to'], $mailContent['name_to']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $mailContent['subject'];
            $mail->Body    = $mailContent['body'];

            $mail->send();
            echo ' Message has been sent';
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }
}