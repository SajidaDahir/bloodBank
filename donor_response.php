<?php
session_start();
require_once 'ClassAutoLoad.php';

if (!isset($_SESSION['donor_id'])) { header('Location: signin.php'); exit(); }
$donor_id = (int)$_SESSION['donor_id'];

// Fetch accepted responses by this donor
$responses = [];
try {
    $sql = "SELECT drr.created_at AS accepted_at,
                   br.request_type, br.blood_type, br.units_needed, br.urgency, br.deadline_at, br.status AS request_status, br.created_at AS requested_at,
                   h.hospital_name, h.city
            FROM donor_request_responses drr
            JOIN blood_requests br ON br.id = drr.request_id
            JOIN hospitals h ON h.id = br.hospital_id
            WHERE drr.donor_id = :did AND drr.status = 'Accepted'
            ORDER BY drr.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':did' => $donor_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $responses = [];
}