<?php
// Site Information
$conf['site_name']   = 'BloodBank';
$conf['site_url']    = 'http://localhost/BloodBank';
$conf['admin_email'] = 'admin@bloodbank.com';

// Email Configuration (optional)
$conf['smtp_host'] = 'smtp.gmail.com';
$conf['smtp_user'] = 'User email';
$conf['smtp_pass'] = ''; // Use Gmail App Password
$conf['smtp_port'] = 465;

// Database Configuration
$conf['db_type'] = 'pdo';
$conf['db_host'] = 'localhost';
//$conf['db_port'] = 3307;
$conf['db_user'] = 'root';
$conf['db_pass'] = 'your_password';
$conf['db_name'] = 'bloodbank';

// Database Connection
try {
    $dsn = "mysql:host={$conf['db_host']};dbname={$conf['db_name']};charset=utf8mb4";
    $conn = new PDO($dsn, $conf['db_user'], $conf['db_pass']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Site Language
$conf['site_lang'] = 'en';
?>





