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

    $allowedBT = ['A+','A-','B+','B-','O+','O-','AB+','AB-'];
    $allowedRequestTypes = ['specific','general'];
    $created = false; $updated_msg=''; $error='';
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_request'])) {
        try {
            $requestType = strtolower(trim((string)($_POST['request_type'] ?? 'specific')));
            if (!in_array($requestType, $allowedRequestTypes, true)) { $requestType = 'specific'; }
            // Ensure urgency matches DB enum values
            $allowedUrg = ['Low','Medium','High'];
            $urg = trim((string)($_POST['urgency'] ?? ''));
            if (!in_array($urg, $allowedUrg, true)) { $urg = 'Medium'; }

            $units = (int)($_POST['units_needed'] ?? 1);
            if ($units <= 0) { throw new Exception('Invalid units'); }

            $bt = null;
            if ($requestType === 'specific') {
                $bt = strtoupper(trim((string)($_POST['blood_type'] ?? '')));
                if (!in_array($bt, $allowedBT, true)) { throw new Exception('Invalid blood type'); }
            }

            $deadlineRaw = trim((string)($_POST['deadline_at'] ?? ''));
            $deadline = null;
            if ($urg === 'Low') {
                $deadline = null;
            } else {
                if ($deadlineRaw === '') {
                    if ($urg === 'High') { throw new Exception('Deadline required for high urgency requests.'); }
                    $deadline = null;
                } else {
                    $deadlineRaw = str_replace('T',' ',$deadlineRaw);
                    if (strlen($deadlineRaw) === 16) { $deadlineRaw .= ':00'; }
                    $ts = strtotime($deadlineRaw);
                    if ($ts === false || $ts <= time()) { throw new Exception('Deadline must be a future date/time.'); }
                    $deadline = date('Y-m-d H:i:s', $ts);
                }
            }

            $q=$conn->prepare("INSERT INTO blood_requests(hospital_id,request_type,blood_type,units_needed,urgency,deadline_at,status,created_at) VALUES(:hid,:rtype,:bt,:u,:urg,:deadline,'Pending',NOW())");
            $q->execute([':hid'=>$hospital_id,':rtype'=>$requestType,':bt'=>$bt,':u'=>$units,':urg'=>$urg,':deadline'=>$deadline]);
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
            try { $s=$conn->prepare("SELECT request_type, blood_type, units_needed FROM blood_requests WHERE id=:rid"); $s->execute([':rid'=>$rid]); $req=$s->fetch(PDO::FETCH_ASSOC); } catch(Exception $e){}
            try { $s=$conn->prepare("SELECT hospital_name FROM hospitals WHERE id=:hid"); $s->execute([':hid'=>$hospital_id]); $hospitalName=(string)$s->fetchColumn(); } catch(Exception $e){}

            // Get accepting donors for this request
            $donors=[]; try{ $s=$conn->prepare("SELECT drr.donor_id, d.blood_type FROM donor_request_responses drr JOIN donors d ON d.id=drr.donor_id WHERE drr.request_id=:rid AND drr.status='Accepted'"); $s->execute([':rid'=>$rid]); $donors=$s->fetchAll(PDO::FETCH_ASSOC); }catch(Exception $e){ $donors=[]; }

            if (empty($donors)) {
                throw new Exception('Need at least one accepted donor to fulfill this request.');
            }

            if (!empty($donors)){
                $ins=$conn->prepare("INSERT INTO donations(donor_id,hospital_id,hospital_name,blood_type,status) VALUES(:did,:hid,:hname,:bt,'Completed')");
                $inventoryAdds=[]; $unitsLimit = ($req && isset($req['units_needed'])) ? max(0,(int)$req['units_needed']) : null; $credited = 0;
                foreach($donors as $donorRow){
                    $donorId = (int)$donorRow['donor_id'];
                    $donationBT = bloodbank_normalize_blood_type($donorRow['blood_type'] ?? '');
                    if ($donationBT === '') {
                        $donationBT = bloodbank_normalize_blood_type($req['blood_type'] ?? '');
                    }
                    try { $ins->execute([':did'=>$donorId, ':hid'=>$hospital_id, ':hname'=>$hospitalName, ':bt'=>$donationBT]); } catch(Exception $e) { /* skip duplicate or schema issues */ }
                    if ($donationBT !== '' && ($unitsLimit === null || $credited < $unitsLimit)) {
                        $inventoryAdds[$donationBT] = ($inventoryAdds[$donationBT] ?? 0) + 1;
                        $credited++;
                    }
                }
                if (!empty($inventoryAdds)){
                    try {
                        $up=$conn->prepare("INSERT INTO blood_inventory(hospital_id,blood_type,units_available,updated_at) VALUES(:hid,:bt,:u,NOW()) ON DUPLICATE KEY UPDATE units_available = units_available + VALUES(units_available), updated_at = NOW()");
                        foreach($inventoryAdds as $bt => $inc){
                            $up->execute([':hid'=>$hospital_id, ':bt'=>$bt, ':u'=>$inc]);
                        }
                    } catch(Exception $e) { /* ignore inventory failures */ }
                }
            }

            $conn->commit();
            $updated_msg='Request marked as fulfilled.';
        } catch(Exception $e){ if($conn && $conn->inTransaction()){ $conn->rollBack(); } $error='Unable to fulfill this request.'; }
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

    $sql="SELECT id,request_type,blood_type,units_needed,urgency,deadline_at,status,created_at FROM blood_requests WHERE $where ORDER BY created_at DESC";
    $stmt=$conn->prepare($sql); $stmt->execute([':hid'=>$hospital_id]); $requests=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $conf['page_title']='Hospital Requests | BloodBank'; $Objlayout->header($conf); $Objlayout->dashboardStart($conf,''); ?>
    <?php if($created): ?><div class="card" style="border-color:#d1fae5;background:#ecfdf5;color:#065f46;">Request created successfully.</div><?php elseif(!empty($updated_msg)): ?><div class="card" style="border-color:#dbeafe;background:#eff6ff;color:#1e3a8a;"><?php echo htmlspecialchars($updated_msg); ?></div><?php elseif(!empty($error)): ?><div class="card" style="border-color:#fee2e2;background:#fef2f2;color:#991b1b;"><?php echo $error; ?></div><?php endif; ?>
    <div class="grid stats-grid" style="margin-bottom:12px;">
        <div class="card stat"><div class="stat-title">Filter</div><div class="stat-hint">Viewing: <?php echo htmlspecialchars(ucfirst($filter)); ?></div></div>
        <div class="card stat"><div class="stat-title">Requests</div><div class="stat-value"><?php echo count($requests); ?></div><div class="stat-hint">in list</div></div>
        <div class="card stat hide-mobile"><div class="stat-title">Quick Actions</div><div class="stat-hint"><a href="?filter=active">Active</a> • <a href="?filter=history">History</a></div></div>
    </div>
    <div class="card"><div class="card-title">Create Blood Request</div>
        <form method="post" class="actions-grid" style="grid-template-columns: repeat(5, minmax(0, 1fr));">
            <input type="hidden" name="create_request" value="1" />
            <div>
              <label style="display:block;color:#6b7280;font-size:12px;">Request Type</label>
              <select name="request_type" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;">
                <option value="specific" selected>Specific blood type</option>
                <option value="general">General (any blood)</option>
              </select>
            </div>
            <div data-blood-field>
              <label style="display:block;color:#6b7280;font-size:12px;">Blood Type Needed</label>
              <select name="blood_type" required style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;">
                <option value="" disabled selected>Select Blood Type</option>
                <?php foreach ($allowedBT as $bt): ?>
                  <option value="<?php echo $bt; ?>"><?php echo $bt; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div><label style="display:block;color:#6b7280;font-size:12px;">Units</label><input name="units_needed" type="number" min="1" value="1" required style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/><small style="display:block;color:#9ca3af;font-size:11px;margin-top:4px;">Each donor provides one unit.</small></div>
            <div><label style="display:block;color:#6b7280;font-size:12px;">Urgency</label><select name="urgency" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"><option>Low</option><option selected>Medium</option><option>High</option></select></div>
            <div data-deadline-field>
              <label style="display:block;color:#6b7280;font-size:12px;">Deadline (Medium/High)</label>
              <input type="datetime-local" name="deadline_at" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;" />
              <small style="display:block;color:#9ca3af;font-size:11px;margin-top:4px;">Required for High urgency, optional for Medium. Low urgency requests cannot set a deadline.</small>
            </div>
            <div style="align-self:end;"><button class="btn-primary" type="submit" style="width:100%;padding:10px 14px;">Post Request</button></div>
        </form>
        <script>
          (function(){
            try {
              var form = document.querySelector('.actions-grid');
              if (!form) return;
              var typeSelect = form.querySelector('select[name="request_type"]');
              var bloodSelect = form.querySelector('select[name="blood_type"]');
              var bloodWrap = bloodSelect ? bloodSelect.closest('[data-blood-field]') : null;
              var urgencySelect = form.querySelector('select[name="urgency"]');
              var deadlineWrap = form.querySelector('[data-deadline-field]');
              var deadlineInput = deadlineWrap ? deadlineWrap.querySelector('input[name="deadline_at"]') : null;
              var toggle = function(){
                if (!typeSelect || !bloodSelect) return;
                var specific = typeSelect.value === 'specific';
                bloodSelect.disabled = !specific;
                bloodSelect.required = specific;
                if (bloodWrap) { bloodWrap.style.display = specific ? '' : 'none'; }
                if (!specific) { bloodSelect.value = ''; }
              };
              var toggleDeadline = function(){
                if (!urgencySelect || !deadlineWrap || !deadlineInput) return;
                var urg = urgencySelect.value || '';
                if (urg === 'Low') {
                  deadlineWrap.style.display = 'none';
                  deadlineInput.value = '';
                  deadlineInput.required = false;
                } else {
                  deadlineWrap.style.display = '';
                  deadlineInput.required = (urg === 'High');
                }
              };
              if (typeSelect && bloodSelect) {
                typeSelect.addEventListener('change', toggle);
                toggle();
              }
              if (deadlineInput) {
                var now = new Date();
                deadlineInput.min = new Date(now.getTime() + 5*60000).toISOString().slice(0,16);
              }
              if (urgencySelect) {
                urgencySelect.addEventListener('change', toggleDeadline);
                toggleDeadline();
              }
            } catch(e) {}
          })();
        </script>
    </div>
    <div class="card" style="margin-top:12px;"><div class="card-title">Requests List</div>
        <?php if(empty($requests)): ?><p>No requests found.</p><?php else: ?><div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Blood Needed</th><th>Units</th><th>Urgency</th><th>Deadline</th><th>Status</th><th>Requested</th><th>Action</th></tr></thead><tbody><?php foreach($requests as $r): $deadlineDisplay = !empty($r['deadline_at']) ? date('M j, Y g:i A', strtotime($r['deadline_at'])) : 'None'; ?><tr><td><?php echo htmlspecialchars(ucfirst($r['request_type'] ?? 'specific')); ?></td><td><?php echo htmlspecialchars(bloodbank_format_request_blood_label($r['request_type'] ?? '', $r['blood_type'] ?? '')); ?></td><td><?php echo (int)$r['units_needed']; ?></td><td><?php echo htmlspecialchars($r['urgency']); ?></td><td><?php echo htmlspecialchars($deadlineDisplay); ?></td><td><?php echo htmlspecialchars($r['status']); ?></td><td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td><td><?php if(strcasecmp((string)$r['status'],'Pending')===0): ?><form method="post" style="display:inline;"><input type="hidden" name="fulfill_request_id" value="<?php echo (int)$r['id']; ?>"/><button class="btn-primary" type="submit" style="margin-right:6px;">Mark Fulfilled</button></form><form method="post" style="display:inline;"><input type="hidden" name="cancel_request_id" value="<?php echo (int)$r['id']; ?>"/><button class="btn-outline" type="submit">Cancel</button></form><div style="margin-top:8px;"><?php $donorRows=[]; try{ $d=$conn->prepare("SELECT d.fullname FROM donor_request_responses drr JOIN donors d ON d.id=drr.donor_id WHERE drr.request_id=:rid AND drr.status='Accepted'"); $d->execute([':rid'=>$r['id']]); $donorRows=$d->fetchAll(PDO::FETCH_COLUMN,0);}catch(Exception $e){} if(!empty($donorRows)): ?><div style="font-size:12px;color:#374151;">Accepted donors: <?php echo htmlspecialchars(implode(', ', $donorRows)); ?></div><?php else: ?><span style="color:#6b7280; font-size:12px;">No donors have accepted yet.</span><?php endif; ?><div style="color:#6b7280;font-size:12px;margin-top:6px;">Donors schedule their own appointments once they accept a request.</div></div><?php else: ?><span style="color:#065f46;">Done</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); exit; }

// Donor view
if (!isset($_SESSION['donor_id'])) { header('Location: signin.php'); exit(); }
$donor_id = $_SESSION['donor_id'];
// Check availability for donor view actions
$is_available = 1;
try {
    $c=$conn->prepare("SELECT COALESCE(is_available,1) FROM donors WHERE id=:id");
    $c->execute([':id'=>$donor_id]);
    $is_available=(int)$c->fetchColumn();
} catch(Exception $e){ $is_available=1; }

$donor_bt='';
try{
    $q=$conn->prepare("SELECT blood_type FROM donors WHERE id=:id");
    $q->execute([':id'=>$donor_id]);
    $donor_bt=(string)$q->fetchColumn();
}catch(Exception $e){}

$accept_msg='';
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accept_request_id'])){
    if(!$is_available){
        $accept_msg='You are currently not available to accept requests.';
    } else {
        $rid=(int)$_POST['accept_request_id'];
        try{
            $reqStmt=$conn->prepare("SELECT br.hospital_id, br.request_type, br.blood_type, br.units_needed, br.deadline_at,
                    (SELECT COUNT(*) FROM donor_request_responses WHERE request_id=br.id AND status='Accepted') AS accepted_total
                FROM blood_requests br
                WHERE br.id=:r AND br.status='Pending' AND (br.deadline_at IS NULL OR br.deadline_at >= NOW())");
            $reqStmt->execute([':r'=>$rid]);
            $reqRow=$reqStmt->fetch(PDO::FETCH_ASSOC);
            if(!$reqRow){ throw new Exception('Request unavailable'); }

            if(!bloodbank_can_donor_fulfill_request($donor_bt, $reqRow['request_type'] ?? 'specific', $reqRow['blood_type'] ?? '')){
                $accept_msg='This request requires a different blood type.';
            } else {
                $neededUnits = max(1,(int)($reqRow['units_needed'] ?? 0));
                if ($neededUnits > 0 && (int)($reqRow['accepted_total'] ?? 0) >= $neededUnits) {
                    $accept_msg='This request already has enough donors.';
                } else {
                    try{
                        $q=$conn->prepare("INSERT INTO donor_request_responses(donor_id,request_id,status) VALUES(:did,:rid,'Accepted')");
                        $q->execute([':did'=>$donor_id,':rid'=>$rid]);
                        $accept_msg='You have accepted the request.';
                        $hid=(int)($reqRow['hospital_id'] ?? 0);
                        if($hid){
                            $dn='';
                            try{
                                $nm=$conn->prepare("SELECT fullname FROM donors WHERE id=:id");
                                $nm->execute([':id'=>$donor_id]);
                                $dn=(string)$nm->fetchColumn();
                            }catch(Exception $e){}
                            $title='New donor accepted your request';
                            $body=($dn?($dn.' accepted request #'.$rid):('A donor accepted request #'.$rid));
                            $link='blood_requests.php?filter=active';
                            $n=$conn->prepare("INSERT INTO notifications(recipient_type,recipient_id,title,body,link) VALUES('hospital',:rid,:t,:b,:l)");
                            $n->execute([':rid'=>$hid, ':t'=>$title, ':b'=>$body, ':l'=>$link]);
                            try{
                                $hs=$conn->prepare("SELECT hospital_name,email FROM hospitals WHERE id=:id");
                                $hs->execute([':id'=>$hid]);
                                $hrow=$hs->fetch(PDO::FETCH_ASSOC);
                                if($hrow && !empty($conf['smtp_user'])){
                                    $mailContent=[
                                        'name_from'=>$conf['site_name'],
                                        'email_from'=>$conf['smtp_user'],
                                        'name_to'=>$hrow['hospital_name']??'Hospital',
                                        'email_to'=>$hrow['email']??'',
                                        'subject'=>'A donor accepted your blood request',
                                        'body'=>'<p>'.htmlspecialchars($dn?:'A donor').' accepted request #'.(int)$rid.'</p>'
                                    ];
                                    $ObjSendMail->Send_Mail($conf,$mailContent);
                                }
                            }catch(Exception $me){}
                        }
                    } catch(Exception $dup){
                        $accept_msg='You have already accepted this request.';
                    }
                }
            }
        }catch(Exception $e){
            if(!$accept_msg){ $accept_msg='Unable to accept this request.'; }
        }
    }
}
$requests=[];
if($is_available){
    $eligible = bloodbank_recipient_types_for_donor($donor_bt);
    $params = [':did'=>$donor_id];
    $matchParts = ["br.request_type='general'"];
    if(!empty($eligible)){
        $holders=[];
        foreach($eligible as $idx=>$btVal){
            $key=':bt'.$idx;
            $holders[]=$key;
            $params[$key]=$btVal;
        }
        $matchParts[]="(br.request_type='specific' AND br.blood_type IN (".implode(',', $holders)."))";
    }
    $sql="SELECT br.id, br.request_type, br.blood_type, br.units_needed, br.urgency, br.deadline_at, br.created_at, h.hospital_name, h.city,
                 CASE WHEN drr.id IS NULL THEN 0 ELSE 1 END AS accepted,
                 COALESCE(stats.accepted_total,0) AS accepted_total
          FROM blood_requests br
          JOIN hospitals h ON br.hospital_id=h.id
          LEFT JOIN donor_request_responses drr ON drr.request_id=br.id AND drr.donor_id=:did
          LEFT JOIN (
                SELECT request_id, COUNT(*) AS accepted_total
                FROM donor_request_responses
                WHERE status='Accepted'
                GROUP BY request_id
          ) stats ON stats.request_id = br.id
          WHERE br.status='Pending'
            AND (br.deadline_at IS NULL OR br.deadline_at >= NOW())
            AND (br.units_needed IS NULL OR br.units_needed <= 0 OR COALESCE(stats.accepted_total,0) < br.units_needed)
            AND (".implode(' OR ', $matchParts).")
          ORDER BY br.created_at DESC";
    $stmt=$conn->prepare($sql);
    $stmt->execute($params);
    $requests=$stmt->fetchAll(PDO::FETCH_ASSOC);
}

$conf['page_title']='Blood Requests | BloodBank'; $Objlayout->header($conf); ?>
<?php $Objlayout->donorDashboardStart($conf,'requests'); ?>
<div class="card"><div class="card-title">Active Blood Requests</div>
<p style="color:#6b7280;font-size:13px;margin-bottom:8px;">Each donor contributes one unit. Requests close automatically once the deadline (if any) passes.</p>
<?php if(!$is_available): ?><div class="card" style="border-color:#fee2e2;background:#fff1f2;color:#9f1239;margin-bottom:10px;">You are not available to receive requests. Turn on availability from your dashboard.</div><?php endif; ?>
<?php if($accept_msg): ?><div class="card" style="border-color:#dbeafe;background:#eff6ff;color:#1e3a8a;margin-bottom:10px;"><?php echo htmlspecialchars($accept_msg); ?></div><?php endif; ?>
<?php if(empty($requests)): ?><p>No active blood requests at the moment.</p><?php else: ?>
 <div class="table-wrap"><table class="table"><thead><tr><th>Hospital</th><th>City</th><th>Request Type</th><th>Blood Needed</th><th>Units Needed</th><th>Filled</th><th>Urgency</th><th>Deadline</th><th>Date Requested</th><th>Action</th></tr></thead><tbody>
 <?php foreach($requests as $r): $deadlineDisplay = !empty($r['deadline_at']) ? date('M j, Y g:i A', strtotime($r['deadline_at'])) : 'None'; $unitsNeeded = max(1,(int)$r['units_needed']); $filledCount = min($unitsNeeded, (int)($r['accepted_total'] ?? 0)); $hasCapacity = $filledCount < $unitsNeeded; ?><tr>
   <td><?php echo htmlspecialchars($r['hospital_name']); ?></td>
   <td><?php echo htmlspecialchars($r['city']); ?></td>
   <td><?php echo htmlspecialchars(ucfirst($r['request_type'] ?? 'specific')); ?></td>
   <td><?php echo htmlspecialchars(bloodbank_format_request_blood_label($r['request_type'] ?? '', $r['blood_type'] ?? '')); ?></td>
   <td><?php echo (int)$r['units_needed']; ?></td>
   <td><?php echo $filledCount.' / '.$unitsNeeded; ?></td>
   <td><?php echo htmlspecialchars($r['urgency']); ?></td>
   <td><?php echo htmlspecialchars($deadlineDisplay); ?></td>
   <td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
   <td>
     <?php $canAccept = $hasCapacity && bloodbank_can_donor_fulfill_request($donor_bt, $r['request_type'] ?? '', $r['blood_type'] ?? ''); if(!$hasCapacity): ?>
        <span style="color:#6b7280;">Full</span>
     <?php elseif($r['accepted']): ?>
       <span style="color:#065f46;">Accepted</span>
     <?php else: ?>
        <form method="post" style="display:inline;"><input type="hidden" name="accept_request_id" value="<?php echo (int)$r['id']; ?>"/><?php if($is_available): ?><button class="btn-primary" type="submit" <?php echo $canAccept?'':'disabled'; ?>>Accept</button><?php else: ?><button class="btn-primary" type="button" disabled>Accept</button><?php endif; ?></form>
     <?php endif; ?>
   </td>
 </tr><?php endforeach; ?>
 </tbody></table></div>
<?php endif; ?></div>
<?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); ?>
