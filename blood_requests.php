<?php
session_start();
require_once 'ClassAutoLoad.php';

// Ensure donor is logged in
if (!isset($_SESSION['donor_id'])) {
    header("Location: signin.php");
    exit();
}

$donor_id = $_SESSION['donor_id'];

// Fetch all active blood requests
$stmt = $conn->prepare("
    SELECT br.*, h.hospital_name, h.city 
    FROM blood_requests br
    JOIN hospitals h ON br.hospital_id = h.id
    WHERE br.status = 'Pending'
    ORDER BY br.created_at DESC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$conf['page_title'] = 'Blood Requests | BloodBank';
$Objlayout->header($conf);
?>

<link rel="stylesheet" href="CSS/forms.css">
<link rel="stylesheet" href="CSS/style.css">

<nav class="dashboard-navbar">
    <div class="dashboard-logo">❤️ BloodBank Dashboard</div>
    <ul class="dashboard-links">
        <li><a href="donor_dashboard.php">Home</a></li>
        <li><a href="donation_history.php">Donations</a></li>
        <li><a href="profile.php">Profile</a></li>
        <li><a href="blood_requests.php" class="active">Requests</a></li>
        <li><a href="logout.php" class="logout-btn">Logout</a></li>
    </ul>
</nav>

<section class="form-page">
    <div class="form-container">
        <h2>🩸 Active Blood Requests</h2>

        <?php if (empty($requests)): ?>
            <p>No active blood requests at the moment.</p>
        <?php else: ?>
            <table border="1" cellpadding="10" cellspacing="0" style="width:100%; border-collapse:collapse; text-align:center;">
                <thead>
                    <tr>
                        <th>Hospital</th>
                        <th>City</th>
                        <th>Blood Type</th>
                        <th>Units Needed</th>
                        <th>Urgency</th>
                        <th>Date Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['hospital_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['city']); ?></td>
                            <td><?php echo htmlspecialchars($r['blood_type']); ?></td>
                            <td><?php echo htmlspecialchars($r['units_needed']); ?></td>
                            <td><?php echo htmlspecialchars($r['urgency']); ?></td>
                            <td><?php echo date("F j, Y", strtotime($r['created_at'])); ?></td>
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
