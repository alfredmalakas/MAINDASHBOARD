<?php
/**
 * Database Configuration
 * HRIS Capstone System
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'hris_db');
define('DB_USER', 'root');
define('DB_PASS', '');

$driverOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        $driverOptions
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`");

    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        $driverOptions
    );

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'employees'");
    if (!$tableCheck->fetch()) {
        $schemaPath = __DIR__ . '/../IMPORT_THIS_SQL.sql';
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Database schema file not found at ' . $schemaPath);
        }

        $schemaSql = file_get_contents($schemaPath);
        // Remove SQL comments before splitting so comments do not swallow the
        // CREATE TABLE / INSERT statements that follow them.
        $schemaSql = preg_replace('/\/\*.*?\*\//s', '', $schemaSql);
        $schemaSql = preg_replace('/^\s*--.*$/m', '', $schemaSql);
        $statements = preg_split('/;\s*/', $schemaSql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    // Role/feature migrations for existing installations.
    try { $pdo->exec("ALTER TABLE users MODIFY role ENUM('HR Administrator','Super Admin','Employee') NOT NULL DEFAULT 'Employee'"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_archive (
      archive_id BIGINT AUTO_INCREMENT PRIMARY KEY,
      original_employee_id VARCHAR(20) NOT NULL, first_name VARCHAR(50) NOT NULL, middle_name VARCHAR(50) NULL, last_name VARCHAR(50) NOT NULL,
      gender VARCHAR(20) NULL, date_of_birth DATE NULL, address VARCHAR(255) NULL, contact_number VARCHAR(20) NULL, email VARCHAR(100) NULL,
      department_id INT NULL, position_id INT NULL, employment_status VARCHAR(30) NULL, date_hired DATE NULL, emergency_contact_name VARCHAR(100) NULL,
      emergency_contact_number VARCHAR(20) NULL, photo VARCHAR(255) NULL, basic_salary DECIMAL(10,2) DEFAULT 0.00,
      login_username VARCHAR(50) NULL, login_role VARCHAR(40) NULL, login_status VARCHAR(20) NULL, archived_by INT NULL, archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      reason VARCHAR(255) NULL, INDEX idx_archive_emp(original_employee_id), INDEX idx_archive_date(archived_at)
    ) ENGINE=InnoDB");
    // Ensure the built-in Super Admin account exists for both clean and existing installs.
    try {
        $superHash='$2y$12$kK6gYZ2D5q/.ddwdct1AK.VgqGlcbgxP2BqMD32hipKmUPGLSjcvi';
        $superStmt=$pdo->prepare("INSERT IGNORE INTO users (employee_id,username,password,role,status) VALUES (NULL,'superadmin',?,'Super Admin','Active')");
        $superStmt->execute([$superHash]);
    } catch (Throwable $e) {}
    // Position management and employee agreement contract migrations.
    try { $pdo->exec("ALTER TABLE positions ADD COLUMN description VARCHAR(500) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE positions ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_contracts (
      contract_id BIGINT AUTO_INCREMENT PRIMARY KEY, employee_id VARCHAR(20) NOT NULL,
      contract_type VARCHAR(80) NOT NULL DEFAULT 'Employment Agreement', start_date DATE NOT NULL, end_date DATE NULL,
      salary DECIMAL(10,2) DEFAULT 0.00, terms TEXT NOT NULL,
      status ENUM('Draft','Pending Agreement','Agreed','Rejected','Expired') NOT NULL DEFAULT 'Draft',
      created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, agreed_by_employee_at DATETIME NULL,
      employee_agreement_name VARCHAR(150) NULL, INDEX idx_contract_employee(employee_id), INDEX idx_contract_status(status),
      FOREIGN KEY(employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Ensure newer compliance/operations tables exist even when an older
    // hris_db database is already installed. This keeps upgrades idempotent.
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
      audit_id BIGINT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NULL, employee_id VARCHAR(20) NULL,
      action VARCHAR(50) NOT NULL, entity VARCHAR(80) NOT NULL,
      entity_id VARCHAR(80) NULL, details TEXT NULL,
      ip_address VARCHAR(45) NULL, user_agent VARCHAR(500) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_audit_created (created_at), INDEX idx_audit_user (user_id), INDEX idx_audit_entity (entity, entity_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS privacy_consents (
      consent_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
      policy_version VARCHAR(30) NOT NULL DEFAULT '1.0', consented_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      withdrawn_at TIMESTAMP NULL, ip_address VARCHAR(45) NULL, INDEX idx_consent_user (user_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_jobs (
      job_id BIGINT AUTO_INCREMENT PRIMARY KEY, job_name VARCHAR(100) NOT NULL,
      status ENUM('Queued','Running','Completed','Failed') NOT NULL DEFAULT 'Queued',
      started_at DATETIME NULL, finished_at DATETIME NULL, details TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_backups (
      backup_id BIGINT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL,
      file_size BIGINT DEFAULT 0, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    // Facial verification enrollment for employee attendance. Store only the face descriptor, not a camera image.
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN face_descriptor LONGTEXT NULL, ADD COLUMN face_enrolled_at DATETIME NULL"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS privacy_requests (
      request_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
      request_type ENUM('Delete','Access','Correction') NOT NULL,
      reason VARCHAR(500) NULL, status ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, actioned_at TIMESTAMP NULL, actioned_by INT NULL,
      INDEX idx_privacy_status(status)
    ) ENGINE=InnoDB");
    try { $pdo->exec("ALTER TABLE otp_requests ADD COLUMN recipient_email VARCHAR(150) NULL"); } catch (Throwable $e) {}
    // Leave medical certificates and integration notifications.
    try { $pdo->exec("ALTER TABLE leave_requests ADD COLUMN attachment_path VARCHAR(255) NULL, ADD COLUMN attachment_original_name VARCHAR(255) NULL"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_files (
      integration_file_id BIGINT AUTO_INCREMENT PRIMARY KEY,
      source_system VARCHAR(120) NOT NULL,
      original_filename VARCHAR(255) NOT NULL,
      stored_path VARCHAR(255) NOT NULL,
      file_size BIGINT DEFAULT 0,
      received_by_user_id INT NULL,
      received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_integration_received(received_at),
      INDEX idx_integration_source(source_system)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
      setting_key VARCHAR(100) PRIMARY KEY,
      setting_value TEXT NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $superGmailSetting = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('superadmin_gmail', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $superGmailSetting->execute(['cajotealfredo60@gmail.com']);

    // Demo attendance/payroll history seed for capstone presentation.
    // Runs once per database and creates sample records across different months.
    try {
        $seedCheck = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='demo_history_seed_v1' LIMIT 1");
        $seedCheck->execute();
        $seeded = $seedCheck->fetchColumn();
        if (!$seeded) {
            $demoEmployees = $pdo->query("SELECT employee_id, basic_salary FROM employees WHERE employee_id IN ('EMP-2026-0001','EMP-2026-0002','EMP-2026-0003','EMP-2026-0004','EMP-2026-0005') ORDER BY employee_id")->fetchAll();
            $attInsert = $pdo->prepare("INSERT IGNORE INTO attendance (employee_id,attendance_date,time_in,time_out,break_start,break_end,status,late_minutes,overtime_minutes,remarks) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $months = [
                ['2026-06-01','2026-06-30',15],
                ['2026-07-01','2026-07-31',20],
                ['2026-08-01','2026-08-31',22]
            ];
            foreach ($demoEmployees as $ei => $emp) {
                foreach ($months as $mi => $cfg) {
                    $start = new DateTime($cfg[0]); $end = new DateTime($cfg[1]); $target = (int)$cfg[2]; $count=0;
                    for ($dt=clone $start; $dt <= $end && $count < $target; $dt->modify('+1 day')) {
                        $dow=(int)$dt->format('N'); if ($dow >= 6) continue;
                        $day=(int)$dt->format('j');
                        $status='Present'; $late=0; $ot=0; $in='08:00:00'; $out='17:00:00'; $remark='Demo attendance record';
                        if (($day + $ei + $mi) % 17 === 0) { $status='Absent'; $in=null; $out=null; $remark='Demo absent record'; }
                        elseif (($day + $ei + $mi) % 11 === 0) { $status='Late'; $late=18 + (($day+$ei)%18); $in='08:'.str_pad((string)$late,2,'0',STR_PAD_LEFT).':00'; $remark='Demo late record'; }
                        elseif (($day + $ei + $mi) % 13 === 0) { $status='Overtime'; $ot=60 + (($day+$ei)%61); $out='18:00:00'; $remark='Demo overtime record'; }
                        $attInsert->execute([$emp['employee_id'],$dt->format('Y-m-d'),$in,$out,$in&&$out?'12:00:00':null,$in&&$out?'13:00:00':null,$status,$late,$ot,$remark]);
                        $count++;
                    }
                }
            }

            // Six historical semi-monthly payroll records per demo employee (June–August 2026).
            $payInsert=$pdo->prepare("INSERT INTO payroll (employee_id,pay_period_start,pay_period_end,basic_salary,overtime_pay,allowances,incentives,bonuses,gross_pay,tax_deduction,sss_deduction,philhealth_deduction,pagibig_deduction,late_deduction,absence_deduction,total_deductions,net_pay,generated_by,date_generated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $periods=[
                ['2026-06-01','2026-06-15'],['2026-06-16','2026-06-30'],
                ['2026-07-01','2026-07-15'],['2026-07-16','2026-07-31'],
                ['2026-08-01','2026-08-15'],['2026-08-16','2026-08-31']
            ];
            foreach($demoEmployees as $ei=>$emp){
                $basic=(float)$emp['basic_salary'];
                foreach($periods as $pi=>$period){
                    $ot=round((($ei+$pi)%3)*350,2); $bonus=($pi%2===1)?round($basic*0.05,2):0;
                    $gross=round($basic+$ot+$bonus,2);
                    $sss=round(min($basic*0.045,1350),2); $ph=round($basic*0.025/2,2); $pii=200.00;
                    $taxable=max(0,$gross-$sss-$ph-$pii); $tax=round(max(0,($taxable-20833.33)*0.15),2);
                    $late=round((($ei+$pi)%4)*75,2); $abs=round((($ei+$pi)%5===0)?$basic/22:0,2);
                    $ded=round($sss+$ph+$pii+$tax+$late+$abs,2); $net=round($gross-$ded,2);
                    $generatedAt=$period[1].' 18:00:00';
                    $payInsert->execute([$emp['employee_id'],$period[0],$period[1],$basic,$ot,0,0,$bonus,$gross,$tax,$sss,$ph,$pii,$late,$abs,$ded,$net,null,$generatedAt]);
                }
            }
            $set=$pdo->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES('demo_history_seed_v1','1') ON DUPLICATE KEY UPDATE setting_value='1'");
            $set->execute();
        }
    } catch (Throwable $e) {
        // Demo seed must never prevent the HRIS from loading.
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
      notification_id BIGINT AUTO_INCREMENT PRIMARY KEY, recipient_user_id INT NOT NULL,
      title VARCHAR(180) NOT NULL, message TEXT NOT NULL, notification_type VARCHAR(50) DEFAULT 'info',
      related_entity VARCHAR(80) NULL, related_id BIGINT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL,
      INDEX idx_notifications_recipient (recipient_user_id, is_read, created_at)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS data_submissions (
      submission_id BIGINT AUTO_INCREMENT PRIMARY KEY, sender_user_id INT NOT NULL,
      source_branch VARCHAR(120) NOT NULL, destination_branch VARCHAR(120) NULL,
      title VARCHAR(180) NOT NULL, message TEXT NULL, file_path VARCHAR(255) NULL,
      original_filename VARCHAR(255) NULL, status ENUM('New','Received','In Review','Accepted','Rejected') NOT NULL DEFAULT 'New',
      feedback TEXT NULL, feedback_by INT NULL, feedback_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_submission_status(status), INDEX idx_submission_sender(sender_user_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS otp_requests (
      request_id BIGINT AUTO_INCREMENT PRIMARY KEY, requester_user_id INT NOT NULL,
      recipient_email VARCHAR(150) NULL,
      action_name VARCHAR(180) NOT NULL, reason VARCHAR(500) NULL, status ENUM('Pending','Approved','Rejected','Verified','Expired') NOT NULL DEFAULT 'Pending',
      otp_hash CHAR(64) NULL, otp_expires_at DATETIME NULL, approved_by INT NULL, approved_at DATETIME NULL, verified_at DATETIME NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_otp_status(status), INDEX idx_otp_requester(requester_user_id)
    ) ENGINE=InnoDB");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
} catch (RuntimeException $e) {
    die($e->getMessage());
}
