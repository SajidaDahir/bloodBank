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

?>