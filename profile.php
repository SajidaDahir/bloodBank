<?php
session_start();
require_once 'ClassAutoLoad.php';

// Hospital profile view (dashboard shell)
if (isset($_SESSION['hospital_id'])) {
    $hid = $_SESSION['hospital_id'];
    $hospital = null;
    try { $stmt=$conn->prepare("SELECT hospital_name AS name, contact_person, email, phone, city, address, created_at FROM hospitals WHERE id = :id"); $stmt->execute([':id'=>$hid]); $hospital=$stmt->fetch(PDO::FETCH_ASSOC);} catch (Exception $e) { $hospital = null; }
    $conf['page_title'] = 'Hospital Profile | BloodBank'; $Objlayout->header($conf); $Objlayout->dashboardStart($conf, 'profile'); ?>
    <div class="card"><div class="card-title">Hospital Information</div>
    <?php if ($hospital): ?><div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px;">
        <div><strong>Name</strong><div><?php echo htmlspecialchars($hospital['name']); ?></div></div>
        <div><strong>Contact</strong><div><?php echo htmlspecialchars($hospital['contact_person']); ?></div></div>
        <div><strong>Email</strong><div><?php echo htmlspecialchars($hospital['email']); ?></div></div>
        <div><strong>Phone</strong><div><?php echo htmlspecialchars($hospital['phone']); ?></div></div>
        <div><strong>City</strong><div><?php echo htmlspecialchars($hospital['city']); ?></div></div>
        <div><strong>Address</strong><div><?php echo htmlspecialchars($hospital['address']); ?></div></div>
        <div><strong>Joined</strong><div><?php echo date('F j, Y', strtotime($hospital['created_at'])); ?></div></div>
    </div><?php else: ?><p>Hospital details not available.</p><?php endif; ?></div>
    <?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); exit; }

// Donor profile view
if (!isset($_SESSION['donor_id'])) { header("Location: signin.php"); exit(); }
$donor_id = $_SESSION['donor_id'];
$message = ''; $messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $blood_type = strtoupper(trim($_POST['blood_type'] ?? ''));
    $dob = trim($_POST['dob'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $ec_name = trim($_POST['ec_name'] ?? '');
    $ec_phone = trim($_POST['ec_phone'] ?? '');

    // Validate blood type strictly against allowed set
    $validBloodTypes = ['A+','A-','B+','B-','O+','O-','AB+','AB-'];
    if (!in_array($blood_type, $validBloodTypes, true)) {
        $message = 'Invalid blood type selected.'; $messageClass = 'error';
    } else {
        try {
            $stmt = $conn->prepare("UPDATE donors SET fullname = :fullname, phone = :phone, blood_type = :blood_type, location = :location WHERE id = :id");
            $stmt->execute([':fullname'=>$fullname, ':phone'=>$phone, ':blood_type'=>$blood_type, ':location'=>$city, ':id'=>$donor_id]);
            if ($email !== '') { try { $q=$conn->prepare("UPDATE donors SET email=:e WHERE id=:id"); $q->execute([':e'=>$email, ':id'=>$donor_id]); } catch(Exception $e){} }
            if ($dob !== '') { try { $q=$conn->prepare("UPDATE donors SET date_of_birth=:d WHERE id=:id"); $q->execute([':d'=>$dob, ':id'=>$donor_id]); } catch(Exception $e){} }
            if ($country !== '') { try { $q=$conn->prepare("UPDATE donors SET country=:c WHERE id=:id"); $q->execute([':c'=>$country, ':id'=>$donor_id]); } catch(Exception $e){} }
            if ($address !== '') { try { $q=$conn->prepare("UPDATE donors SET address=:a WHERE id=:id"); $q->execute([':a'=>$address, ':id'=>$donor_id]); } catch(Exception $e){} }
            if ($ec_name !== '' || $ec_phone !== '') { try { $q=$conn->prepare("UPDATE donors SET emergency_contact_name=:n, emergency_contact_phone=:p WHERE id=:id"); $q->execute([':n'=>$ec_name, ':p'=>$ec_phone, ':id'=>$donor_id]); } catch(Exception $e){} }
            $message='Profile updated successfully!'; $messageClass='success';
        } catch(Exception $e){ $message='Could not update profile.'; $messageClass='error'; }
    }
}

$stmt=$conn->prepare("SELECT * FROM donors WHERE id=:id"); $stmt->execute([':id'=>$donor_id]); $donor=$stmt->fetch(PDO::FETCH_ASSOC);

$conf['page_title'] = 'Profile | BloodBank'; $Objlayout->header($conf);
?>
<?php $Objlayout->donorDashboardStart($conf,'profile'); ?>

<div class="card">
  <div class="card-title">Edit Profile</div>
  <?php if (!empty($message)): ?><div class="card" style="border-color:<?php echo $messageClass==='success'?'#d1fae5':'#fee2e2'; ?>; background:<?php echo $messageClass==='success'?'#ecfdf5':'#fef2f2'; ?>; color:<?php echo $messageClass==='success'?'#065f46':'#991b1b'; ?>; margin-bottom:10px;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
  <form method="POST">
    <div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px;">
      <div><label style="display:block;color:#6b7280;font-size:12px;">Full Name</label><input name="fullname" value="<?php echo htmlspecialchars($donor['fullname'] ?? ''); ?>" required style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
      <div><label style="display:block;color:#6b7280;font-size:12px;">Date of Birth</label><input type="date" name="dob" value="<?php echo htmlspecialchars($donor['date_of_birth'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
      <div><label style="display:block;color:#6b7280;font-size:12px;">Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($donor['email'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
      <div><label style="display:block;color:#6b7280;font-size:12px;">Phone Number</label><input name="phone" value="<?php echo htmlspecialchars($donor['phone'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
      <div><label style="display:block;color:#6b7280;font-size:12px;">Blood Type</label><select name="blood_type" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"><?php $bt=$donor['blood_type']??''; foreach(['A+','A-','B+','B-','O+','O-','AB+','AB-'] as $t){ $sel=$bt===$t?'selected':''; echo "<option $sel>".htmlspecialchars($t)."</option>"; } ?></select></div>
      <div></div>
    </div>
    <div style="margin:12px 0;color:#6b7280;">Location</div>
    <div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px;">
      <div><label style="display:block;color:#6b7280;font-size:12px;">City</label><input name="city" value="<?php echo htmlspecialchars($donor['location'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
      <div><label style="display:block;color:#6b7280;font-size:12px;">Country</label><input name="country" value="<?php echo htmlspecialchars($donor['country'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
      <div style="grid-column:1/-1;"><label style="display:block;color:#6b7280;font-size:12px;">Address</label><input name="address" value="<?php echo htmlspecialchars($donor['address'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
    </div>
    <div style="margin:12px 0;color:#6b7280;">Emergency Contact</div>
    <div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px;">
      <div><label style="display:block;color:#6b7280;font-size:12px;">Contact Name</label><input name="ec_name" value="<?php echo htmlspecialchars($donor['emergency_contact_name'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
      <div><label style="display:block;color:#6b7280;font-size:12px;">Contact Phone</label><input name="ec_phone" value="<?php echo htmlspecialchars($donor['emergency_contact_phone'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
    </div>
    <div style="display:flex;gap:10px;margin-top:14px;"><button type="submit" class="btn-primary">Save Changes</button><a href="donor_dashboard.php" class="btn-outline" style="display:inline-flex;align-items:center;">Cancel</a></div>
  </form>
</div>

<?php $Objlayout->dashboardEnd(); $Objlayout->footer($conf); ?>

