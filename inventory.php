<?php
session_start();
require_once 'ClassAutoLoad.php';

// Security check — only allow logged-in hospitals
if (!isset($_SESSION['hospital_id'])) {
    header("Location: hospital_login.php");
    exit();
}

// Fetch hospital details for display
$hospital_id = $_SESSION['hospital_id'];
$hospital = null;
$inventory = [];

try {
    $stmt = $conn->prepare("SELECT hospital_name AS name FROM hospitals WHERE id = :id");
    $stmt->execute([':id' => $hospital_id]);
    $hospital = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $hospital = null; }

// Fetch inventory data
try {
    $inv = $conn->prepare("SELECT blood_type, units_available, updated_at FROM blood_inventory WHERE hospital_id = :hid ORDER BY blood_type");
    $inv->execute([':hid' => $hospital_id]);
    $inventory = $inv->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $inventory = []; }

// Page title
$conf['page_title'] = 'BloodBank | Hospital Inventory';

// Render layout header
$Objlayout->header($conf);
?>

<?php $Objlayout->dashboardStart($conf, 'inventory'); ?>

<div class="card">
    <div class="card-title"><?php echo $hospital && !empty($hospital['name']) ? htmlspecialchars($hospital['name']).' — ' : ''; ?>Current Inventory</div>
    <?php if (!empty($inventory)): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Blood Type</th>
                        <th>Units Available</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['blood_type']); ?></td>
                        <td><?php echo (int)$item['units_available']; ?></td>
                        <td><?php echo date('M j, Y H:i', strtotime($item['updated_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No inventory data available. Blood units will be tracked here once donations are processed.</p>
    <?php endif; ?>
</div>

<?php $Objlayout->dashboardEnd(); ?>

<?php $Objlayout->footer($conf); ?>

