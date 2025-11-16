<?php
//start session and require autoloader; redirect unauthenticated hospitals
session_start();
require_once 'ClassAutoLoad.php';

if (!isset($_SESSION['hospital_id'])) { header('Location: hospital_login.php'); exit(); }
$hospital_id = $_SESSION['hospital_id'];
?>