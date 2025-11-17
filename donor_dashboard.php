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