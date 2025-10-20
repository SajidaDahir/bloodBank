<?php
session_start();
require_once '../ClassAutoLoad.php';

$email = isset($_GET['email']) ? $_GET['email'] : '';
if (empty($email)) {
    header("Location: ../hospital_registration.php");
    exit();
}

$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['verification_code']);

    if (empty($code)) {
        $message = '⚠️ Please enter the verification code.';
        $messageClass = 'error';
    } else {
        $stmt = $conn->prepare("SELECT verification_code FROM hospitals WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $hospital = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hospital) {
            $message = '⚠️ Invalid email address.';
            $messageClass = 'error';
        } elseif ($hospital['verification_code'] == $code) {
            $stmt = $conn->prepare("UPDATE hospitals SET is_verified = 1, verification_code = NULL WHERE email = :email");
            $stmt->execute([':email' => $email]);

            $message = '✅ Your hospital account has been successfully verified! <a href="../signin.php" class="link">You can now log in</a>.';
            $messageClass = 'success';
        } else {
            $message = '⚠️ Incorrect code. Please try again.';
            $messageClass = 'error';
        }
    }
}

// Page title
$conf['page_title'] = 'BloodBank | Verify Hospital Account';
$Objlayout->header($conf);
?>

<!-- Navbar -->
<nav class="verify-navbar">
    <span class="navbar-title"><?php echo $conf['page_title']; ?></span>
    <a href="../index.php" class="btn-home">Home</a>
</nav>

<!-- External CSS for footer & general styles -->
<link rel="stylesheet" href="../CSS/style.css">

<!-- Inline styles for this page -->
<style>
/* Navbar */
nav.verify-navbar {
    display: flex;
    justify-content: space-between; /* title left, home right */
    align-items: center;
    padding: 15px 30px;
    background: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-radius: 8px;
    margin-bottom: 30px;
}

nav.verify-navbar .navbar-title {
    font-weight: 700;
    font-size: 18px;
    color: #e63946; /* red theme */
}

nav.verify-navbar .btn-home {
    text-decoration: none;
    color: #e63946; /* red theme */
    font-weight: 600;
}

/* Verify container */
.verify-container {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    padding: 40px;
    max-width: 450px;
    width: 100%;
    text-align: center;
    margin: 50px auto;
}

.verify-container h2 {
    color: #e63946;
    margin-bottom: 15px;
}

.verify-container p {
    color: #555;
    margin-bottom: 25px;
}

/* Input */
.form-input {
    width: 80%;
    padding: 12px;
    border-radius: 10px;
    border: 1px solid #ddd;
    text-align: center;
    font-size: 18px;
    letter-spacing: 5px;
    margin-bottom: 20px;
    outline: none;
}

/* Button */
.btn-submit {
    background: #e63946;
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 12px 20px;
    font-weight: 700;
    cursor: pointer;
    transition: 0.3s;
}

.btn-submit:hover {
    background: #c0392b;
}

/* Banner messages */
.banner.success {
    background: #e63946;
    color: #fff;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.banner.error {
    background: #f8d7da;
    color: #842029;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}

/* Link inside banner */
.banner a.link {
    color: #fff;
    text-decoration: underline;
    font-weight: 700;
}

/* Note and links */
.note {
    font-size: 14px;
    color: #888;
    margin-top: 15px;
}

.note a {
    color: #e63946;
    text-decoration: underline;
    font-weight: 600;
}

/* Mobile responsiveness */
@media(max-width: 500px){
    .verify-container {
        padding: 25px;
    }
    .form-input {
        width: 100%;
    }
}
</style>

<div class="form-page">
    <div class="form-container verify-container">
        <h2>Verify Your Hospital Account</h2>
        <p>We’ve sent a 6-digit code to: <strong><?php echo htmlspecialchars($email); ?></strong></p>

        <?php if (!empty($message)): ?>
            <div class="banner <?php echo $messageClass; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="verification_code" maxlength="6" placeholder="Enter 6-digit code" required class="form-input">
            <button type="submit" class="btn-submit">Verify Account</button>
        </form>

        <p class="note">Didn't get the code? <a href="hospital_signup.php?email=<?php echo urlencode($email); ?>">Resend</a></p>
    </div>
</div>

<?php
$Objlayout->footer($conf);
?>
