<?php
session_start();
require_once 'ClassAutoLoad.php';

if (!isset($_SESSION['donor_id'])) { header('Location: signin.php'); exit(); }
$donor_id = $_SESSION['donor_id'];

// Determine availability upfront for guarding actions
$is_available = 1;
try {
    $chk = $conn->prepare("SELECT COALESCE(is_available,1) FROM donors WHERE id=:id");
    $chk->execute([':id' => $donor_id]);
    $is_available = (int)$chk->fetchColumn();
} catch (Exception $e) {
    $is_available = 1;
}

// Accept request
$accept_msg='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accept_request_id'])){
    if (!$is_available) {
        $accept_msg='You are currently not available to accept requests.';
    } else {
        $rid=(int)$_POST['accept_request_id'];
        try{
            $q=$conn->prepare("INSERT INTO donor_request_responses(donor_id,request_id,status) VALUES(:did,:rid,'Accepted')");
            $q->execute([':did'=>$donor_id,':rid'=>$rid]);
            $accept_msg='Accepted';
            // Notify hospital of new donor acceptance
            try {
                $hidStmt = $conn->prepare("SELECT hospital_id FROM blood_requests WHERE id=:rid");
                $hidStmt->execute([':rid'=>$rid]);
                $hid = (int)$hidStmt->fetchColumn();
                if ($hid) {
                    $dname = isset($_SESSION['donor_name']) ? (string)$_SESSION['donor_name'] : '';
                    if ($dname==='') { try { $nm=$conn->prepare("SELECT fullname FROM donors WHERE id=:id"); $nm->execute([':id'=>$donor_id]); $dname=(string)$nm->fetchColumn(); } catch(Exception $e){} }
                    $title = 'New donor accepted your request';
                    $body  = trim($dname) !== '' ? ($dname.' accepted request #'.$rid) : ('A donor accepted request #'.$rid);
                    $link  = 'blood_requests.php?filter=active';
                    $insN  = $conn->prepare("INSERT INTO notifications(recipient_type,recipient_id,title,body,link) VALUES('hospital',:rid,:t,:b,:l)");
                    $insN->execute([':rid'=>$hid, ':t'=>$title, ':b'=>$body, ':l'=>$link]);
                    // Send email to hospital (best-effort)
                    try {
                        $hs=$conn->prepare("SELECT hospital_name,email FROM hospitals WHERE id=:id");
                        $hs->execute([':id'=>$hid]);
                        $hrow=$hs->fetch(PDO::FETCH_ASSOC);
                        if ($hrow && !empty($conf['smtp_user'])) {
                            $mailContent = [
                                'name_from'  => $conf['site_name'],
                                'email_from' => $conf['smtp_user'],
                                'name_to'    => $hrow['hospital_name'] ?? 'Hospital',
                                'email_to'   => $hrow['email'] ?? '',
                                'subject'    => 'A donor accepted your blood request',
                                'body'       => '<p>' . htmlspecialchars($dname ? $dname : 'A donor') . ' accepted request #' . (int)$rid . '</p>'
                            ];
                            $ObjSendMail->Send_Mail($conf, $mailContent);
                        }
                    } catch(Exception $me){}
                }
            } catch(Exception $e) { /* ignore notification errors */ }
        }catch(Exception $e){
            $accept_msg='Accepted'; // Already accepted or duplicate
        }
    }
}

// Toggle availability
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_availability'])){
    try { $conn->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS is_available TINYINT(1) NOT NULL DEFAULT 1"); } catch(Exception $e) {}
    try { $conn->exec("UPDATE donors SET is_available = 1 - IFNULL(is_available,1) WHERE id = ".(int)$donor_id); } catch(Exception $e) {}
    // reflect change locally without a re-query
    $is_available = 1 - (int)$is_available;
}

// Fetch donor (fallback if column missing)
$donor=null; try{ $stmt=$conn->prepare("SELECT fullname,email,phone,blood_type,location,is_available,created_at FROM donors WHERE id=:id"); $stmt->execute([':id'=>$donor_id]); $donor=$stmt->fetch(PDO::FETCH_ASSOC);}catch(Exception $e){ try{ $stmt=$conn->prepare("SELECT fullname,email,phone,blood_type,location,created_at FROM donors WHERE id=:id"); $stmt->execute([':id'=>$donor_id]); $donor=$stmt->fetch(PDO::FETCH_ASSOC); if($donor){ $donor['is_available']=1; } }catch(Exception $e2){ $donor=null; } }
if(!$donor){ header('Location: signin.php'); exit(); }

// Stats and matching
$totalDonations=0; $lastDonation=null; $pendingMatches=0; $livesSaved=0;
try{ $q=$conn->prepare("SELECT COUNT(*) AS c, MAX(created_at) AS last_dt FROM donations WHERE donor_id=:id AND status='Completed'"); $q->execute([':id'=>$donor_id]); $row=$q->fetch(PDO::FETCH_ASSOC); $totalDonations=(int)($row['c']??0); $lastDonation=$row['last_dt']??null; }catch(Exception $e){}
try{ $q=$conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE status='Pending' AND blood_type=:bt"); $q->execute([':bt'=>$donor['blood_type']]); $pendingMatches=(int)$q->fetchColumn(); }catch(Exception $e){}
if (empty($donor['is_available'])) { $pendingMatches = 0; }
$livesSaved=$totalDonations;

$activeRequests=[]; if (!empty($donor['is_available'])) { try{ $q=$conn->prepare("SELECT br.id,br.blood_type,br.units_needed,br.urgency,br.created_at,h.hospital_name,h.city, CASE WHEN drr.id IS NULL THEN 0 ELSE 1 END AS accepted FROM blood_requests br JOIN hospitals h ON br.hospital_id=h.id LEFT JOIN donor_request_responses drr ON drr.request_id=br.id AND drr.donor_id=:did WHERE br.status='Pending' AND br.blood_type=:bt ORDER BY br.created_at DESC LIMIT 6"); $q->execute([':bt'=>$donor['blood_type'], ':did'=>$donor_id]); $activeRequests=$q->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){ $activeRequests=[]; } }

// Notifications for donor
$notifications=[]; try{ $nn=$conn->prepare("SELECT id,title,body,link,created_at,is_read FROM notifications WHERE recipient_type='donor' AND recipient_id=:id ORDER BY is_read ASC, created_at DESC LIMIT 5"); $nn->execute([':id'=>$donor_id]); $notifications=$nn->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){ $notifications=[]; }

// Appointments for donor (upcoming and recent)
$appointments=[]; try{ $ap=$conn->prepare("SELECT a.id,a.scheduled_at,a.status,h.hospital_name FROM appointments a JOIN hospitals h ON h.id=a.hospital_id WHERE a.donor_id=:id ORDER BY a.scheduled_at DESC LIMIT 5"); $ap->execute([':id'=>$donor_id]); $appointments=$ap->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){ $appointments=[]; }

$conf['page_title'] = 'BloodBank | Donor Dashboard'; $Objlayout->header($conf);
?>
<?php $Objlayout->donorDashboardStart($conf,'dashboard'); ?>

<div class="card" style="margin-bottom:14px;">
  <div class="card-title">Availability Status</div>
  <form method="post" style="display:flex;align-items:center;gap:12px;">
    <input type="hidden" name="toggle_availability" value="1" />
    <button type="submit" class="btn-outline" style="min-width:140px;"><?php echo !empty($donor['is_available']) ? 'Turn Off' : 'Turn On'; ?></button>
    <div style="flex:1;">
      <div style="background:<?php echo !empty($donor['is_available']) ? '#d1fae5' : '#fee2e2'; ?>;color:<?php echo !empty($donor['is_available']) ? '#065f46' : '#991b1b'; ?>;padding:10px;border-radius:8px;">
        <?php echo !empty($donor['is_available']) ? 'You are currently available to donate' : 'You are not available to receive requests'; ?>
      </div>
    </div>
  </form>
</div>

<div class="grid stats-grid">
  <div class="card stat"><div class="stat-title">Total Donations</div><div class="stat-value"><?php echo $totalDonations; ?></div><div class="stat-hint">Last: <?php echo $lastDonation ? date('M j', strtotime($lastDonation)) : '—'; ?></div></div>
  <div class="card stat"><div class="stat-title">Pending Requests</div><div class="stat-value text-danger"><?php echo $pendingMatches; ?></div><div class="stat-hint">Requires response</div></div>
  <div class="card stat"><div class="stat-title">Lives Saved</div><div class="stat-value text-success"><?php echo $livesSaved; ?></div><div class="stat-hint">Impact score</div></div>
  <div class="card stat hide-mobile"><div class="stat-title">Blood Type</div><div class="stat-value small"><?php echo htmlspecialchars($donor['blood_type']); ?></div><div class="stat-hint">Profile</div></div>
</div>

<div class="card" style="margin-top:14px;">
  <div class="card-title">Active Blood Requests Near You</div>
  <?php if (empty($donor['is_available'])): ?>
    <div class="card" style="border-color:#fee2e2;background:#fff1f2;color:#9f1239;margin-bottom:10px;">You are not available to receive requests. Turn on availability to see and accept requests.</div>
  <?php endif; ?>
  <?php if($accept_msg): ?><div class="card" style="border-color:#dbeafe;background:#eff6ff;color:#1e3a8a;margin-bottom:10px;">Accepted</div><?php endif; ?>
  <?php if (empty($activeRequests)): ?><p>No matching requests at the moment.</p><?php else: ?>
    <div class="grid" style="grid-template-columns:1fr; gap:12px;">
      <?php foreach ($activeRequests as $req): ?>
        <div style="border:1px solid #fecaca; background:#fff5f5; border-radius:12px; padding:12px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;"><div style="font-weight:700;color:#b91c1c;">URGENT</div><div style="color:#6b7280; font-size:12px;"><?php echo date('M j, g:i A', strtotime($req['created_at'])); ?></div></div>
          <div><strong>Blood Type:</strong> <?php echo htmlspecialchars($req['blood_type']); ?></div>
          <div><strong>Hospital:</strong> <?php echo htmlspecialchars($req['hospital_name']); ?></div>
          <div><strong>Units needed:</strong> <?php echo (int)$req['units_needed']; ?> units</div>
          <div style="display:flex; gap:10px; margin-top:10px;">
            <?php if (!empty($req['accepted'])): ?>
              <span style="color:#065f46;">Accepted</span>
            <?php else: ?>
              <form method="post"><input type="hidden" name="accept_request_id" value="<?php echo (int)$req['id']; ?>" /><button class="btn-primary" type="submit">Accept Request</button></form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="grid" style="grid-template-columns:1fr; gap:12px; margin-top:14px;">
  <div class="card">
    <div class="card-title">Notifications</div>
    <?php if(empty($notifications)): ?><p>No notifications yet.</p><?php else: ?>
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
      <div class="table-wrap"><table class="table"><thead><tr><th>Hospital</th><th>When</th><th>Status</th></tr></thead><tbody>
        <?php foreach($appointments as $a): ?>
          <tr><td><?php echo htmlspecialchars($a['hospital_name']); ?></td><td><?php echo date('M j, Y H:i', strtotime($a['scheduled_at'])); ?></td><td><?php echo htmlspecialchars($a['status']); ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); ?>
