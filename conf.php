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
$conf['db_pass'] = '3';
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

// Harden blood_requests.blood_type similarly and introduce request_type
try { $conn->exec("UPDATE blood_requests SET blood_type = UPPER(blood_type)"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("UPDATE blood_requests SET blood_type = NULL WHERE blood_type IS NOT NULL AND UPPER(blood_type) NOT IN ('A+','A-','B+','B-','O+','O-','AB+','AB-')"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("ALTER TABLE blood_requests MODIFY COLUMN blood_type ENUM('A+','A-','B+','B-','O+','O-','AB+','AB-') NULL"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("ALTER TABLE blood_requests ADD COLUMN IF NOT EXISTS request_type ENUM('specific','general') NOT NULL DEFAULT 'specific' AFTER blood_type"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("UPDATE blood_requests SET request_type='specific' WHERE request_type IS NULL OR request_type=''"); } catch (Exception $e) { /* ignore */ }
try { $conn->exec("ALTER TABLE blood_requests ADD COLUMN IF NOT EXISTS deadline_at DATETIME NULL AFTER urgency"); } catch (Exception $e) { /* ignore */ }

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

// Blood type compatibility helpers (used across dashboards and request flows)
if (!function_exists('bloodbank_normalize_blood_type')) {
    function bloodbank_normalize_blood_type($bloodType)
    {
        $bt = strtoupper(trim((string)$bloodType));
        static $allowed = ['A+','A-','B+','B-','O+','O-','AB+','AB-'];
        return in_array($bt, $allowed, true) ? $bt : '';
    }
}

if (!function_exists('bloodbank_donor_map')) {
    function bloodbank_donor_map()
    {
        static $map = [
            'O-'  => ['O-','O+','A-','A+','B-','B+','AB-','AB+'],
            'O+'  => ['O+','A+','B+','AB+'],
            'B-'  => ['B-','B+','AB-','AB+'],
            'B+'  => ['B+','AB+'],
            'A-'  => ['A-','A+','AB-','AB+'],
            'A+'  => ['A+','AB+'],
            'AB-' => ['AB-','AB+'],
            'AB+' => ['AB+'],
        ];
        return $map;
    }
}

if (!function_exists('bloodbank_recipient_types_for_donor')) {
    function bloodbank_recipient_types_for_donor($donorType)
    {
        $donorType = bloodbank_normalize_blood_type($donorType);
        $map = bloodbank_donor_map();
        return $map[$donorType] ?? [];
    }
}

if (!function_exists('bloodbank_can_donor_fulfill_request')) {
    function bloodbank_can_donor_fulfill_request($donorType, $requestType, $requestBloodType)
    {
        $requestType = strtolower(trim((string)$requestType));
        if ($requestType === 'general') {
            return true; // anyone can donate
        }
        if ($requestType !== 'specific') {
            return false;
        }
        $donorType = bloodbank_normalize_blood_type($donorType);
        $requestBloodType = bloodbank_normalize_blood_type($requestBloodType);
        if ($donorType === '' || $requestBloodType === '') {
            return false;
        }
        $map = bloodbank_donor_map();
        return in_array($requestBloodType, $map[$donorType] ?? [], true);
    }
}

if (!function_exists('bloodbank_format_request_blood_label')) {
    function bloodbank_format_request_blood_label($requestType, $bloodType)
    {
        $requestType = strtolower(trim((string)$requestType));
        if ($requestType === 'general') {
            return 'Any blood type';
        }
        $bt = bloodbank_normalize_blood_type($bloodType);
        return $bt !== '' ? $bt : 'Unknown';
    }
}

// Site Language
$conf['site_lang'] = 'en';
