<?php
session_start();
require_once 'ClassAutoLoad.php';

if (!isset($_SESSION['donor_id'])) { header('Location: signin.php'); exit(); }
$donor_id = (int)$_SESSION['donor_id'];

// Fetch accepted responses by this donor
$responses = [];
try {
    $sql = "SELECT drr.created_at AS accepted_at,
                   br.blood_type, br.units_needed, br.urgency, br.status AS request_status, br.created_at AS requested_at,
                   h.hospital_name, h.city
            FROM donor_request_responses drr
            JOIN blood_requests br ON br.id = drr.request_id
            JOIN hospitals h ON h.id = br.hospital_id
            WHERE drr.donor_id = :did AND drr.status = 'Accepted'
            ORDER BY drr.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':did' => $donor_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $responses = [];
}

$conf['page_title'] = 'My Responses | BloodBank';
$Objlayout->header($conf);
?>
<?php $Objlayout->donorDashboardStart($conf,'responses'); ?>

<div class="card">
  <div class="card-title">My Accepted Responses</div>
  <?php if (empty($responses)): ?>
    <p>You haven't accepted any requests yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Accepted</th><th>Hospital</th><th>City</th><th>Blood Type</th><th>Units</th><th>Urgency</th><th>Request Status</th><th>Requested</th></tr>
        </thead>
        <tbody>
          <?php foreach($responses as $r): ?>
            <tr>
              <td><?php echo date('M j, Y', strtotime($r['accepted_at'])); ?></td>
              <td><?php echo htmlspecialchars($r['hospital_name']); ?></td>
              <td><?php echo htmlspecialchars($r['city']); ?></td>
              <td><?php echo htmlspecialchars($r['blood_type']); ?></td>
              <td><?php echo (int)$r['units_needed']; ?></td>
              <td><?php echo htmlspecialchars($r['urgency']); ?></td>
              <td><?php echo htmlspecialchars($r['request_status']); ?></td>
              <td><?php echo date('M j, Y', strtotime($r['requested_at'])); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php $Objlayout->dashboardEnd(); ?>
<?php $Objlayout->footer($conf); ?>

