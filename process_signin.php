<?php
session_start();
require_once 'ClassAutoLoad.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $role = $_POST['role']; // donor or hospital

    if (empty($email) || empty($password) || empty($role)) {
        $_SESSION['banner'] = ['type' => 'error', 'message' => 'Please fill all fields.'];
        header("Location: signin.php");
        exit();
    }

    if ($role === 'donor') {
        $stmt = $conn->prepare("SELECT * FROM donors WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $donor = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($donor && password_verify($password, $donor['password'])) {
            if ($donor['is_verified'] == 0) {
                $_SESSION['banner'] = ['type' => 'warning', 'message' => 'Please verify your donor account first.'];
                header("Location: auth/verify_donor.php?email=" . urlencode($email));
                exit();
            }

            $_SESSION['donor_id'] = $donor['id'];
            $_SESSION['donor_name'] = $donor['fullname'];
            header("Location: donor_dashboard.php");
            exit();
        } else {
            $_SESSION['banner'] = ['type' => 'error', 'message' => 'Invalid donor credentials.'];
            header("Location: signin.php");
            exit();
        }
    } elseif ($role === 'hospital') {
        $stmt = $conn->prepare("SELECT * FROM hospitals WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $hospital = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($hospital && password_verify($password, $hospital['password'])) {
            if ($hospital['is_verified'] == 0) {
                $_SESSION['banner'] = ['type' => 'warning', 'message' => 'Please verify your hospital account first.'];
                header("Location: auth/verify_hospital.php?email=" . urlencode($email));
                exit();
            }

            $_SESSION['hospital_id'] = $hospital['id'];
            $_SESSION['hospital_name'] = $hospital['hospital_name'];
            header("Location: hospital_dashboard.php");
            exit();
        } else {
            $_SESSION['banner'] = ['type' => 'error', 'message' => 'Invalid hospital credentials.'];
            header("Location: signin.php");
            exit();
        }
    }
} else {
    header("Location: signin.php");
    exit();
}
?>
