<?php
session_start();
require_once 'ClassAutoLoad.php';

// Redirect if not logged in
if (!isset($_SESSION['donor_id'])) {
    header("Location: signin.php");
    exit();
}

$donor_id = $_SESSION['donor_id'];
$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname']);
    $phone = trim($_POST['phone']);
    $blood_type = trim($_POST['blood_type']);
    $location = trim($_POST['location']);

    $stmt = $conn->prepare("UPDATE donors SET fullname = :fullname, phone = :phone, blood_type = :blood_type, location = :location WHERE id = :id");
    $stmt->execute([
        ':fullname' => $fullname,
        ':phone' => $phone,
        ':blood_type' => $blood_type,
        ':location' => $location,
        ':id' => $donor_id
    ]);

    $message = '✅ Profile updated successfully!';
    $messageClass = 'success';
}

// Fetch donor details
$stmt = $conn->prepare("SELECT * FROM donors WHERE id = :id");
$stmt->execute([':id' => $donor_id]);
$donor = $stmt->fetch(PDO::FETCH_ASSOC);

$conf['page_title'] = 'Profile | BloodBank';
$Objlayout->header($conf);
$Objlayout->nav($conf);
?>

<link rel="stylesheet" href="CSS/forms.css">

<section class="form-page">
    <div class="form-container">
        <h2>Edit Your Profile</h2>

        <?php if (!empty($message)): ?>
            <div class="banner <?php echo $messageClass; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="POST">
            <label>Full Name</label>
            <input type="text" name="fullname" value="<?php echo htmlspecialchars($donor['fullname']); ?>" required>

            <label>Phone</label>
            <input type="text" name="phone" value="<?php echo htmlspecialchars($donor['phone']); ?>">

            <label>Blood Type</label>
            <input type="text" name="blood_type" value="<?php echo htmlspecialchars($donor['blood_type']); ?>">

            <label>Location</label>
            <input type="text" name="location" value="<?php echo htmlspecialchars($donor['location']); ?>">

            <button type="submit" class="btn-submit">Save Changes</button>
        </form>

        <a href="donor_dashboard.php" class="btn">← Back to Dashboard</a>
    </div>
</section>

<?php
$Objlayout->footer($conf);
?>
