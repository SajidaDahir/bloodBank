<?php
session_start();
require_once 'ClassAutoLoad.php';

// Hospital view using dashboard shell
if (isset($_SESSION['hospital_id'])) {
    $hospital_id = $_SESSION['hospital_id'];
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'active';
    $where = "hospital_id = :hid";
    if ($filter === 'active') { $where .= " AND (status IS NULL OR status NOT IN ('closed','completed','fulfilled'))"; }
    elseif ($filter === 'history') { $where .= " AND status IN ('closed','completed','fulfilled')"; }

    $created = false; $error='';
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_request'])) {
        try {
            $q=$conn->prepare("INSERT INTO blood_requests(hospital_id,blood_type,units_needed,urgency,status,created_at) VALUES(:hid,:bt,:u,:urg,'Pending',NOW())");
            $q->execute([':hid'=>$hospital_id,':bt'=>trim($_POST['blood_type']),':u'=>(int)$_POST['units_needed'],':urg'=>trim($_POST['urgency'])]);
            $created=true;
        } catch(Exception $e){ $error='Could not create request.'; }
    }

    $sql="SELECT id,blood_type,units_needed,urgency,status,created_at FROM blood_requests WHERE $where ORDER BY created_at DESC";
    $stmt=$conn->prepare($sql); $stmt->execute([':hid'=>$hospital_id]); $requests=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $conf['page_title']='Hospital Requests | BloodBank'; $Objlayout->header($conf); $Objlayout->dashboardStart($conf,''); ?>
    <?php if($created): ?><div class="card" style="border-color:#d1fae5;background:#ecfdf5;color:#065f46;">Request created successfully.</div><?php elseif(!empty($error)): ?><div class="card" style="border-color:#fee2e2;background:#fef2f2;color:#991b1b;"><?php echo $error; ?></div><?php endif; ?>
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
            <div><label style="display:block;color:#6b7280;font-size:12px;">Urgency</label><select name="urgency" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"><option>Normal</option><option>High</option><option>Critical</option></select></div>
            <div style="align-self:end;"><button class="btn-primary" type="submit" style="width:100%;padding:10px 14px;">Post Request</button></div>
        </form>
    </div>
    <div class="card" style="margin-top:12px;"><div class="card-title">Requests List</div>
        <?php if(empty($requests)): ?><p>No requests found.</p><?php else: ?><div class="table-wrap"><table class="table"><thead><tr><th>Blood Type</th><th>Units</th><th>Urgency</th><th>Status</th><th>Requested</th></tr></thead><tbody><?php foreach($requests as $r): ?><tr><td><?php echo htmlspecialchars($r['blood_type']); ?></td><td><?php echo (int)$r['units_needed']; ?></td><td><?php echo htmlspecialchars($r['urgency']); ?></td><td><?php echo htmlspecialchars($r['status']); ?></td><td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); exit; }

// Donor view
if (!isset($_SESSION['donor_id'])) { header('Location: signin.php'); exit(); }
$donor_id = $_SESSION['donor_id'];
$accept_msg=''; if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accept_request_id'])){ $rid=(int)$_POST['accept_request_id']; try{ $q=$conn->prepare("INSERT INTO donor_request_responses(donor_id,request_id,status) VALUES(:did,:rid,'Accepted')"); $q->execute([':did'=>$donor_id,':rid'=>$rid]); $accept_msg='You have accepted the request.'; }catch(Exception $e){ $accept_msg='You have already accepted this request.'; } }
$donor_bt=''; try{ $q=$conn->prepare("SELECT blood_type FROM donors WHERE id=:id"); $q->execute([':id'=>$donor_id]); $donor_bt=(string)$q->fetchColumn(); }catch(Exception $e){}
$stmt=$conn->prepare("SELECT br.id, br.blood_type, br.units_needed, br.urgency, br.created_at, h.hospital_name, h.city, CASE WHEN drr.id IS NULL THEN 0 ELSE 1 END AS accepted FROM blood_requests br JOIN hospitals h ON br.hospital_id=h.id LEFT JOIN donor_request_responses drr ON drr.request_id=br.id AND drr.donor_id=:did WHERE br.status='Pending' ORDER BY br.created_at DESC");
$stmt->execute([':did'=>$donor_id]); $requests=$stmt->fetchAll(PDO::FETCH_ASSOC);

$conf['page_title']='Blood Requests | BloodBank'; $Objlayout->header($conf); ?>
<?php $Objlayout->donorDashboardStart($conf,'requests'); ?>
<div class="card"><div class="card-title">Active Blood Requests</div>
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
       <form method="post" style="display:inline;"><input type="hidden" name="accept_request_id" value="<?php echo (int)$r['id']; ?>"/><button class="btn-primary" type="submit" <?php echo $canAccept?'':'disabled'; ?>>Accept</button></form>
     <?php endif; ?>
   </td>
 </tr><?php endforeach; ?>
 </tbody></table></div>
<?php endif; ?></div>
<?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); ?>

