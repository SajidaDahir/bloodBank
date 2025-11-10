<?php
// Site Information
$conf['site_name']   = 'BloodBank';
$conf['site_url']    = 'http://localhost/BloodBank';
$conf['admin_email'] = 'admin@bloodbank.com';

// Email Configuration (optional)
$conf['smtp_host'] = 'smtp.gmail.com';
$conf['smtp_user'] = ''; // Your Gmail address
$conf['smtp_pass'] = ''; // Use Gmail App Password
$conf['smtp_port'] = 465;

// Database Configuration
$conf['db_type'] = 'pdo';
$conf['db_host'] = 'localhost';
//$conf['db_port'] = 3307;
$conf['db_user'] = 'root';
$conf['db_pass'] = '';
$conf['db_name'] = 'bloodbank';

// Database Connection
try {
    $dsn = "mysql:host={$conf['db_host']};dbname={$conf['db_name']};charset=utf8mb4";
    $conn = new PDO($dsn, $conf['db_user'], $conf['db_pass']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Ensure required columns exist (lightweight bootstrap migrations)
try { $conn->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS is_available TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) { /* ignore */ }

// Harden donors.blood_type as ENUM with cleanup to avoid migration failure
try { $conn->exec("UPDATE donors SET blood_type = UPPER(blood_type)"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("UPDATE donors SET blood_type = NULL WHERE blood_type IS NOT NULL AND UPPER(blood_type) NOT IN ('A+','A-','B+','B-','O+','O-','AB+','AB-')"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("ALTER TABLE donors MODIFY COLUMN blood_type ENUM('A+','A-','B+','B-','O+','O-','AB+','AB-') NULL"); } catch (Exception $e) { /* ignore */ }

// Harden blood_requests.blood_type similarly
try { $conn->exec("UPDATE blood_requests SET blood_type = UPPER(blood_type)"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("UPDATE blood_requests SET blood_type = NULL WHERE blood_type IS NOT NULL AND UPPER(blood_type) NOT IN ('A+','A-','B+','B-','O+','O-','AB+','AB-')"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("ALTER TABLE blood_requests MODIFY COLUMN blood_type ENUM('A+','A-','B+','B-','O+','O-','AB+','AB-') NOT NULL"); } catch (Exception $e) { /* ignore */ }

// Notifications table
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipient_type ENUM('donor','hospital') NOT NULL,
        recipient_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        body TEXT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recipient (recipient_type, recipient_id, is_read, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ignore */ }

// Appointments table
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        donor_id INT NOT NULL,
        hospital_id INT NOT NULL,
        request_id INT NOT NULL,
        scheduled_at DATETIME NOT NULL,
        status ENUM('Pending','Confirmed','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE,
        FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
        FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE,
        INDEX idx_schedule (hospital_id, scheduled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ignore */ }

// Site Language
$conf['site_lang'] = 'en';
