<?php
require_once '../ClassAutoLoad.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname   = trim($_POST['fullname']);
    $email      = trim($_POST['email']);
    $password   = $_POST['password'];
    $phone      = trim($_POST['phone']);
    $blood_type = trim($_POST['blood_type']);
    $location   = trim($_POST['location']);

    // Basic validation
    if (empty($fullname) || empty($email) || empty($password) || empty($phone) || empty($blood_type) || empty($location)) {
        $_SESSION['banner'] = [
            'type' => 'error',
            'message' => '⚠️ All fields are required!'
        ];
        header("Location: ../donor_registration.php");
        exit();
    }

    try {
        // Check if donor already exists
        $stmt = $conn->prepare("SELECT id FROM donors WHERE email = :email");
        $stmt->execute([':email' => $email]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['banner'] = [
                'type' => 'warning',
                'message' => '⚠️ This email is already registered. Please log in instead.'
            ];
            header("Location: ../donor_registration.php");
            exit();
        }

        // Hash password securely
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        // Generate 6-digit verification code
        $verification_code = random_int(100000, 999999);

        // Insert new donor
        $stmt = $conn->prepare("
            INSERT INTO donors (fullname, email, password, phone, blood_type, location, verification_code, is_verified)
            VALUES (:fullname, :email, :password, :phone, :blood_type, :location, :verification_code, 0)
        ");
        $stmt->execute([
            ':fullname'          => $fullname,
            ':email'             => $email,
            ':password'          => $hashedPassword,
            ':phone'             => $phone,
            ':blood_type'        => $blood_type,
            ':location'          => $location,
            ':verification_code' => $verification_code
        ]);

        // Save email in session for verification
        $_SESSION['email'] = $email;

        // Prepare 2FA Email
        $mailContent = [
            'name_from'  => $conf['site_name'],
            'email_from' => $conf['smtp_user'],
            'name_to'    => $fullname,
            'email_to'   => $email,
            'subject'    => 'Verify Your Blood Bank Donor Account',
            'body'       => "
                <h3>Hello $fullname,</h3>
                <p>Welcome to <b>{$conf['site_name']}</b>!</p>
                <p>Your 6-digit verification code is:</p>
                <h2 style='color:#e63946;'>$verification_code</h2>
                <p>Please enter this code on the verification page to activate your donor account.</p>
                <p>If you did not register, please ignore this email.</p>
                <br><p>Kind regards,<br>{$conf['site_name']} Team</p>
            "
        ];

        // Send email (without echo)
        try {
            $ObjSendMail->Send_Mail($conf, $mailContent);
        } catch (Exception $e) {
            // Fail silently, user can still verify manually
        }

        // Redirect to verification page
        header("Location: ../auth/verify_donor.php?email=" . urlencode($email));
        exit();

    } catch (PDOException $e) {
        $_SESSION['banner'] = [
            'type' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ];
        header("Location: ../donor_registration.php");
        exit();
    }
} else {
    $_SESSION['banner'] = [
        'type' => 'error',
        'message' => 'Invalid request method.'
    ];
    header("Location: ../donor_registration.php");
    exit();
}
?>
