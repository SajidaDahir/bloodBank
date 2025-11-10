<?php
session_start();
require_once 'ClassAutoLoad.php';

// Hospital view using dashboard shell
if (isset($_SESSION['hospital_id'])) {
    $hospital_id = $_SESSION['hospital_id'];
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'active';
    $where = "hospital_id = :hid";
    if ($filter === 'active') { $where .= " AND status = 'Pending'"; }
    elseif ($filter === 'history') { $where .= " AND status IN ('Fulfilled','Cancelled')"; }

    $created = false; $updated_msg=''; $error='';
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_request'])) {
        try {
            // Ensure urgency matches DB enum values
            $allowedUrg = ['Low','Medium','High'];
            $urg = trim((string)($_POST['urgency'] ?? ''));
            if (!in_array($urg, $allowedUrg, true)) { $urg = 'Medium'; }
            // Validate blood type strictly
            $allowedBT = ['A+','A-','B+','B-','O+','O-','AB+','AB-'];
            $bt = strtoupper(trim((string)($_POST['blood_type'] ?? '')));
            if (!in_array($bt, $allowedBT, true)) { throw new Exception('Invalid blood type'); }

            $q=$conn->prepare("INSERT INTO blood_requests(hospital_id,blood_type,units_needed,urgency,status,created_at) VALUES(:hid,:bt,:u,:urg,'Pending',NOW())");
            $q->execute([':hid'=>$hospital_id,':bt'=>$bt,':u'=>(int)$_POST['units_needed'],':urg'=>$urg]);
            $created=true;
        } catch(Exception $e){ $error='Could not create request.'; }
    }

    // Mark request as fulfilled (hospital action) and auto-create donation records
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['fulfill_request_id'])) {
        $rid = (int)$_POST['fulfill_request_id'];
        try {
            $conn->beginTransaction();
            $q=$conn->prepare("UPDATE blood_requests SET status='Fulfilled' WHERE id=:rid AND hospital_id=:hid AND status='Pending'");
            $q->execute([':rid'=>$rid, ':hid'=>$hospital_id]);
            if ($q->rowCount()===0) { throw new Exception('No pending request to fulfill.'); }

            // Fetch request details and hospital name
            $req = null; $hospitalName = '';
            try { $s=$conn->prepare("SELECT blood_type, units_needed FROM blood_requests WHERE id=:rid"); $s->execute([':rid'=>$rid]); $req=$s->fetch(PDO::FETCH_ASSOC); } catch(Exception $e){}
            try { $s=$conn->prepare("SELECT hospital_name FROM hospitals WHERE id=:hid"); $s->execute([':hid'=>$hospital_id]); $hospitalName=(string)$s->fetchColumn(); } catch(Exception $e){}

            // Get accepting donors for this request
            $donors=[]; try{ $s=$conn->prepare("SELECT donor_id FROM donor_request_responses WHERE request_id=:rid AND status='Accepted'"); $s->execute([':rid'=>$rid]); $donors=$s->fetchAll(PDO::FETCH_COLUMN,0); }catch(Exception $e){ $donors=[]; }

            if (!empty($donors)){
                $ins=$conn->prepare("INSERT INTO donations(donor_id,hospital_id,hospital_name,blood_type,status) VALUES(:did,:hid,:hname,:bt,'Completed')");
                foreach($donors as $did){
                    try { $ins->execute([':did'=>(int)$did, ':hid'=>$hospital_id, ':hname'=>$hospitalName, ':bt'=>$req ? (string)$req['blood_type'] : '' ]); } catch(Exception $e) { /* skip duplicate or schema issues */ }
                }
                // Increment inventory for this hospital and blood type by number of completed donations
                if ($req && !empty($req['blood_type'])){
                    $inc = count($donors);
                    if (isset($req['units_needed'])) { $inc = min($inc, (int)$req['units_needed']); }
                    try {
                        $up=$conn->prepare("INSERT INTO blood_inventory(hospital_id,blood_type,units_available,updated_at) VALUES(:hid,:bt,:u,NOW()) ON DUPLICATE KEY UPDATE units_available = units_available + VALUES(units_available), updated_at = NOW()");
                        $up->execute([':hid'=>$hospital_id, ':bt'=>$req['blood_type'], ':u'=>$inc]);
                    } catch(Exception $e) { /* ignore inventory failures */ }
                }
            }

            $conn->commit();
            $updated_msg='Request marked as fulfilled.';
        } catch(Exception $e){ if($conn && $conn->inTransaction()){ $conn->rollBack(); } $error='Unable to fulfill this request.'; }
    }

    // Create appointment (hospital action)
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_appointment'])) {
        $rid = (int)($_POST['request_id'] ?? 0);
        $did = (int)($_POST['donor_id'] ?? 0);
        $dt  = trim((string)($_POST['scheduled_at'] ?? ''));
        if (strpos($dt,'T') !== false) { $dt = str_replace('T',' ',$dt).':00'; }
        try {
            $ok=false; $chk=$conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE id=:rid AND hospital_id=:hid AND status='Pending'"); $chk->execute([':rid'=>$rid, ':hid'=>$hospital_id]); $ok = ((int)$chk->fetchColumn())>0;
            if ($ok) { $acc=$conn->prepare("SELECT COUNT(*) FROM donor_request_responses WHERE request_id=:rid AND donor_id=:did AND status='Accepted'"); $acc->execute([':rid'=>$rid, ':did'=>$did]); $ok = ((int)$acc->fetchColumn())>0; }
            if ($ok && $dt !== '') {
                $insA=$conn->prepare("INSERT INTO appointments(donor_id,hospital_id,request_id,scheduled_at,status) VALUES(:did,:hid,:rid,:dt,'Pending')");
                $insA->execute([':did'=>$did, ':hid'=>$hospital_id, ':rid'=>$rid, ':dt'=>$dt]);
                // notify donor and send email
                try {
                    $hn=''; $h=$conn->prepare("SELECT hospital_name FROM hospitals WHERE id=:id"); $h->execute([':id'=>$hospital_id]); $hn=(string)$h->fetchColumn();
                    $title='Appointment scheduled';
                    $body='Your appointment at '.($hn?:'the hospital').' is set for '.date('M j, Y H:i', strtotime($dt));
                    $link='donor_dashboard.php';
                    $n=$conn->prepare("INSERT INTO notifications(recipient_type,recipient_id,title,body,link) VALUES('donor',:rid,:t,:b,:l)");
                    $n->execute([':rid'=>$did, ':t'=>$title, ':b'=>$body, ':l'=>$link]);
                    // Email
                    try { $d=$conn->prepare("SELECT fullname,email FROM donors WHERE id=:id"); $d->execute([':id'=>$did]); $drow=$d->fetch(PDO::FETCH_ASSOC); if($drow && !empty($conf['smtp_user'])){ $mailContent=['name_from'=>$conf['site_name'],'email_from'=>$conf['smtp_user'],'name_to'=>$drow['fullname']??'Donor','email_to'=>$drow['email']??'','subject'=>'Your blood donation appointment','body'=>'<p>Hello '.htmlspecialchars($drow['fullname']??'Donor').',</p><p>Your appointment at '.htmlspecialchars($hn?:'the hospital').' is scheduled for <strong>'.date('M j, Y H:i', strtotime($dt)).'</strong>.</p>']; $ObjSendMail->Send_Mail($conf,$mailContent);} } catch(Exception $me){}
                } catch(Exception $ee){}
                $updated_msg='Appointment created.';
            } else { $error='Unable to create appointment.'; }
        } catch(Exception $e) { $error='Unable to create appointment.'; }
    }

    // Cancel request (hospital action)
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cancel_request_id'])) {
        $rid = (int)$_POST['cancel_request_id'];
        try {
            $q=$conn->prepare("UPDATE blood_requests SET status='Cancelled' WHERE id=:rid AND hospital_id=:hid AND status='Pending'");
            $q->execute([':rid'=>$rid, ':hid'=>$hospital_id]);
            if ($q->rowCount()>0) { $updated_msg='Request cancelled.'; } else { $error='Unable to cancel this request.'; }
        } catch(Exception $e){ $error='Unable to cancel this request.'; }
    }

    $sql="SELECT id,blood_type,units_needed,urgency,status,created_at FROM blood_requests WHERE $where ORDER BY created_at DESC";
    $stmt=$conn->prepare($sql); $stmt->execute([':hid'=>$hospital_id]); $requests=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $conf['page_title']='Hospital Requests | BloodBank'; $Objlayout->header($conf); $Objlayout->dashboardStart($conf,''); ?>
    <?php if($created): ?><div class="card" style="border-color:#d1fae5;background:#ecfdf5;color:#065f46;">Request created successfully.</div><?php elseif(!empty($updated_msg)): ?><div class="card" style="border-color:#dbeafe;background:#eff6ff;color:#1e3a8a;"><?php echo htmlspecialchars($updated_msg); ?></div><?php elseif(!empty($error)): ?><div class="card" style="border-color:#fee2e2;background:#fef2f2;color:#991b1b;"><?php echo $error; ?></div><?php endif; ?>
    <div class="grid stats-grid" style="margin-bottom:12px;">
        <div class="card stat"><div class="stat-title">Filter</div><div class="stat-hint">Viewing: <?php echo htmlspecialchars(ucfirst($filter)); ?></div></div>
        <div class="card stat"><div class="stat-title">Requests</div><div class="stat-value"><?php echo count($requests); ?></div><div class="stat-hint">in list</div></div>
        <div class="card stat hide-mobile"><div class="stat-title">Quick Actions</div><div class="stat-hint"><a href="?filter=active">Active</a> • <a href="?filter=history">History</a></div></div>
    </div>
    <div class="card"><div class="card-title">Create Blood Request</div>
        <form method="post" class="actions-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
            <input type="hidden" name="create_request" value="1" />
            <div><label style="display:block;color:#6b7280;font-size:12px;">Blood Type</label><input name="blood_type" required placeholder="A+, O-, …" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
            <div><label style="display:block;color:#6b7280;font-size:12px;">Units</label><input name="units_needed" type="number" min="1" value="1" required style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
            <div><label style="display:block;color:#6b7280;font-size:12px;">Urgency</label><select name="urgency" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"><option>Low</option><option selected>Medium</option><option>High</option></select></div>
            <div style="align-self:end;"><button class="btn-primary" type="submit" style="width:100%;padding:10px 14px;">Post Request</button></div>
        </form>
        <script>
          (function(){
            try {
              var form = document.querySelector('.actions-grid');
              if (!form) return;
              var input = form.querySelector('input[name="blood_type"]');
              if (!input) return;
              var wrap = input.parentNode;
              var select = document.createElement('select');
              select.name = 'blood_type';
              select.required = true;
              select.setAttribute('style', input.getAttribute('style') || '');
              var opt0 = document.createElement('option');
              opt0.value = '';
              opt0.disabled = true; opt0.selected = true;
              opt0.textContent = 'Select Blood Type';
              select.appendChild(opt0);
              ['A+','A-','B+','B-','O+','O-','AB+','AB-'].forEach(function(bt){
                var o = document.createElement('option'); o.value = bt; o.textContent = bt; select.appendChild(o);
              });
              wrap.replaceChild(select, input);
            } catch(e) {}
          })();
        </script>
    </div>
    <div class="card" style="margin-top:12px;"><div class="card-title">Requests List</div>
        <?php if(empty($requests)): ?><p>No requests found.</p><?php else: ?><div class="table-wrap"><table class="table"><thead><tr><th>Blood Type</th><th>Units</th><th>Urgency</th><th>Status</th><th>Requested</th><th>Action</th></tr></thead><tbody><?php foreach($requests as $r): ?><tr><td><?php echo htmlspecialchars($r['blood_type']); ?></td><td><?php echo (int)$r['units_needed']; ?></td><td><?php echo htmlspecialchars($r['urgency']); ?></td><td><?php echo htmlspecialchars($r['status']); ?></td><td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td><td><?php if(strcasecmp((string)$r['status'],'Pending')===0): ?><form method="post" style="display:inline;"><input type="hidden" name="fulfill_request_id" value="<?php echo (int)$r['id']; ?>"/><button class="btn-primary" type="submit" style="margin-right:6px;">Mark Fulfilled</button></form><form method="post" style="display:inline;"><input type="hidden" name="cancel_request_id" value="<?php echo (int)$r['id']; ?>"/><button class="btn-outline" type="submit">Cancel</button></form><div style="margin-top:8px;"><?php $donorRows=[]; try{ $d=$conn->prepare("SELECT d.id,d.fullname FROM donor_request_responses drr JOIN donors d ON d.id=drr.donor_id WHERE drr.request_id=:rid AND drr.status='Accepted'"); $d->execute([':rid'=>$r['id']]); $donorRows=$d->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){} if(!empty($donorRows)): ?><form method="post" style="display:flex; gap:6px; align-items:center; margin-top:6px;"><input type="hidden" name="create_appointment" value="1" /><input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>" /><select name="donor_id" required style="padding:6px;border:1px solid #e5e7eb;border-radius:6px;"><option value="" disabled selected>Select Donor</option><?php foreach($donorRows as $dr): ?><option value="<?php echo (int)$dr['id']; ?>"><?php echo htmlspecialchars($dr['fullname']); ?></option><?php endforeach; ?></select><input type="datetime-local" name="scheduled_at" required style="padding:6px;border:1px solid #e5e7eb;border-radius:6px;" /><button class="btn-outline" type="submit">Schedule</button></form><?php else: ?><span style="color:#6b7280; font-size:12px;">No donors have accepted yet.</span><?php endif; ?></div><?php else: ?><span style="color:#065f46;">Done</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); exit; }

// Donor view
if (!isset($_SESSION['donor_id'])) { header('Location: signin.php'); exit(); }
$donor_id = $_SESSION['donor_id'];
// Check availability for donor view actions
$is_available = 1; try{ $c=$conn->prepare("SELECT COALESCE(is_available,1) FROM donors WHERE id=:id"); $c->execute([':id'=>$donor_id]); $is_available=(int)$c->fetchColumn(); }catch(Exception $e){ $is_available=1; }
$accept_msg=''; if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accept_request_id'])){ if(!$is_available){ $accept_msg='You are currently not available to accept requests.'; } else { $rid=(int)$_POST['accept_request_id']; try{ $q=$conn->prepare("INSERT INTO donor_request_responses(donor_id,request_id,status) VALUES(:did,:rid,'Accepted')"); $q->execute([':did'=>$donor_id,':rid'=>$rid]); $accept_msg='You have accepted the request.'; try{ $h=$conn->prepare("SELECT hospital_id FROM blood_requests WHERE id=:r"); $h->execute([':r'=>$rid]); $hid=(int)$h->fetchColumn(); if($hid){ $dn=''; try{$nm=$conn->prepare("SELECT fullname FROM donors WHERE id=:id"); $nm->execute([':id'=>$donor_id]); $dn=(string)$nm->fetchColumn();}catch(Exception $e){} $title='New donor accepted your request'; $body=($dn?($dn.' accepted request #'.$rid):('A donor accepted request #'.$rid)); $link='blood_requests.php?filter=active'; $n=$conn->prepare("INSERT INTO notifications(recipient_type,recipient_id,title,body,link) VALUES('hospital',:rid,:t,:b,:l)"); $n->execute([':rid'=>$hid, ':t'=>$title, ':b'=>$body, ':l'=>$link]); try{ $hs=$conn->prepare("SELECT hospital_name,email FROM hospitals WHERE id=:id"); $hs->execute([':id'=>$hid]); $hrow=$hs->fetch(PDO::FETCH_ASSOC); if($hrow && !empty($conf['smtp_user'])){ $mailContent=['name_from'=>$conf['site_name'],'email_from'=>$conf['smtp_user'],'name_to'=>$hrow['hospital_name']??'Hospital','email_to'=>$hrow['email']??'','subject'=>'A donor accepted your blood request','body'=>'<p>'.htmlspecialchars($dn?:'A donor').' accepted request #'.(int)$rid.'</p>']; $ObjSendMail->Send_Mail($conf,$mailContent);} }catch(Exception $me){} } }catch(Exception $ne){} }catch(Exception $e){ $accept_msg='You have already accepted this request.'; } } }
$donor_bt=''; try{ $q=$conn->prepare("SELECT blood_type FROM donors WHERE id=:id"); $q->execute([':id'=>$donor_id]); $donor_bt=(string)$q->fetchColumn(); }catch(Exception $e){}
$requests=[]; if($is_available){ $stmt=$conn->prepare("SELECT br.id, br.blood_type, br.units_needed, br.urgency, br.created_at, h.hospital_name, h.city, CASE WHEN drr.id IS NULL THEN 0 ELSE 1 END AS accepted FROM blood_requests br JOIN hospitals h ON br.hospital_id=h.id LEFT JOIN donor_request_responses drr ON drr.request_id=br.id AND drr.donor_id=:did WHERE br.status='Pending' ORDER BY br.created_at DESC"); $stmt->execute([':did'=>$donor_id]); $requests=$stmt->fetchAll(PDO::FETCH_ASSOC); }

$conf['page_title']='Blood Requests | BloodBank'; $Objlayout->header($conf); ?>
<?php $Objlayout->donorDashboardStart($conf,'requests'); ?>
<div class="card"><div class="card-title">Active Blood Requests</div>
<?php if(!$is_available): ?><div class="card" style="border-color:#fee2e2;background:#fff1f2;color:#9f1239;margin-bottom:10px;">You are not available to receive requests. Turn on availability from your dashboard.</div><?php endif; ?>
<?php if($accept_msg): ?><div class="card" style="border-color:#dbeafe;background:#eff6ff;color:#1e3a8a;margin-bottom:10px;"><?php echo htmlspecialchars($accept_msg); ?></div><?php endif; ?>
<?php if(empty($requests)): ?><p>No active blood requests at the moment.</p><?php else: ?>
 <div class="table-wrap"><table class="table"><thead><tr><th>Hospital</th><th>City</th><th>Blood Type</th><th>Units Needed</th><th>Urgency</th><th>Date Requested</th><th>Action</th></tr></thead><tbody>
 <?php foreach($requests as $r): ?><tr>
   <td><?php echo htmlspecialchars($r['hospital_name']); ?></td>
   <td><?php echo htmlspecialchars($r['city']); ?></td>
   <td><?php echo htmlspecialchars($r['blood_type']); ?></td>
   <td><?php echo (int)$r['units_needed']; ?></td>
   <td><?php echo htmlspecialchars($r['urgency']); ?></td>
   <td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
   <td>
     <?php $canAccept = (strcasecmp((string)$r['blood_type'], (string)$donor_bt) === 0); if($r['accepted']): ?>
       <span style="color:#065f46;">Accepted</span>
     <?php else: ?>
        <form method="post" style="display:inline;"><input type="hidden" name="accept_request_id" value="<?php echo (int)$r['id']; ?>"/><?php if($is_available): ?><button class="btn-primary" type="submit" <?php echo $canAccept?'':'disabled'; ?>>Accept</button><?php else: ?><button class="btn-primary" type="button" disabled>Accept</button><?php endif; ?></form>
     <?php endif; ?>
   </td>
 </tr><?php endforeach; ?>
 </tbody></table></div>
<?php endif; ?></div>
<?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); ?>
