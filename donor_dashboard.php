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

$donor_bt = '';
try {
    $btStmt = $conn->prepare("SELECT blood_type FROM donors WHERE id=:id");
    $btStmt->execute([':id' => $donor_id]);
    $donor_bt = (string)$btStmt->fetchColumn();
} catch (Exception $e) {
    $donor_bt = '';
}
$schedule_msg='';
$schedule_error='';

// Accept request
$accept_msg='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['accept_request_id'])){
    if (!$is_available) {
        $accept_msg='You are currently not available to accept requests.';
    } else {
        $rid=(int)$_POST['accept_request_id'];
        try{
            $reqStmt = $conn->prepare("SELECT br.hospital_id, br.request_type, br.blood_type, br.units_needed, br.deadline_at,
                    (SELECT COUNT(*) FROM donor_request_responses WHERE request_id=br.id AND status='Accepted') AS accepted_total
                FROM blood_requests br WHERE br.id=:rid AND br.status='Pending' AND (br.deadline_at IS NULL OR br.deadline_at >= NOW())");
            $reqStmt->execute([':rid'=>$rid]);
            $reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if(!$reqRow){ throw new Exception('Request unavailable'); }
            if(!bloodbank_can_donor_fulfill_request($donor_bt, $reqRow['request_type'] ?? 'specific', $reqRow['blood_type'] ?? '')){
                $accept_msg='This request requires a different blood type.';
            } else {
                $maxUnits = max(1,(int)($reqRow['units_needed'] ?? 0));
                if ($maxUnits > 0 && (int)($reqRow['accepted_total'] ?? 0) >= $maxUnits) {
                    $accept_msg='This request already has enough donors.';
                } else {
                try{
                    $q=$conn->prepare("INSERT INTO donor_request_responses(donor_id,request_id,status) VALUES(:did,:rid,'Accepted')");
                    $q->execute([':did'=>$donor_id,':rid'=>$rid]);
                    $accept_msg='Accepted';
                    $hid = (int)($reqRow['hospital_id'] ?? 0);
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
                }catch(Exception $dup){
                    $accept_msg='Accepted'; // Already accepted or duplicate
                }
                }
            }
        }catch(Exception $e){
            if(!$accept_msg){ $accept_msg='Unable to accept this request.'; }
        }
    }
}

// Schedule appointment (donor initiated)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['schedule_request_id'])) {
    if (!$is_available) {
        $schedule_error = 'Turn on your availability before scheduling.';
    } else {
        $rid = (int)($_POST['schedule_request_id'] ?? 0);
        $dt  = trim((string)($_POST['scheduled_at'] ?? ''));
        if ($rid <= 0 || $dt === '') {
            $schedule_error = 'Select a date and time for your visit.';
        } else {
            if (strpos($dt,'T') !== false) { $dt = str_replace('T',' ',$dt); }
            $dt = rtrim($dt, 'Z');
            $ts = strtotime($dt);
            if ($ts === false) {
                $schedule_error = 'Invalid date provided.';
            } elseif ($ts < time()) {
                $schedule_error = 'Please choose a future time.';
            } else {
                $scheduledAt = date('Y-m-d H:i:s', $ts);
                try {
                    $conn->beginTransaction();
                    $reqStmt = $conn->prepare("SELECT br.id, br.hospital_id, h.hospital_name, br.deadline_at FROM donor_request_responses drr JOIN blood_requests br ON br.id=drr.request_id JOIN hospitals h ON h.id=br.hospital_id WHERE drr.donor_id=:did AND drr.request_id=:rid AND drr.status='Accepted' AND br.status='Pending' AND (br.deadline_at IS NULL OR br.deadline_at >= NOW()) LIMIT 1");
                    $reqStmt->execute([':did'=>$donor_id, ':rid'=>$rid]);
                    $reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$reqRow) { throw new Exception('You can only schedule accepted, active requests.'); }
                    if (!empty($reqRow['deadline_at']) && $ts > strtotime($reqRow['deadline_at'])) {
                        throw new Exception('Selected time exceeds the request deadline.');
                    }
                    $hid = (int)$reqRow['hospital_id'];
                    $apptStmt=$conn->prepare("SELECT id FROM appointments WHERE donor_id=:did AND request_id=:rid LIMIT 1");
                    $apptStmt->execute([':did'=>$donor_id, ':rid'=>$rid]);
                    $existingId = $apptStmt->fetchColumn();
                    if ($existingId) {
                        $upd=$conn->prepare("UPDATE appointments SET scheduled_at=:dt, status='Pending' WHERE id=:id");
                        $upd->execute([':dt'=>$scheduledAt, ':id'=>$existingId]);
                    } else {
                        $ins=$conn->prepare("INSERT INTO appointments(donor_id,hospital_id,request_id,scheduled_at,status) VALUES(:did,:hid,:rid,:dt,'Pending')");
                        $ins->execute([':did'=>$donor_id, ':hid'=>$hid, ':rid'=>$rid, ':dt'=>$scheduledAt]);
                    }
                    $conn->commit();
                    $schedule_msg='Appointment scheduled for '.date('M j, Y g:i A', $ts).'.';
                    try {
                        $title='Donor scheduled appointment';
                        $body='A donor scheduled request #'.$rid.' for '.date('M j, Y g:i A', $ts).'.';
                        $link='hospital_dashboard.php#appointments';
                        $n=$conn->prepare("INSERT INTO notifications(recipient_type,recipient_id,title,body,link) VALUES('hospital',:rid,:t,:b,:l)");
                        $n->execute([':rid'=>$hid, ':t'=>$title, ':b'=>$body, ':l'=>$link]);
                        try {
                            if (!empty($conf['smtp_user'])) {
                                $hs=$conn->prepare("SELECT hospital_name,email FROM hospitals WHERE id=:id");
                                $hs->execute([':id'=>$hid]);
                                $hrow=$hs->fetch(PDO::FETCH_ASSOC);
                                if ($hrow && !empty($hrow['email'])) {
                                    $mailContent=[
                                        'name_from'=>$conf['site_name'],
                                        'email_from'=>$conf['smtp_user'],
                                        'name_to'=>$hrow['hospital_name'] ?? 'Hospital',
                                        'email_to'=>$hrow['email'],
                                        'subject'=>'A donor scheduled an appointment',
                                        'body'=>'<p>A donor scheduled request #'.(int)$rid.' for <strong>'.date('M j, Y g:i A', $ts).'</strong>.</p>'
                                    ];
                                    $ObjSendMail->Send_Mail($conf,$mailContent);
                                }
                            }
                        } catch(Exception $me){}
                    } catch(Exception $ne){}
                } catch(Exception $e){
                    if($conn && $conn->inTransaction()){ $conn->rollBack(); }
                    if (empty($schedule_error)) { $schedule_error='Unable to schedule this appointment.'; }
                }
            }
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
if($donor && $donor_bt===''){ $donor_bt = (string)($donor['blood_type'] ?? ''); }

// Stats and matching
$totalDonations=0; $lastDonation=null; $pendingMatches=0; $livesSaved=0;
try{ $q=$conn->prepare("SELECT COUNT(*) AS c, MAX(created_at) AS last_dt FROM donations WHERE donor_id=:id AND status='Completed'"); $q->execute([':id'=>$donor_id]); $row=$q->fetch(PDO::FETCH_ASSOC); $totalDonations=(int)($row['c']??0); $lastDonation=$row['last_dt']??null; }catch(Exception $e){}
try{
    $eligible = bloodbank_recipient_types_for_donor($donor_bt);
    $params = [];
    $parts = ["request_type='general'"];
    if(!empty($eligible)){
        $holders=[];
        foreach($eligible as $idx=>$btVal){
            $key=':bt'.$idx;
            $holders[]=$key;
            $params[$key]=$btVal;
        }
        $parts[]="(request_type='specific' AND blood_type IN (".implode(',', $holders)."))";
    }
    $sql="SELECT COUNT(*)
          FROM blood_requests br
          LEFT JOIN (
                SELECT request_id, COUNT(*) AS accepted_total
                FROM donor_request_responses
                WHERE status='Accepted'
                GROUP BY request_id
          ) stats ON stats.request_id = br.id
          WHERE br.status='Pending'
            AND (br.deadline_at IS NULL OR br.deadline_at >= NOW())
            AND (br.units_needed IS NULL OR br.units_needed <= 0 OR COALESCE(stats.accepted_total,0) < br.units_needed)
            AND (".implode(' OR ', $parts).")";
    $q=$conn->prepare($sql);
    $q->execute($params);
    $pendingMatches=(int)$q->fetchColumn();
}catch(Exception $e){}
if (empty($donor['is_available'])) { $pendingMatches = 0; }
$livesSaved=$totalDonations;

$activeRequests=[];
if (!empty($donor['is_available'])) {
    try{
        $eligible = bloodbank_recipient_types_for_donor($donor_bt);
        $params = [':did'=>$donor_id];
        $matchParts = ["br.request_type='general'"];
        if(!empty($eligible)){
            $holders=[];
            foreach($eligible as $idx=>$btVal){
                $key=':abt'.$idx;
                $holders[]=$key;
                $params[$key]=$btVal;
            }
            $matchParts[]="(br.request_type='specific' AND br.blood_type IN (".implode(',', $holders)."))";
        }
        $sql="SELECT br.id,br.request_type,br.blood_type,br.units_needed,br.urgency,br.deadline_at,br.created_at,h.hospital_name,h.city,
                     CASE WHEN drr.request_id IS NULL THEN 0 ELSE 1 END AS accepted,
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
              ORDER BY br.created_at DESC LIMIT 6";
        $q=$conn->prepare($sql);
        $q->execute($params);
        $activeRequests=$q->fetchAll(PDO::FETCH_ASSOC);
    }catch(Exception $e){ $activeRequests=[]; }
}

// Notifications for donor
$notifications=[]; try{ $nn=$conn->prepare("SELECT id,title,body,link,created_at,is_read FROM notifications WHERE recipient_type='donor' AND recipient_id=:id ORDER BY is_read ASC, created_at DESC LIMIT 5"); $nn->execute([':id'=>$donor_id]); $notifications=$nn->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){ $notifications=[]; }

// Appointments for donor (upcoming and recent)
$appointments=[]; try{ $ap=$conn->prepare("SELECT a.id,a.scheduled_at,a.status,h.hospital_name FROM appointments a JOIN hospitals h ON h.id=a.hospital_id WHERE a.donor_id=:id ORDER BY a.scheduled_at DESC LIMIT 5"); $ap->execute([':id'=>$donor_id]); $appointments=$ap->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){ $appointments=[]; }
