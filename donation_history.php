<?php
session_start();
require_once 'ClassAutoLoad.php';

if (!isset($_SESSION['donor_id'])) { header('Location: signin.php'); exit(); }
$donor_id = $_SESSION['donor_id'];

try { $stmt=$conn->prepare("SELECT donation_date, hospital_name, blood_type, status FROM donations WHERE donor_id = :donor_id ORDER BY donation_date DESC"); $stmt->execute([':donor_id'=>$donor_id]); $donations=$stmt->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $donations=[]; }

$conf['page_title']='Donation History';
$Objlayout->header($conf);
?>
<?php $Objlayout->donorDashboardStart($conf,'history'); ?>

<div class="card">
  <div class="card-title">Donation History</div>
  <?php if (empty($donations)): ?>
    <p>You haven't recorded any donations yet.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Hospital</th><th>Blood Type</th><th>Status</th></tr></thead><tbody><?php foreach($donations as $d): ?><tr><td><?php echo htmlspecialchars($d['donation_date']); ?></td><td><?php echo htmlspecialchars($d['hospital_name']); ?></td><td><?php echo htmlspecialchars($d['blood_type']); ?></td><td><?php echo htmlspecialchars($d['status']); ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<?php $Objlayout->dashboardEnd(); ?>
<?php $Objlayout->footer($conf); ?>

