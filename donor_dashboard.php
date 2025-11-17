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
                        $ins=$conn->prepare("INSERT INTO appointment(donor_id,hospital_id,request_id,scheduled_at,status) VALUES(:did,:hid,:rid,:dt,'Pending')");
                        $ins->execute([':did'=>$donor_id, ':hid'=>$hid, ':rid'=>$rid, ':dt'=>$scheduledAt]);
                    }
                    $conn->commit();
                    $schedule_msg='Appointment schedul for '.date('M j, Y g:i A', $ts).'.';
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