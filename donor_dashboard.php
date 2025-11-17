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