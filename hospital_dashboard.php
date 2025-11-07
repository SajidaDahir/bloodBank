<?php
session_start();
require_once 'ClassAutoLoad.php';

// Security check — only allow logged-in hospitals
if (!isset($_SESSION['hospital_id'])) {
    header("Location: hospital_login.php");
    exit();
}

// Fetch hospital details and data
$hospital_id = $_SESSION['hospital_id'];
$hospital = null;
$requests = [];
$stats = ['total'=>0,'active'=>0,'units'=>0];

try {
    $stmt = $conn->prepare("SELECT hospital_name AS name, contact_person, email, phone, city, address, created_at FROM hospitals WHERE id = :id");
    $stmt->execute([':id' => $hospital_id]);
    $hospital = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $hospital = null; }

// Recent requests
try {
    $rq = $conn->prepare("SELECT id, blood_type, units_needed AS units, urgency, status, created_at FROM blood_requests WHERE hospital_id = :hid ORDER BY created_at DESC LIMIT 8");
    $rq->execute([':hid' => $hospital_id]);
    $requests = $rq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $requests = []; }

// Stats (best-effort)
try { $q=$conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE hospital_id=:hid"); $q->execute([':hid'=>$hospital_id]); $stats['total']=(int)$q->fetchColumn(); } catch(Exception $e){}
try { $q=$conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE hospital_id=:hid AND (status IS NULL OR status NOT IN ('closed','completed','fulfilled'))"); $q->execute([':hid'=>$hospital_id]); $stats['active']=(int)$q->fetchColumn(); } catch(Exception $e){}
try { $q=$conn->prepare("SELECT COALESCE(SUM(units_available),0) FROM blood_inventory WHERE hospital_id=:hid"); $q->execute([':hid'=>$hospital_id]); $stats['units']=(int)$q->fetchColumn(); } catch(Exception $e){}

// Page title
$conf['page_title'] = 'BloodBank | Hospital Dashboard';

// Render layout header
$Objlayout->header($conf);
?>

<?php $Objlayout->dashboardStart($conf, 'dashboard'); ?>

<div class="grid stats-grid">
    <div class="card stat">
        <div class="stat-title">Total Requests</div>
        <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
        <div class="stat-hint">All time</div>
    </div>
    <div class="card stat">
        <div class="stat-title">Active Requests</div>
        <div class="stat-value text-danger"><?php echo (int)$stats['active']; ?></div>
        <div class="stat-hint">Awaiting donors</div>
    </div>
    <div class="card stat">
        <div class="stat-title">Units Received</div>
        <div class="stat-value text-success"><?php echo (int)$stats['units']; ?></div>
        <div class="stat-hint">Blood units</div>
    </div>
    <div class="card stat hide-mobile">
        <div class="stat-title">Welcome</div>
        <div class="stat-value small"><?php echo $hospital && !empty($hospital['name']) ? htmlspecialchars($hospital['name']) : 'Hospital'; ?></div>
        <div class="stat-hint">Glad to have you</div>
    </div>
</div>

<div class="card">
    <div class="card-title">Quick Actions</div>
    <div class="actions-grid">
        <a class="action" href="blood_requests.php?action=create">
            <div class="action-icon">＋</div>
            <div class="action-text">
                <div class="action-title">Create Blood Request</div>
                <div class="action-sub">Post urgent or scheduled request</div>
            </div>
        </a>
        <a class="action" href="inventory.php">
            <div class="action-icon">⬒</div>
            <div class="action-text">
                <div class="action-title">Update Inventory</div>
                <div class="action-sub">Manage blood stock levels</div>
            </div>
        </a>
        <a class="action" href="blood_requests.php?filter=active">
            <div class="action-icon">☰</div>
            <div class="action-text">
                <div class="action-title">View Active Requests</div>
                <div class="action-sub"><?php echo (int)$stats['active']; ?> requests pending</div>
            </div>
        </a>
    </div>
</div>

<?php
// Build critical inventory alert panel from low stock items (<= 4)
$low = [];
try {
    $inv = $conn->prepare("SELECT blood_type, units_available FROM blood_inventory WHERE hospital_id = :hid ORDER BY blood_type");
    $inv->execute([':hid' => $hospital_id]);
    $rows = $inv->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if ((int)$row['units_available'] <= 4) { $low[] = $row; }
    }
} catch (Exception $e) {}
?>

<?php if (!empty($low)): ?>
    <div class="alert-panel">
        <div class="alert-title">Critical Inventory Alerts</div>
        <div class="alert-grid">
            <?php foreach ($low as $li): ?>
                <div class="alert-card">
                    <div class="alert-type"><?php echo htmlspecialchars($li['blood_type']); ?></div>
                    <div class="alert-units"><?php echo (int)$li['units_available']; ?> units</div>
                    <div class="alert-critical">Critical</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-title">Recent Requests</div>
    <?php if (!empty($requests)): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Blood Type</th>
                        <th>Units</th>
                        <th>Urgency</th>
                        <th>Status</th>
                        <th>Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['blood_type']); ?></td>
                            <td><?php echo (int)$r['units']; ?></td>
                            <td><?php echo htmlspecialchars($r['urgency']); ?></td>
                            <td><?php echo htmlspecialchars($r['status']); ?></td>
                            <td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No recent requests found.</p>
    <?php endif; ?>
</div>

<?php $Objlayout->dashboardEnd(); ?>

<?php $Objlayout->footer($conf); ?>

