<?php
session_start();
require_once 'ClassAutoLoad.php';

// Ensure donor is logged in
if (!isset($_SESSION['donor_id'])) {
    header("Location: signin.php");
    exit();
}

$donor_id = $_SESSION['donor_id'];

// Fetch donation history from database
$stmt = $conn->prepare("SELECT * FROM donations WHERE donor_id = :donor_id ORDER BY donation_date DESC");
$stmt->execute([':donor_id' => $donor_id]);
$donations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$conf['page_title'] = 'Donation History';
$Objlayout->header($conf);
$Objlayout->nav($conf);
?>

<link rel="stylesheet" href="CSS/forms.css">

<section class="form-page">
    <div class="form-container">
        <h2>🩸 Donation History</h2>

        <?php if (empty($donations)): ?>
            <p>You haven’t recorded any donations yet.</p>
        <?php else: ?>
            <table border="1" cellpadding="10" cellspacing="0" style="width:100%; border-collapse:collapse; text-align:center;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Hospital</th>
                        <th>Blood Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donations as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['donation_date']); ?></td>
                            <td><?php echo htmlspecialchars($d['hospital_name']); ?></td>
                            <td><?php echo htmlspecialchars($d['blood_type']); ?></td>
                            <td><?php echo htmlspecialchars($d['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <a href="donor_dashboard.php" class="btn-submit" style="display:inline-block;margin-top:20px;">⬅ Back to Dashboard</a>
    </div>
</section>

<?php
$Objlayout->footer($conf);
?>
