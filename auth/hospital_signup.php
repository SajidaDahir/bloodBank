<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../ClassAutoLoad.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hospital_name  = trim($_POST['hospital_name']);
    $email          = trim($_POST['email']);
    $contact_person = trim($_POST['contact_person']);
    $password       = $_POST['password'];
    $phone          = trim($_POST['phone']);
    $city           = trim($_POST['location']); // corrected from $location
    $address        = trim($_POST['address']);

    // ✅ Validate all fields
    if (empty($hospital_name) || empty($email) || empty($password) || empty($phone) || empty($city) || empty($address)) {
        $_SESSION['banner'] = [
            'type' => 'error',
            'message' => '⚠️ All fields are required!'
        ];
        header("Location: ../hospital_registration.php");
        exit();
    }

    try {
        // ✅ Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM hospitals WHERE email = :email");
        $stmt->execute([':email' => $email]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['banner'] = [
                'type' => 'warning',
                'message' => '⚠️ This email is already registered. Please log in instead.'
            ];
            header("Location: ../hospital_registration.php");
            exit();
        }

        // ✅ Securely hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $verification_code = random_int(100000, 999999);

        // ✅ Correct INSERT query
        $stmt = $conn->prepare("
            INSERT INTO hospitals 
            (hospital_name, contact_person, email, phone, city, address, password, verification_code, is_verified)
            VALUES 
            (:hospital_name, :contact_person, :email, :phone, :city, :address, :password, :verification_code, 0)
        ");

        $stmt->execute([
            ':hospital_name'     => $hospital_name,
            ':contact_person'    => $contact_person,
            ':email'             => $email,
            ':phone'             => $phone,
            ':city'              => $city,
            ':address'           => $address,
            ':password'          => $hashedPassword,
            ':verification_code' => $verification_code
        ]);

        $_SESSION['email'] = $email;

        // ✅ Send verification email
        $mailContent = [
            'name_from'  => $conf['site_name'],
            'email_from' => $conf['smtp_user'],
            'name_to'    => $hospital_name,
            'email_to'   => $email,
            'subject'    => 'Verify Your Hospital Account - Blood Bank',
            'body'       => "
                <h3>Hello $hospital_name,</h3>
                <p>Welcome to <b>{$conf['site_name']}</b>!</p>
                <p>Your 6-digit verification code is:</p>
                <h2 style='color:#e63946;'>$verification_code</h2>
                <p>Please enter this code on the verification page to activate your hospital account.</p>
                <br><p>Kind regards,<br>{$conf['site_name']} Team</p>
            "
        ];

        try {
            $ObjSendMail->Send_Mail($conf, $mailContent);
        } catch (Exception $e) {
            // fail silently for email
        }

        header("Location: {$conf['site_url']}/auth/verify_hospital.php?email=" . urlencode($email));
        exit();

    } catch (PDOException $e) {
        $_SESSION['banner'] = [
            'type' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ];
        header("Location: ../hospital_registration.php");
        exit();
    }
} else {
    $_SESSION['banner'] = [
        'type' => 'error',
        'message' => 'Invalid request method.'
    ];
    header("Location: ../hospital_registration.php");
    exit();
}
?>
