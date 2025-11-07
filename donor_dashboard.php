<?php
session_start();
require_once 'ClassAutoLoad.php';

// Only allow logged-in donors
if (!isset($_SESSION['donor_id'])) {
    header('Location: signin.php');
    exit();
}

$donor_id = $_SESSION['donor_id'];

// Accept request action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_request_id'])) {
    $rid = (int)$_POST['accept_request_id'];
    try {
        $sql = "INSERT INTO donor_request_responses(donor_id, request_id, status) VALUES (:did,:rid,'Accepted')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':did'=>$donor_id, ':rid'=>$rid]);
    } catch (Exception $e) { /* ignore if already accepted */ }
}

// Toggle availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_availability'])) {
    try {
        $conn->exec("UPDATE donors SET is_available = 1 - IFNULL(is_available,1) WHERE id = " . (int)$donor_id);
    } catch (Exception $e) { /* ignore */ }
}

// Fetch donor details including availability
$donor = null;
try {
    $stmt = $conn->prepare("SELECT fullname, email, phone, blood_type, location, is_available, created_at FROM donors WHERE id = :id");
    $stmt->execute([':id' => $donor_id]);
    $donor = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback for DBs without is_available column
    try {
        $stmt = $conn->prepare("SELECT fullname, email, phone, blood_type, location, created_at FROM donors WHERE id = :id");
        $stmt->execute([':id' => $donor_id]);
        $donor = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($donor) { $donor['is_available'] = 1; }
    } catch (Exception $e2) { $donor = null; }
}

if (!$donor) { header('Location: signin.php'); exit(); }

// Stats
$totalDonations = 0; $lastDonation = null; $pendingMatches = 0; $livesSaved = 0;
try {
    $q = $conn->prepare("SELECT COUNT(*) AS c, MAX(created_at) AS last_dt FROM donations WHERE donor_id=:id AND status='Completed'");
    $q->execute([':id'=>$donor_id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $totalDonations = (int)($row['c'] ?? 0);
    $lastDonation = $row['last_dt'] ?? null;
} catch (Exception $e) {}

// Pending matching requests
try {
    $q = $conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE status='Pending' AND blood_type = :bt");
    $q->execute([':bt'=>$donor['blood_type']]);
    $pendingMatches = (int)$q->fetchColumn();
} catch (Exception $e) {}

$livesSaved = $totalDonations; // proxy metric

// Active requests to show
$activeRequests = [];
try {
    $q = $conn->prepare("SELECT br.id, br.blood_type, br.units_needed, br.urgency, br.created_at, h.hospital_name, h.city FROM blood_requests br JOIN hospitals h ON br.hospital_id=h.id WHERE br.status='Pending' AND br.blood_type=:bt ORDER BY br.created_at DESC LIMIT 6");
    $q->execute([':bt'=>$donor['blood_type']]);
    $activeRequests = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $activeRequests = []; }

$conf['page_title'] = 'BloodBank | Donor Dashboard';
$Objlayout->header($conf);
$Objlayout->donorDashboardStart($conf, 'dashboard');
?>

<div class="card" style="margin-bottom:14px;">
    <div class="card-title">Availability Status</div>
    <form method="post" style="display:flex;align-items:center;gap:12px;">
        <input type="hidden" name="toggle_availability" value="1" />
        <button type="submit" class="btn-outline" style="min-width:140px;">
            <?php echo !empty($donor['is_available']) ? 'Turn Off' : 'Turn On'; ?>
        </button>
        <div style="flex:1;">
            <div style="background:<?php echo !empty($donor['is_available']) ? '#d1fae5' : '#fee2e2'; ?>;color:<?php echo !empty($donor['is_available']) ? '#065f46' : '#991b1b'; ?>;padding:10px;border-radius:8px;">
                <?php echo !empty($donor['is_available']) ? 'You are currently available to donate' : 'You are not available to receive requests'; ?>
            </div>
        </div>
    </form>
</div>

<div class="grid stats-grid">
    <div class="card stat">
        <div class="stat-title">Total Donations</div>
        <div class="stat-value"><?php echo $totalDonations; ?></div>
        <div class="stat-hint">Last: <?php echo $lastDonation ? date('M j', strtotime($lastDonation)) : '—'; ?></div>
    </div>
    <div class="card stat">
        <div class="stat-title">Pending Requests</div>
        <div class="stat-value text-danger"><?php echo $pendingMatches; ?></div>
        <div class="stat-hint">Requires response</div>
    </div>
    <div class="card stat">
        <div class="stat-title">Lives Saved</div>
        <div class="stat-value text-success"><?php echo $livesSaved; ?></div>
        <div class="stat-hint">Impact score</div>
    </div>
    <div class="card stat hide-mobile">
        <div class="stat-title">Blood Type</div>
        <div class="stat-value small"><?php echo htmlspecialchars($donor['blood_type']); ?></div>
        <div class="stat-hint">Profile</div>
    </div>
</div>

<div class="card" style="margin-top:14px;">
    <div class="card-title">Active Blood Requests Near You</div>
    <?php if (empty($activeRequests)): ?>
        <p>No matching requests at the moment.</p>
    <?php else: ?>
        <div class="grid" style="grid-template-columns:1fr; gap:12px;">
            <?php foreach ($activeRequests as $req): ?>
                <div style="border:1px solid #fecaca; background:#fff5f5; border-radius:12px; padding:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <div style="font-weight:700;color:#b91c1c;">URGENT
                        <div style="color:#6b7280; font-size:12px;"><?php echo date('M j, g:i A', strtotime($req['created_at'])); ?></div>
                    </div>
                    <div><strong>Blood Type:</strong> <?php echo htmlspecialchars($req['blood_type']); ?></div>
                    <div><strong>Hospital:</strong> <?php echo htmlspecialchars($req['hospital_name']); ?></div>
                    <div><strong>Units needed:</strong> <?php echo (int)$req['units_needed']; ?> units</div>
                    <div style="display:flex; gap:10px; margin-top:10px;">
                        <form method="post">
                            <input type="hidden" name="accept_request_id" value="<?php echo (int)$req['id']; ?>" />
                            <button class="btn-primary" type="submit">Accept Request</button>
                        </form>
                        <a class="btn-outline" href="blood_requests.php" style="display:inline-flex;align-items:center;">View Details</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>


<?php $Objlayout->dashboardEnd(); ?>
<?php $Objlayout->footer($conf); ?>

