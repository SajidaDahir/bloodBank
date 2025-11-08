<?php
session_start();
require_once 'ClassAutoLoad.php';
if (!isset($_SESSION['hospital_id'])) { header('Location: hospital_login.php'); exit(); }
$hospital_id = $_SESSION['hospital_id'];

$hospital = null; $inventory=[]; $msg=''; $err='';
try { $stmt=$conn->prepare("SELECT hospital_name AS name FROM hospitals WHERE id=:id"); $stmt->execute([':id'=>$hospital_id]); $hospital=$stmt->fetch(PDO::FETCH_ASSOC);} catch(Exception $e){$hospital=null;}

// Handle inventory adjustments (+/-)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['adjust_inventory'])){
    $bt = trim((string)($_POST['blood_type'] ?? ''));
    $delta = (int)($_POST['delta'] ?? 0);
    if ($bt==='') { $err='Please provide a blood type.'; }
    else {
        try {
            $q=$conn->prepare("UPDATE blood_inventory SET units_available = GREATEST(units_available + :d, 0), updated_at = NOW() WHERE hospital_id=:hid AND blood_type=:bt");
            $q->execute([':d'=>$delta, ':hid'=>$hospital_id, ':bt'=>$bt]);
            if ($q->rowCount()===0){
                $units = max(0, $delta);
                $ins=$conn->prepare("INSERT INTO blood_inventory(hospital_id,blood_type,units_available,updated_at) VALUES(:hid,:bt,:u,NOW())");
                $ins->execute([':hid'=>$hospital_id, ':bt'=>$bt, ':u'=>$units]);
            }
            $msg='Inventory updated.';
        } catch(Exception $e){ $err='Failed to update inventory.'; }
    }
}

// Handle absolute set
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['set_inventory'])){
    $bt = trim((string)($_POST['blood_type'] ?? ''));
    $units = (int)($_POST['units'] ?? 0);
    if ($bt==='') { $err='Please provide a blood type.'; }
    else if ($units < 0) { $err='Units cannot be negative.'; }
    else {
        try {
            $q=$conn->prepare("UPDATE blood_inventory SET units_available = :u, updated_at = NOW() WHERE hospital_id=:hid AND blood_type=:bt");
            $q->execute([':u'=>$units, ':hid'=>$hospital_id, ':bt'=>$bt]);
            if ($q->rowCount()===0){
                $ins=$conn->prepare("INSERT INTO blood_inventory(hospital_id,blood_type,units_available,updated_at) VALUES(:hid,:bt,:u,NOW())");
                $ins->execute([':hid'=>$hospital_id, ':bt'=>$bt, ':u'=>$units]);
            }
            $msg='Inventory set successfully.';
        } catch(Exception $e){ $err='Failed to set inventory.'; }
    }
}

try { $inv=$conn->prepare("SELECT blood_type, units_available, updated_at FROM blood_inventory WHERE hospital_id=:hid ORDER BY blood_type"); $inv->execute([':hid'=>$hospital_id]); $inventory=$inv->fetchAll(PDO::FETCH_ASSOC);} catch(Exception $e){$inventory=[];}

$conf['page_title']='BloodBank | Hospital Inventory';
$Objlayout->header($conf);
?>
<?php $Objlayout->dashboardStart($conf,'inventory'); ?>

<div class="card">
  <div class="card-title"><?php echo $hospital && !empty($hospital['name']) ? htmlspecialchars($hospital['name']).' - ' : ''; ?>Current Inventory</div>
  <?php if($msg): ?><div class="card" style="border-color:#d1fae5;background:#ecfdf5;color:#065f46; margin-bottom:10px; "><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="card" style="border-color:#fee2e2;background:#fef2f2;color:#991b1b; margin-bottom:10px; "><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if(!empty($inventory)): ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Blood Type</th><th>Units Available</th><th>Last Updated</th><th>Set Units</th></tr></thead><tbody><?php foreach($inventory as $item): ?><tr><td><?php echo htmlspecialchars($item['blood_type']); ?></td><td><?php echo (int)$item['units_available']; ?></td><td><?php echo date('M j, Y H:i', strtotime($item['updated_at'])); ?></td><td><form method="post" style="display:flex;gap:6px;align-items:center;"><input type="hidden" name="set_inventory" value="1"/><input type="hidden" name="blood_type" value="<?php echo htmlspecialchars($item['blood_type']); ?>"/><input type="number" name="units" min="0" value="<?php echo (int)$item['units_available']; ?>" style="width:90px;padding:8px;border:1px solid #e5e7eb;border-radius:8px;"/><button class="btn-primary" type="submit">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div>
  <?php else: ?><p>No inventory data available. Blood units will be tracked here once donations are processed.</p><?php endif; ?>
</div>

<div class="card" style="margin-top:12px;">
  <div class="card-title">Adjust Inventory</div>
  <form method="post" class="actions-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
    <input type="hidden" name="adjust_inventory" value="1" />
    <div><label style="display:block;color:#6b7280;font-size:12px;">Blood Type</label><input name="blood_type" required placeholder="A+, O-, ..." style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
    <div><label style="display:block;color:#6b7280;font-size:12px;">Delta Units (+/-)</label><input name="delta" type="number" value="1" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"/></div>
    <div style="align-self:end;"><button class="btn-primary" type="submit" style="width:100%;padding:10px 14px;">Apply</button></div>
  </form>
</div>

<?php $Objlayout->dashboardEnd(); ?>
<?php $Objlayout->footer($conf); ?>

