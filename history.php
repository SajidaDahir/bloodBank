<?php
// Session, Autoload and Authentication
session_start();
require_once 'ClassAutoLoad.php';

if (!isset($_SESSION['hospital_id'])) { header('Location: hospital_login.php'); exit(); }
$hospital_id = $_SESSION['hospital_id'];

$requests=[]; $counts=['completed'=>0,'cancelled'=>0,'fulfilled'=>0];
// Fetch historical request
try {
    $stmt=$conn->prepare("SELECT br.id,br.request_type,br.blood_type,br.units_needed,br.urgency,br.deadline_at,br.status,br.created_at,
        (SELECT GROUP_CONCAT(d.fullname SEPARATOR ', ') FROM donor_request_responses drr JOIN donors d ON d.id=drr.donor_id WHERE drr.request_id=br.id AND drr.status='Accepted') AS donors
        FROM blood_requests br
        WHERE br.hospital_id=:hid AND (br.status IN ('Fulfilled','Completed','Cancelled','closed','completed','cancelled','fulfilled'))
        ORDER BY br.created_at DESC");
    $stmt->execute([':hid'=>$hospital_id]);
    $requests=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($requests as $r){ $s=strtolower((string)$r['status']); if(strpos($s,'complete')!==false)$counts['completed']++; elseif(strpos($s,'cancel')!==false)$counts['cancelled']++; elseif(strpos($s,'fulfill')!==false)$counts['fulfilled']++; }
} catch(Exception $e){ $requests=[]; }

//Page Configuration and Header

$conf['page_title']='Request History | BloodBank';
$Objlayout->header($conf);
?>
<?php $Objlayout->dashboardStart($conf,'history'); ?>


<div class="grid stats-grid">
  <div class="card stat"><div class="stat-title">Total Closed</div><div class="stat-value"><?php echo count($requests); ?></div><div class="stat-hint">All time</div></div>
  <div class="card stat"><div class="stat-title">Completed</div><div class="stat-value text-success"><?php echo (int)$counts['completed']; ?></div><div class="stat-hint">Requests</div></div>
  <div class="card stat"><div class="stat-title">Fulfilled</div><div class="stat-value"><?php echo (int)$counts['fulfilled']; ?></div><div class="stat-hint">Requests</div></div>
  <div class="card stat"><div class="stat-title">Cancelled</div><div class="stat-value text-danger"><?php echo (int)$counts['cancelled']; ?></div><div class="stat-hint">Requests</div></div>
</div>
<!-- Request history table -->
<div class="card"><div class="card-title">Request History</div>
  <?php if(empty($requests)): ?><p>No historical requests yet.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Blood Needed</th><th>Units</th><th>Urgency</th><th>Deadline</th><th>Accepted Donors</th><th>Status</th><th>Date</th></tr></thead><tbody><?php foreach($requests as $r): $deadlineDisplay = !empty($r['deadline_at']) ? date('M j, Y g:i A', strtotime($r['deadline_at'])) : 'None'; ?><tr><td><?php echo htmlspecialchars(ucfirst($r['request_type'] ?? 'specific')); ?></td><td><?php echo htmlspecialchars(bloodbank_format_request_blood_label($r['request_type'] ?? '', $r['blood_type'] ?? '')); ?></td><td><?php echo (int)$r['units_needed']; ?></td><td><?php echo htmlspecialchars($r['urgency']); ?></td><td><?php echo htmlspecialchars($deadlineDisplay); ?></td><td><?php echo htmlspecialchars($r['donors'] ?? ''); ?></td><td><?php echo htmlspecialchars($r['status']); ?></td><td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<!-- Footer-->
<?php $Objlayout->dashboardEnd(); ?>
<?php $Objlayout->footer($conf); ?>
