<?php
//start session and require autoloader; redirect unauthenticated hospitals
session_start();
require_once 'ClassAutoLoad.php';

if (!isset($_SESSION['hospital_id'])) { header('Location: hospital_login.php'); exit(); }
$hospital_id = $_SESSION['hospital_id'];

//fetch hospital details, recent requests, and stats
$hospital = null; $requests = []; $stats=['total'=>0,'active'=>0,'units'=>0];
try { $stmt=$conn->prepare("SELECT hospital_name AS name, contact_person, email, phone, city, address, created_at FROM hospitals WHERE id=:id"); $stmt->execute([':id'=>$hospital_id]); $hospital=$stmt->fetch(PDO::FETCH_ASSOC);} catch(Exception $e){$hospital=null;}
try { $rq=$conn->prepare("SELECT id, request_type, blood_type, units_needed AS units, urgency, deadline_at, status, created_at FROM blood_requests WHERE hospital_id=:hid ORDER BY created_at DESC LIMIT 8"); $rq->execute([':hid'=>$hospital_id]); $requests=$rq->fetchAll(PDO::FETCH_ASSOC);} catch(Exception $e){$requests=[];}
try { $q=$conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE hospital_id=:hid"); $q->execute([':hid'=>$hospital_id]); $stats['total']=(int)$q->fetchColumn(); } catch(Exception $e){}
try { $q=$conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE hospital_id=:hid AND (status IS NULL OR status NOT IN ('closed','completed','fulfilled'))"); $q->execute([':hid'=>$hospital_id]); $stats['active']=(int)$q->fetchColumn(); } catch(Exception $e){}
try { $q=$conn->prepare("SELECT COALESCE(SUM(units_available),0) FROM blood_inventory WHERE hospital_id=:hid"); $q->execute([':hid'=>$hospital_id]); $stats['units']=(int)$q->fetchColumn(); } catch(Exception $e){}

// Appointment action: mark completed (single action)
$banner='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['complete_appointment_id'])){
    $aid=(int)$_POST['complete_appointment_id'];
    try {
        $ok=false; $ap=$conn->prepare("SELECT a.donor_id,a.hospital_id,a.request_id,a.scheduled_at, br.blood_type AS request_blood_type, br.request_type, d.blood_type AS donor_blood_type FROM appointments a JOIN blood_requests br ON br.id=a.request_id JOIN donors d ON d.id=a.donor_id WHERE a.id=:id");
        $ap->execute([':id'=>$aid]);
        $row=$ap->fetch(PDO::FETCH_ASSOC);
        if($row && (int)$row['hospital_id']===$hospital_id){ $ok=true; }
        if ($ok){
            // Set appointment completed
            $up=$conn->prepare("UPDATE appointments SET status='Completed' WHERE id=:id");
            $up->execute([':id'=>$aid]);

            // Insert donation record
            try {
                $hn=''; $h=$conn->prepare("SELECT hospital_name FROM hospitals WHERE id=:id"); $h->execute([':id'=>$hospital_id]); $hn=(string)$h->fetchColumn();
                $donationBT = bloodbank_normalize_blood_type($row['donor_blood_type'] ?? '');
                if ($donationBT === '') { $donationBT = bloodbank_normalize_blood_type($row['request_blood_type'] ?? ''); }
                $ins=$conn->prepare("INSERT INTO donations(donor_id,hospital_id,hospital_name,blood_type,status) VALUES(:did,:hid,:hname,:bt,'Completed')");
                $ins->execute([':did'=>(int)$row['donor_id'], ':hid'=>$hospital_id, ':hname'=>$hn, ':bt'=>$donationBT ]);
                // Optional: increment inventory by 1 for this blood type
                if ($donationBT !== '') {
                    try { $upinv=$conn->prepare("INSERT INTO blood_inventory(hospital_id,blood_type,units_available,updated_at) VALUES(:hid,:bt,1,NOW()) ON DUPLICATE KEY UPDATE units_available = units_available + 1, updated_at = NOW()"); $upinv->execute([':hid'=>$hospital_id, ':bt'=>$donationBT ]); } catch(Exception $ie){}
                }
            } catch(Exception $ie){}

            // Notify donor
            try {
                $title='Appointment completed';
                $body='Thank you for donating. Your appointment has been completed.';
                $link='donation_history.php';
                $n=$conn->prepare("INSERT INTO notifications(recipient_type,recipient_id,title,body,link) VALUES('donor',:rid,:t,:b,:l)");
                $n->execute([':rid'=>(int)$row['donor_id'], ':t'=>$title, ':b'=>$body, ':l'=>$link]);
            } catch(Exception $ne){}

            $banner='Appointment marked as completed.';
        }
    } catch(Exception $e){ $banner='Unable to complete appointment.'; }
}
// Hospital notifications and appointments
$notifications=[]; try{ $nn=$conn->prepare("SELECT id,title,body,link,created_at,is_read FROM notifications WHERE recipient_type='hospital' AND recipient_id=:id ORDER BY is_read ASC, created_at DESC LIMIT 5"); $nn->execute([':id'=>$hospital_id]); $notifications=$nn->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){ $notifications=[]; }
$appointments=[]; try{ $ap=$conn->prepare("SELECT a.id,a.scheduled_at,a.status,d.fullname, br.request_type, br.blood_type FROM appointments a JOIN donors d ON d.id=a.donor_id JOIN blood_requests br ON br.id=a.request_id WHERE a.hospital_id=:hid ORDER BY a.scheduled_at ASC LIMIT 8"); $ap->execute([':hid'=>$hospital_id]); $appointments=$ap->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){ $appointments=[]; }

$conf['page_title']='BloodBank | Hospital Dashboard';
$Objlayout->header($conf);
?>
<?php $Objlayout->dashboardStart($conf,'dashboard'); ?>

<?php if(!empty($banner)): ?><div class="card" style="border-color:#dbeafe;background:#eff6ff;color:#1e3a8a;"><?php echo htmlspecialchars($banner); ?></div><?php endif; ?>
<!-- Statistics Summary overview -->
<div class="grid stats-grid">
  <div class="card stat"><div class="stat-title">Total Requests</div><div class="stat-value"><?php echo (int)$stats['total']; ?></div><div class="stat-hint">All time</div></div>
  <div class="card stat"><div class="stat-title">Active Requests</div><div class="stat-value text-danger"><?php echo (int)$stats['active']; ?></div><div class="stat-hint">Awaiting donors</div></div>
  <div class="card stat"><div class="stat-title">Units Received</div><div class="stat-value text-success"><?php echo (int)$stats['units']; ?></div><div class="stat-hint">Blood units</div></div>
  <div class="card stat hide-mobile"><div class="stat-title">Welcome</div><div class="stat-value small"><?php echo $hospital?htmlspecialchars($hospital['name']):'Hospital'; ?></div><div class="stat-hint">Glad to have you</div></div>
</div>
<!-- Quick shortcuts for high priority works -->
<div class="card">
  <div class="card-title">Quick Actions</div>
  <div class="actions-grid">
    <a class="action" href="blood_requests.php?action=create"><div class="action-icon">＋</div><div class="action-text"><div class="action-title">Create Blood Request</div><div class="action-sub">Post urgent or scheduled request</div></div></a>
    <a class="action" href="inventory.php"><div class="action-icon">⬒</div><div class="action-text"><div class="action-title">Update Inventory</div><div class="action-sub">Manage blood stock levels</div></div></a>
    <a class="action" href="blood_requests.php?filter=active"><div class="action-icon">☰</div><div class="action-text"><div class="action-title">View Active Requests</div><div class="action-sub"><?php echo (int)$stats['active']; ?> requests pending</div></div></a>
  </div>
</div>
<?php $low=[]; try{ $inv=$conn->prepare("SELECT blood_type, units_available FROM blood_inventory WHERE hospital_id=:hid ORDER BY blood_type"); $inv->execute([':hid'=>$hospital_id]); foreach($inv->fetchAll(PDO::FETCH_ASSOC) as $row){ if((int)$row['units_available']<=4){ $low[]=$row; } } }catch(Exception $e){} ?>
<?php if(!empty($low)): ?>
  <div class="alert-panel"><div class="alert-title">Critical Inventory Alerts</div><div class="alert-grid"><?php foreach($low as $li): ?><div class="alert-card"><div class="alert-type"><?php echo htmlspecialchars($li['blood_type']); ?></div><div class="alert-units"><?php echo (int)$li['units_available']; ?> units</div><div class="alert-critical">Critical</div></div><?php endforeach; ?></div></div>
<?php endif; ?>

<div class="card">
  <div class="card-title">Recent Requests</div>
  <?php if(!empty($requests)): ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Blood Needed</th><th>Units</th><th>Urgency</th><th>Deadline</th><th>Status</th><th>Requested</th></tr></thead><tbody><?php foreach($requests as $r): $deadlineDisplay = !empty($r['deadline_at']) ? date('M j, Y g:i A', strtotime($r['deadline_at'])) : 'None'; ?><tr><td><?php echo htmlspecialchars(ucfirst($r['request_type'] ?? 'specific')); ?></td><td><?php echo htmlspecialchars(bloodbank_format_request_blood_label($r['request_type'] ?? '', $r['blood_type'] ?? '')); ?></td><td><?php echo (int)$r['units']; ?></td><td><?php echo htmlspecialchars($r['urgency']); ?></td><td><?php echo htmlspecialchars($deadlineDisplay); ?></td><td><?php echo htmlspecialchars($r['status']); ?></td><td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php else: ?><p>No recent requests found.</p><?php endif; ?>
</div>

<div class="grid" style="grid-template-columns:1fr; gap:12px;">
  <div class="card">
    <div class="card-title">Notifications</div>
    <?php if(empty($notifications)): ?><p>No notifications.</p><?php else: ?>
      <ul style="list-style:none;padding:0;margin:0;">
        <?php foreach($notifications as $n): ?>
          <li style="padding:8px 0;border-bottom:1px solid #f3f4f6;">
            <div style="font-weight:600;"><?php echo htmlspecialchars($n['title']); ?></div>
            <div style="color:#6b7280;font-size:12px;"><?php echo htmlspecialchars($n['body'] ?? ''); ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-title">Appointments</div>
    <?php if(empty($appointments)): ?><p>No appointments scheduled.</p><?php else: ?>
      <div class="table-wrap"><table class="table"><thead><tr><th>Donor</th><th>Request Type</th><th>Blood Needed</th><th>When</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach($appointments as $a): ?>
          <tr>
            <td><?php echo htmlspecialchars($a['fullname']); ?></td>
            <td><?php echo htmlspecialchars(ucfirst($a['request_type'] ?? 'specific')); ?></td>
            <td><?php echo htmlspecialchars(bloodbank_format_request_blood_label($a['request_type'] ?? '', $a['blood_type'] ?? '')); ?></td>
            <td><?php echo date('M j, Y H:i', strtotime($a['scheduled_at'])); ?></td>
            <td><?php echo htmlspecialchars($a['status']); ?></td>
            <td>
              <?php if (strcasecmp((string)$a['status'],'Completed')!==0): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="complete_appointment_id" value="<?php echo (int)$a['id']; ?>" />
                  <button class="btn-primary" type="submit">Mark Completed</button>
                </form>
              <?php else: ?>
                <span style="color:#065f46;">Done</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>

?>