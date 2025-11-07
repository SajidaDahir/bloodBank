<?php
session_start();
require_once 'ClassAutoLoad.php';

// Only hospitals use this page
if (!isset($_SESSION['hospital_id'])) {
    header('Location: hospital_login.php');
    exit();
}

$hospital_id = $_SESSION['hospital_id'];
$requests = [];
$counts = ['completed'=>0,'cancelled'=>0,'fulfilled'=>0];

// Fetch historical requests (fulfilled/completed/cancelled)
try {
    // Support both 'Fulfilled' and 'Completed' depending on schema
    $stmt = $conn->prepare(
        "SELECT id, blood_type, units_needed, urgency, status, created_at
         FROM blood_requests
         WHERE hospital_id = :hid AND (status IN ('Fulfilled','Completed','Cancelled','closed','completed','cancelled','fulfilled'))
         ORDER BY created_at DESC"
    );
    $stmt->execute([':hid'=>$hospital_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($requests as $r) {
        $s = strtolower((string)$r['status']);
        if (strpos($s,'complete') !== false) $counts['completed']++;
        elseif (strpos($s,'cancel') !== false) $counts['cancelled']++;
        elseif (strpos($s,'fulfill') !== false) $counts['fulfilled']++;
    }
} catch (Exception $e) {
    $requests = [];
}

$conf['page_title'] = 'Request History | BloodBank';
$Objlayout->header($conf);
$Objlayout->dashboardStart($conf, 'history');
?>

<div class="grid stats-grid">
    <div class="card stat">
        <div class="stat-title">Total Closed</div>
        <div class="stat-value"><?php echo count($requests); ?></div>
        <div class="stat-hint">All time</div>
    </div>
    <div class="card stat">
        <div class="stat-title">Completed</div>
        <div class="stat-value text-success"><?php echo (int)$counts['completed']; ?></div>
        <div class="stat-hint">Requests</div>
    </div>
    <div class="card stat">
        <div class="stat-title">Fulfilled</div>
        <div class="stat-value"><?php echo (int)$counts['fulfilled']; ?></div>
        <div class="stat-hint">Requests</div>
    </div>
    <div class="card stat">
        <div class="stat-title">Cancelled</div>
        <div class="stat-value text-danger"><?php echo (int)$counts['cancelled']; ?></div>
        <div class="stat-hint">Requests</div>
    </div>
</div>

<div class="card">
    <div class="card-title">Request History</div>
    <?php if (empty($requests)): ?>
        <p>No historical requests yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Blood Type</th>
                        <th>Units</th>
                        <th>Urgency</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['blood_type']); ?></td>
                            <td><?php echo (int)$r['units_needed']; ?></td>
                            <td><?php echo htmlspecialchars($r['urgency']); ?></td>
                            <td><?php echo htmlspecialchars($r['status']); ?></td>
                            <td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php $Objlayout->dashboardEnd(); ?>
<?php $Objlayout->footer($conf); ?>

