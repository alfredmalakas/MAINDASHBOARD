CREATE DATABASE IF NOT EXISTS hris_db;
USE hris_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS data_submissions;
DROP TABLE IF EXISTS otp_requests;
DROP TABLE IF EXISTS employee_archive;
DROP TABLE IF EXISTS performance;
DROP TABLE IF EXISTS payroll;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS leave_requests;
DROP TABLE IF EXISTS leave_balances;
DROP TABLE IF EXISTS leave_settings;
DROP TABLE IF EXISTS api_tokens;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS positions;
DROP TABLE IF EXISTS departments;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE positions (
    position_id INT AUTO_INCREMENT PRIMARY KEY,
    position_title VARCHAR(100) NOT NULL,
    department_id INT NULL,
    base_salary DECIMAL(10,2) DEFAULT 0.00,
    description VARCHAR(500) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE employees (
    employee_id VARCHAR(20) PRIMARY KEY,          -- e.g. EMP-2026-0001
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    gender ENUM('Male','Female','Other') NOT NULL,
    date_of_birth DATE NOT NULL,
    address VARCHAR(255) NULL,
    contact_number VARCHAR(20) NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    department_id INT NULL,
    position_id INT NULL,
    employment_status ENUM('Regular','Probationary','Contractual','Part-Time','Resigned','Terminated') DEFAULT 'Probationary',
    date_hired DATE NOT NULL,
    emergency_contact_name VARCHAR(100) NULL,
    emergency_contact_number VARCHAR(20) NULL,
    photo VARCHAR(255) DEFAULT 'assets/uploads/default.png',
    basic_salary DECIMAL(10,2) DEFAULT 0.00,
    face_descriptor LONGTEXT NULL,
    face_enrolled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    FOREIGN KEY (position_id) REFERENCES positions(position_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE employee_contracts (
    contract_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    contract_type VARCHAR(80) NOT NULL DEFAULT 'Employment Agreement',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    salary DECIMAL(10,2) DEFAULT 0.00,
    terms TEXT NOT NULL,
    status ENUM('Draft','Pending Agreement','Agreed','Rejected','Expired') NOT NULL DEFAULT 'Draft',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    agreed_by_employee_at DATETIME NULL,
    employee_agreement_name VARCHAR(150) NULL,
    INDEX idx_contract_employee(employee_id),
    INDEX idx_contract_status(status),
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,               -- store bcrypt hash
    role ENUM('HR Administrator','Super Admin','Employee') NOT NULL DEFAULT 'Employee',
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('superadmin_gmail', 'cajotealfredo60@gmail.com')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

CREATE TABLE api_tokens (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL DEFAULT 'API Token',
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    attendance_date DATE NOT NULL,
    time_in TIME NULL,
    time_out TIME NULL,
    break_start TIME NULL,
    break_end TIME NULL,
    status ENUM('Present','Late','Absent','Leave','Half-Day','Overtime') DEFAULT 'Present',
    late_minutes INT DEFAULT 0,
    overtime_minutes INT DEFAULT 0,
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    UNIQUE KEY unique_daily_attendance (employee_id, attendance_date)
) ENGINE=InnoDB;

CREATE TABLE leave_requests (
    leave_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    leave_type ENUM('Vacation Leave','Sick Leave','Emergency Leave','Maternity Leave','Paternity Leave','Bereavement Leave') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT NOT NULL,
    reason VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    approved_by INT NULL,
    attachment_path VARCHAR(255) NULL,
    attachment_original_name VARCHAR(255) NULL,
    date_filed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_actioned TIMESTAMP NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE leave_balances (
    balance_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    leave_type ENUM('Vacation Leave','Sick Leave','Emergency Leave','Maternity Leave','Paternity Leave','Bereavement Leave') NOT NULL,
    year INT NOT NULL,
    allocated_days INT DEFAULT 0,
    used_days INT DEFAULT 0,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    UNIQUE KEY unique_balance (employee_id, leave_type, year)
) ENGINE=InnoDB;

CREATE TABLE leave_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    leave_type ENUM('Vacation Leave','Sick Leave','Emergency Leave','Maternity Leave','Paternity Leave','Bereavement Leave') NOT NULL,
    default_days INT DEFAULT 0,
    requires_attachment TINYINT(1) DEFAULT 0,
    allow_employee_apply TINYINT(1) DEFAULT 1,
    description VARCHAR(255) NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY unique_leave_setting (leave_type)
) ENGINE=InnoDB;

CREATE TABLE payroll (
    payroll_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    pay_period_start DATE NOT NULL,
    pay_period_end DATE NOT NULL,
    basic_salary DECIMAL(10,2) DEFAULT 0.00,
    overtime_pay DECIMAL(10,2) DEFAULT 0.00,
    allowances DECIMAL(10,2) DEFAULT 0.00,
    incentives DECIMAL(10,2) DEFAULT 0.00,
    bonuses DECIMAL(10,2) DEFAULT 0.00,
    gross_pay DECIMAL(10,2) DEFAULT 0.00,
    tax_deduction DECIMAL(10,2) DEFAULT 0.00,
    sss_deduction DECIMAL(10,2) DEFAULT 0.00,
    philhealth_deduction DECIMAL(10,2) DEFAULT 0.00,
    pagibig_deduction DECIMAL(10,2) DEFAULT 0.00,
    late_deduction DECIMAL(10,2) DEFAULT 0.00,
    absence_deduction DECIMAL(10,2) DEFAULT 0.00,
    total_deductions DECIMAL(10,2) DEFAULT 0.00,
    net_pay DECIMAL(10,2) DEFAULT 0.00,
    generated_by INT NULL,
    date_generated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE performance (
    performance_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    evaluator_id INT NULL,                         -- user_id of supervisor/HR
    evaluation_period VARCHAR(50) NOT NULL,        -- e.g. "Q1 2026"
    kpi_score DECIMAL(5,2) DEFAULT 0.00,
    rating ENUM('Excellent','Very Good','Good','Fair','Needs Improvement') NOT NULL,
    strengths TEXT NULL,
    areas_for_improvement TEXT NULL,
    supervisor_comments TEXT NULL,
    employee_feedback TEXT NULL,
    evaluation_date DATE NOT NULL,
    rubric_scores LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;


CREATE TABLE notifications (
  notification_id BIGINT AUTO_INCREMENT PRIMARY KEY, recipient_user_id INT NOT NULL,
  title VARCHAR(180) NOT NULL, message TEXT NOT NULL, notification_type VARCHAR(50) DEFAULT 'info',
  related_entity VARCHAR(80) NULL, related_id BIGINT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL,
  INDEX idx_notifications_recipient (recipient_user_id, is_read, created_at)
) ENGINE=InnoDB;

CREATE TABLE data_submissions (
  submission_id BIGINT AUTO_INCREMENT PRIMARY KEY, sender_user_id INT NOT NULL,
  source_branch VARCHAR(120) NOT NULL, destination_branch VARCHAR(120) NULL,
  title VARCHAR(180) NOT NULL, message TEXT NULL, file_path VARCHAR(255) NULL,
  original_filename VARCHAR(255) NULL, status ENUM('New','Received','In Review','Accepted','Rejected') NOT NULL DEFAULT 'New',
  feedback TEXT NULL, feedback_by INT NULL, feedback_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_submission_status(status), INDEX idx_submission_sender(sender_user_id)
) ENGINE=InnoDB;

CREATE TABLE otp_requests (
  request_id BIGINT AUTO_INCREMENT PRIMARY KEY, requester_user_id INT NOT NULL,
  recipient_email VARCHAR(150) NULL,
  action_name VARCHAR(180) NOT NULL, reason VARCHAR(500) NULL, status ENUM('Pending','Approved','Rejected','Verified','Expired') NOT NULL DEFAULT 'Pending',
  otp_hash CHAR(64) NULL, otp_expires_at DATETIME NULL, approved_by INT NULL, approved_at DATETIME NULL, verified_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_otp_status(status), INDEX idx_otp_requester(requester_user_id)
) ENGINE=InnoDB;


INSERT INTO departments (department_name, description) VALUES
('Human Resources', 'Handles HR operations'),
('Information Technology', 'Handles systems and technical support'),
('Finance', 'Handles accounting and finance'),
('Operations', 'Handles daily business operations');

INSERT INTO positions (position_title, department_id, base_salary, description, status) VALUES
('HR Administrator', 1, 25000.00, 'Manages HR operations, employee records, policies, and HR processes.', 'Active'),
('HR Staff', 1, 18000.00, 'Supports recruitment, employee records, attendance, leave, and HR administration.', 'Active'),
('IT Manager', 2, 35000.00, 'Leads IT operations, systems, infrastructure, and technical support.', 'Active'),
('Web Developer', 2, 22000.00, 'Develops, maintains, tests, and improves web applications and integrations.', 'Active'),
('Accountant', 3, 24000.00, 'Handles accounting records, payroll support, financial reports, and reconciliation.', 'Active'),
('Operations Staff', 4, 16000.00, 'Supports daily operations, coordination, documentation, and service delivery.', 'Active');


INSERT INTO users (employee_id, username, password, role) VALUES (NULL,'superadmin','$2y$12$kK6gYZ2D5q/.ddwdct1AK.VgqGlcbgxP2BqMD32hipKmUPGLSjcvi','Super Admin');
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0001', 'Juan', 'Dela Cruz', 'Male', '1990-05-14', 'Caloocan City, Metro Manila', '09171234567', 'juan.delacruz.hr@hrisdemo.local', 1, 1, 'Regular', '2024-01-15', 25000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0001','admin','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','HR Administrator');
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0002', 'Maria', 'Santos', 'Female', '1995-08-22', 'Quezon City, Metro Manila', '09181234567', 'maria.santos.emp@hrisdemo.local', 2, 4, 'Regular', '2024-03-01', 22000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0002','msantos','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0002','Vacation Leave',2026,15,0),('EMP-2026-0002','Sick Leave',2026,15,0);
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0003', 'Carlo', 'Reyes', 'Male', '1993-02-10', 'Malabon City, Metro Manila', '09192345678', 'carlo.reyes.emp@hrisdemo.local', 2, 4, 'Regular', '2024-05-06', 22000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0003','creyes','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0003','Vacation Leave',2026,15,0),('EMP-2026-0003','Sick Leave',2026,15,0);
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0004', 'Angela', 'Garcia', 'Female', '1996-11-18', 'Manila, Metro Manila', '09203456789', 'angela.garcia.emp@hrisdemo.local', 1, 2, 'Regular', '2025-01-06', 18000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0004','agarcia','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0004','Vacation Leave',2026,15,0),('EMP-2026-0004','Sick Leave',2026,15,0);
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0005', 'Mark', 'Villanueva', 'Male', '1991-07-25', 'Valenzuela City, Metro Manila', '09214567890', 'mark.villanueva.emp@hrisdemo.local', 3, 5, 'Regular', '2023-09-11', 24000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0005','mvillanueva','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0005','Vacation Leave',2026,15,0),('EMP-2026-0005','Sick Leave',2026,15,0);
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0006', 'Sofia', 'Mendoza', 'Female', '1998-04-12', 'Meycauayan, Bulacan', '09225678901', 'sofia.mendoza.emp@hrisdemo.local', 4, 6, 'Probationary', '2026-01-15', 16000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0006','smendoza','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0006','Vacation Leave',2026,15,0),('EMP-2026-0006','Sick Leave',2026,15,0);
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0007', 'Daniel', 'Cruz', 'Male', '1994-09-03', 'Marikina City, Metro Manila', '09236789012', 'daniel.cruz.emp@hrisdemo.local', 2, 4, 'Regular', '2024-08-19', 22000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0007','dcruz','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0007','Vacation Leave',2026,15,0),('EMP-2026-0007','Sick Leave',2026,15,0);
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0008', 'Patricia', 'Flores', 'Female', '1997-06-30', 'Pasig City, Metro Manila', '09247890123', 'patricia.flores.emp@hrisdemo.local', 3, 5, 'Regular', '2025-02-03', 24000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0008','pflores','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0008','Vacation Leave',2026,15,0),('EMP-2026-0008','Sick Leave',2026,15,0);
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0009', 'Kevin', 'Navarro', 'Male', '1992-12-08', 'Caloocan City, Metro Manila', '09258901234', 'kevin.navarro.emp@hrisdemo.local', 4, 6, 'Regular', '2024-10-14', 16000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0009','knavarro','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0009','Vacation Leave',2026,15,0),('EMP-2026-0009','Sick Leave',2026,15,0);
INSERT INTO employees (employee_id, first_name, last_name, gender, date_of_birth, address, contact_number, email, department_id, position_id, employment_status, date_hired, basic_salary) VALUES ('EMP-2026-0010', 'Nicole', 'Ramos', 'Female', '1999-03-21', 'Bulacan, Philippines', '09269012345', 'nicole.ramos.emp@hrisdemo.local', 1, 2, 'Probationary', '2026-02-02', 18000.00);
INSERT INTO users (employee_id, username, password, role) VALUES ('EMP-2026-0010','nramos','$2y$10$o5xvsXs6uaNm.kz4XrKV6uKG34R8yxxj7DzHi4umrchjgB4EveVZe','Employee');
INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES ('EMP-2026-0010','Vacation Leave',2026,15,0),('EMP-2026-0010','Sick Leave',2026,15,0);

INSERT INTO leave_settings (leave_type, default_days, requires_attachment, allow_employee_apply, description) VALUES
('Vacation Leave', 15, 0, 1, 'Annual vacation leave allocation.'),
('Sick Leave', 15, 0, 1, 'Leave for illness or medical recovery.'),
('Emergency Leave', 5, 0, 1, 'Short-term leave for urgent personal matters.'),
('Maternity Leave', 105, 1, 1, 'Maternity leave subject to company policy and applicable law.'),
('Paternity Leave', 7, 1, 1, 'Paternity leave subject to company policy and applicable law.'),
('Bereavement Leave', 5, 1, 1, 'Leave following the loss of an immediate family member.');

USE hris_db;

INSERT IGNORE INTO attendance
(employee_id, attendance_date, time_in, time_out, status, late_minutes, overtime_minutes, remarks)
VALUES
('EMP-2026-0001', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '07:54:00','17:18:00','Overtime',0,18,'Regular shift'),
('EMP-2026-0001', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:06:00','17:00:00','Late',6,0,'Traffic delay'),
('EMP-2026-0001', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:58:00','17:00:00','Present',0,0,''),
('EMP-2026-0002', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:02:00','17:00:00','Late',2,0,''),
('EMP-2026-0002', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '07:55:00','17:22:00','Overtime',0,22,'Project work'),
('EMP-2026-0002', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:59:00','17:00:00','Present',0,0,''),
('EMP-2026-0003', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '07:57:00','17:00:00','Present',0,0,''),
('EMP-2026-0003', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:12:00','17:00:00','Late',12,0,'Traffic delay'),
('EMP-2026-0003', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:52:00','17:10:00','Overtime',0,10,''),
('EMP-2026-0004', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '07:56:00','17:00:00','Present',0,0,''),
('EMP-2026-0004', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:04:00','17:00:00','Late',4,0,''),
('EMP-2026-0004', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:58:00','17:16:00','Overtime',0,16,''),
('EMP-2026-0005', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '07:50:00','17:00:00','Present',0,0,''),
('EMP-2026-0005', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:08:00','17:00:00','Late',8,0,''),
('EMP-2026-0005', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:58:00','17:25:00','Overtime',0,25,''),
('EMP-2026-0006', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:01:00','17:00:00','Late',1,0,''),
('EMP-2026-0006', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '07:57:00','17:00:00','Present',0,0,''),
('EMP-2026-0006', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '08:05:00','17:08:00','Late',5,8,''),
('EMP-2026-0007', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '07:53:00','17:00:00','Present',0,0,''),
('EMP-2026-0007', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:03:00','17:15:00','Overtime',3,15,''),
('EMP-2026-0007', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:59:00','17:00:00','Present',0,0,''),
('EMP-2026-0008', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '07:58:00','17:00:00','Present',0,0,''),
('EMP-2026-0008', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:10:00','17:00:00','Late',10,0,''),
('EMP-2026-0008', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:55:00','17:20:00','Overtime',0,20,''),
('EMP-2026-0009', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '07:52:00','17:00:00','Present',0,0,''),
('EMP-2026-0009', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:07:00','17:00:00','Late',7,0,''),
('EMP-2026-0009', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:57:00','17:12:00','Overtime',0,12,''),
('EMP-2026-0010', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:05:00','17:00:00','Late',5,0,''),
('EMP-2026-0010', DATE_SUB(CURDATE(), INTERVAL 2 DAY), '07:58:00','17:00:00','Present',0,0,''),
('EMP-2026-0010', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '07:54:00','17:30:00','Overtime',0,30,'Month-end support');

INSERT INTO leave_requests
(employee_id, leave_type, start_date, end_date, total_days, reason, status)
VALUES
('EMP-2026-0002','Vacation Leave',DATE_ADD(CURDATE(),INTERVAL 7 DAY),DATE_ADD(CURDATE(),INTERVAL 9 DAY),3,'Family vacation','Pending'),
('EMP-2026-0003','Sick Leave',DATE_SUB(CURDATE(),INTERVAL 12 DAY),DATE_SUB(CURDATE(),INTERVAL 11 DAY),2,'Medical recovery','Approved'),
('EMP-2026-0004','Emergency Leave',DATE_SUB(CURDATE(),INTERVAL 20 DAY),DATE_SUB(CURDATE(),INTERVAL 20 DAY),1,'Urgent family matter','Approved'),
('EMP-2026-0005','Vacation Leave',DATE_ADD(CURDATE(),INTERVAL 14 DAY),DATE_ADD(CURDATE(),INTERVAL 16 DAY),3,'Personal travel','Pending'),
('EMP-2026-0006','Sick Leave',DATE_SUB(CURDATE(),INTERVAL 6 DAY),DATE_SUB(CURDATE(),INTERVAL 5 DAY),2,'Flu recovery','Approved'),
('EMP-2026-0008','Vacation Leave',DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_SUB(CURDATE(),INTERVAL 29 DAY),2,'Personal leave','Rejected');

UPDATE leave_balances SET used_days = 2
WHERE employee_id='EMP-2026-0003' AND leave_type='Sick Leave' AND year=YEAR(CURDATE());
UPDATE leave_balances SET used_days = 2
WHERE employee_id='EMP-2026-0006' AND leave_type='Sick Leave' AND year=YEAR(CURDATE());

INSERT INTO payroll
(employee_id,pay_period_start,pay_period_end,basic_salary,overtime_pay,allowances,incentives,bonuses,gross_pay,
 tax_deduction,sss_deduction,philhealth_deduction,pagibig_deduction,late_deduction,absence_deduction,total_deductions,net_pay,generated_by)
VALUES
('EMP-2026-0001',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),12500,850,1000,500,0,14850,950,600,250,100,50,0,1950,12900,1),
('EMP-2026-0002',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),11000,650,800,300,0,12750,650,550,225,100,20,0,1545,11205,1),
('EMP-2026-0003',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),11000,720,800,350,0,12870,670,550,225,100,40,0,1585,11285,1),
('EMP-2026-0004',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),9000,520,700,250,0,10470,430,450,190,100,20,0,1190,9280,1),
('EMP-2026-0005',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),12000,900,900,400,500,14700,920,600,250,100,50,0,1920,12780,1),
('EMP-2026-0006',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),8000,300,500,0,0,8800,250,400,175,100,10,0,935,7865,1),
('EMP-2026-0007',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),11000,780,800,300,0,12880,670,550,225,100,30,0,1575,11305,1),
('EMP-2026-0008',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),12000,820,900,300,0,14020,820,600,250,100,20,0,1790,12230,1),
('EMP-2026-0009',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),8000,420,600,200,0,9220,300,400,175,100,35,0,1010,8210,1),
('EMP-2026-0010',DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),9000,650,700,250,0,10600,450,450,190,100,25,0,1215,9385,1);

INSERT INTO performance
(employee_id,evaluator_id,evaluation_period,kpi_score,rating,strengths,areas_for_improvement,supervisor_comments,employee_feedback,evaluation_date)
VALUES
('EMP-2026-0001',1,'Q3 2026',94.50,'Excellent','Leadership and policy knowledge','Delegate more routine tasks','Consistently exceeds HR targets.','Will continue improving delegation.',CURDATE()),
('EMP-2026-0002',1,'Q3 2026',91.00,'Excellent','Technical delivery and teamwork','Improve documentation','Strong contributor to IT projects.','Agreed to improve documentation.',CURDATE()),
('EMP-2026-0003',1,'Q3 2026',86.50,'Very Good','Problem solving','Time estimation','Reliable project execution.','Will use improved task estimates.',CURDATE()),
('EMP-2026-0004',1,'Q3 2026',89.00,'Very Good','Employee support and communication','Advanced reporting','Dependable HR staff member.','Interested in advanced reporting training.',CURDATE()),
('EMP-2026-0005',1,'Q3 2026',88.00,'Very Good','Accuracy and financial analysis','Presentation skills','Strong financial reporting performance.','Will work on presentation confidence.',CURDATE()),
('EMP-2026-0006',1,'Q3 2026',78.00,'Good','Adaptability and learning','Process consistency','Shows good progress during probation.','Requests more process training.',CURDATE()),
('EMP-2026-0007',1,'Q3 2026',84.00,'Very Good','Coding quality and collaboration','Testing coverage','Good delivery with reliable teamwork.','Will expand automated testing.',CURDATE()),
('EMP-2026-0008',1,'Q3 2026',90.00,'Excellent','Financial controls and reporting','Automation skills','Excellent accuracy and ownership.','Interested in automation training.',CURDATE()),
('EMP-2026-0009',1,'Q3 2026',81.50,'Very Good','Operations discipline','Cross-team communication','Meets operational targets consistently.','Will improve cross-team updates.',CURDATE()),
('EMP-2026-0010',1,'Q3 2026',76.50,'Good','Customer service and initiative','Prioritization','Good potential and positive attitude.','Will focus on prioritization.',CURDATE());

-- Employee archive (soft-delete destination)
CREATE TABLE IF NOT EXISTS employee_archive (
  archive_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  original_employee_id VARCHAR(20) NOT NULL, first_name VARCHAR(50) NOT NULL, middle_name VARCHAR(50) NULL, last_name VARCHAR(50) NOT NULL,
  gender VARCHAR(20) NULL, date_of_birth DATE NULL, address VARCHAR(255) NULL, contact_number VARCHAR(20) NULL, email VARCHAR(100) NULL,
  department_id INT NULL, position_id INT NULL, employment_status VARCHAR(30) NULL, date_hired DATE NULL, emergency_contact_name VARCHAR(100) NULL,
  emergency_contact_number VARCHAR(20) NULL, photo VARCHAR(255) NULL, basic_salary DECIMAL(10,2) DEFAULT 0.00,
  login_username VARCHAR(50) NULL, login_role VARCHAR(40) NULL, login_status VARCHAR(20) NULL, archived_by INT NULL, archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(255) NULL, INDEX idx_archive_emp(original_employee_id), INDEX idx_archive_date(archived_at)
) ENGINE=InnoDB;

-- Compliance, audit, consent and operational support
CREATE TABLE IF NOT EXISTS audit_logs (
  audit_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  employee_id VARCHAR(20) NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(80) NOT NULL,
  entity_id VARCHAR(80) NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at), INDEX idx_audit_user (user_id), INDEX idx_audit_entity (entity, entity_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS privacy_consents (
  consent_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  policy_version VARCHAR(30) NOT NULL DEFAULT '1.0',
  consented_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  withdrawn_at TIMESTAMP NULL,
  ip_address VARCHAR(45) NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX idx_consent_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_jobs (
  job_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(100) NOT NULL,
  status ENUM('Queued','Running','Completed','Failed') NOT NULL DEFAULT 'Queued',
  started_at DATETIME NULL, finished_at DATETIME NULL, details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_backups (
  backup_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  file_size BIGINT DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS privacy_requests (
  request_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  request_type ENUM('Delete','Access','Correction') NOT NULL,
  reason VARCHAR(500) NULL,
  status ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actioned_at TIMESTAMP NULL,
  actioned_by INT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (actioned_by) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_privacy_status(status)
) ENGINE=InnoDB;


-- Demo attendance history across different months (15–22 weekday records per employee/month).
INSERT IGNORE INTO attendance (employee_id, attendance_date, time_in, time_out, break_start, break_end, status, late_minutes, overtime_minutes, remarks) VALUES
('EMP-2026-0001','2026-06-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-08','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-09','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-11','08:29:00','17:00:00','12:00:00','13:00:00','Late',29,0,'Demo late record'),
('EMP-2026-0001','2026-06-12','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-15','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-16','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-17',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0001','2026-06-18','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-06-19','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-06','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-07','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-08','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-09','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-10','08:28:00','17:00:00','12:00:00','13:00:00','Late',28,0,'Demo late record'),
('EMP-2026-0001','2026-07-13','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-14','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-15','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-16',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0001','2026-07-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-20','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-21','08:21:00','17:00:00','12:00:00','13:00:00','Late',21,0,'Demo late record'),
('EMP-2026-0001','2026-07-22','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-23','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-24','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-07-28','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-06','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-07','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-11','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,71,'Demo overtime record'),
('EMP-2026-0001','2026-08-12','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-13','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-14','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-18','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-19','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-20','08:20:00','17:00:00','12:00:00','13:00:00','Late',20,0,'Demo late record'),
('EMP-2026-0001','2026-08-21','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-24','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,84,'Demo overtime record'),
('EMP-2026-0001','2026-08-25','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-26','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-28','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0001','2026-08-31','08:31:00','17:00:00','12:00:00','13:00:00','Late',31,0,'Demo late record'),
('EMP-2026-0002','2026-06-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-08','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-09','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-10','08:29:00','17:00:00','12:00:00','13:00:00','Late',29,0,'Demo late record'),
('EMP-2026-0002','2026-06-11','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-12','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,73,'Demo overtime record'),
('EMP-2026-0002','2026-06-15','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-16',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0002','2026-06-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-18','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-06-19','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-06','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-07','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-08','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-09','08:28:00','17:00:00','12:00:00','13:00:00','Late',28,0,'Demo late record'),
('EMP-2026-0002','2026-07-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-13','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-14','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-15',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0002','2026-07-16','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-20','08:21:00','17:00:00','12:00:00','13:00:00','Late',21,0,'Demo late record'),
('EMP-2026-0002','2026-07-21','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-22','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-23','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-24','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,85,'Demo overtime record'),
('EMP-2026-0002','2026-07-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-07-28','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-06','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-07','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-10','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,71,'Demo overtime record'),
('EMP-2026-0002','2026-08-11','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-12','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-13','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record');
INSERT IGNORE INTO attendance (employee_id, attendance_date, time_in, time_out, break_start, break_end, status, late_minutes, overtime_minutes, remarks) VALUES
('EMP-2026-0002','2026-08-14',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0002','2026-08-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-18','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-19','08:20:00','17:00:00','12:00:00','13:00:00','Late',20,0,'Demo late record'),
('EMP-2026-0002','2026-08-20','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-21','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-24','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-25','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-26','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-28','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0002','2026-08-31',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0003','2026-06-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-08','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-09','08:29:00','17:00:00','12:00:00','13:00:00','Late',29,0,'Demo late record'),
('EMP-2026-0003','2026-06-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-11','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,73,'Demo overtime record'),
('EMP-2026-0003','2026-06-12','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-15',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0003','2026-06-16','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-18','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-06-19','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-06','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-07','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-08','08:28:00','17:00:00','12:00:00','13:00:00','Late',28,0,'Demo late record'),
('EMP-2026-0003','2026-07-09','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-10','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,72,'Demo overtime record'),
('EMP-2026-0003','2026-07-13','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-14',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0003','2026-07-15','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-16','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-20','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-21','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-22','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-23','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,85,'Demo overtime record'),
('EMP-2026-0003','2026-07-24','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-07-28','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-06','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-07','08:27:00','17:00:00','12:00:00','13:00:00','Late',27,0,'Demo late record'),
('EMP-2026-0003','2026-08-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-11','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-12','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-13',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0003','2026-08-14','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-18','08:20:00','17:00:00','12:00:00','13:00:00','Late',20,0,'Demo late record'),
('EMP-2026-0003','2026-08-19','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-20','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-21','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-24','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-25','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-26','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-28','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0003','2026-08-31','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-08','08:29:00','17:00:00','12:00:00','13:00:00','Late',29,0,'Demo late record'),
('EMP-2026-0004','2026-06-09','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-10','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,73,'Demo overtime record'),
('EMP-2026-0004','2026-06-11','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-12','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-15','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-16','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-18','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-06-19','08:22:00','17:00:00','12:00:00','13:00:00','Late',22,0,'Demo late record'),
('EMP-2026-0004','2026-07-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-06','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-07','08:28:00','17:00:00','12:00:00','13:00:00','Late',28,0,'Demo late record'),
('EMP-2026-0004','2026-07-08','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-09','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,72,'Demo overtime record'),
('EMP-2026-0004','2026-07-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-13',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0004','2026-07-14','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-15','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-16','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-20','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-21','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-22','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,85,'Demo overtime record'),
('EMP-2026-0004','2026-07-23','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record');
INSERT IGNORE INTO attendance (employee_id, attendance_date, time_in, time_out, break_start, break_end, status, late_minutes, overtime_minutes, remarks) VALUES
('EMP-2026-0004','2026-07-24','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-07-28','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-06','08:27:00','17:00:00','12:00:00','13:00:00','Late',27,0,'Demo late record'),
('EMP-2026-0004','2026-08-07','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-11','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-12',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0004','2026-08-13','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-14','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-17','08:20:00','17:00:00','12:00:00','13:00:00','Late',20,0,'Demo late record'),
('EMP-2026-0004','2026-08-18','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-19','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-20','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-21','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,84,'Demo overtime record'),
('EMP-2026-0004','2026-08-24','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-25','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-26','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0004','2026-08-28','08:31:00','17:00:00','12:00:00','13:00:00','Late',31,0,'Demo late record'),
('EMP-2026-0004','2026-08-31','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-05','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-08','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-09','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,73,'Demo overtime record'),
('EMP-2026-0005','2026-06-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-11','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-12','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-15','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-16','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-06-18','08:22:00','17:00:00','12:00:00','13:00:00','Late',22,0,'Demo late record'),
('EMP-2026-0005','2026-06-19','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-01','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-02','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-06','08:28:00','17:00:00','12:00:00','13:00:00','Late',28,0,'Demo late record'),
('EMP-2026-0005','2026-07-07','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-08','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,72,'Demo overtime record'),
('EMP-2026-0005','2026-07-09','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-13','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-14','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-15','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-16','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-17','08:21:00','17:00:00','12:00:00','13:00:00','Late',21,0,'Demo late record'),
('EMP-2026-0005','2026-07-20','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-21','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,85,'Demo overtime record'),
('EMP-2026-0005','2026-07-22','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-23','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-24','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-27','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-07-28','08:32:00','17:00:00','12:00:00','13:00:00','Late',32,0,'Demo late record'),
('EMP-2026-0005','2026-08-03','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-04','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-05','08:27:00','17:00:00','12:00:00','13:00:00','Late',27,0,'Demo late record'),
('EMP-2026-0005','2026-08-06','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-07','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,71,'Demo overtime record'),
('EMP-2026-0005','2026-08-10','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-11',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0005','2026-08-12','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-13','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-14','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-17','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-18','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-19','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-20','08:00:00','18:00:00','12:00:00','13:00:00','Overtime',0,84,'Demo overtime record'),
('EMP-2026-0005','2026-08-21','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-24','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-25','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-26','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record'),
('EMP-2026-0005','2026-08-27','08:31:00','17:00:00','12:00:00','13:00:00','Late',31,0,'Demo late record'),
('EMP-2026-0005','2026-08-28',NULL,NULL,NULL,NULL,'Absent',0,0,'Demo absent record'),
('EMP-2026-0005','2026-08-31','08:00:00','17:00:00','12:00:00','13:00:00','Present',0,0,'Demo attendance record');

-- Demo payroll history: six semi-monthly periods across June–August 2026.
INSERT INTO payroll (employee_id,pay_period_start,pay_period_end,basic_salary,overtime_pay,allowances,incentives,bonuses,gross_pay,tax_deduction,sss_deduction,philhealth_deduction,pagibig_deduction,late_deduction,absence_deduction,total_deductions,net_pay,generated_by,date_generated) VALUES
('EMP-2026-0001','2026-06-01','2026-06-15',25000.00,0.00,0.00,0.00,0.00,25000.00,379.38,1125.00,312.50,200.00,0.00,1136.36,3153.24,21846.76,NULL,'2026-06-15 18:00:00'),
('EMP-2026-0001','2026-06-16','2026-06-30',25000.00,350.00,0.00,0.00,1250.00,26600.00,619.38,1125.00,312.50,200.00,75.00,0.00,2331.88,24268.12,NULL,'2026-06-30 18:00:00'),
('EMP-2026-0001','2026-07-01','2026-07-15',25000.00,700.00,0.00,0.00,0.00,25700.00,484.38,1125.00,312.50,200.00,150.00,0.00,2271.88,23428.12,NULL,'2026-07-15 18:00:00'),
('EMP-2026-0001','2026-07-16','2026-07-31',25000.00,0.00,0.00,0.00,1250.00,26250.00,566.88,1125.00,312.50,200.00,225.00,0.00,2429.38,23820.62,NULL,'2026-07-31 18:00:00'),
('EMP-2026-0001','2026-08-01','2026-08-15',25000.00,350.00,0.00,0.00,0.00,25350.00,431.88,1125.00,312.50,200.00,0.00,0.00,2069.38,23280.62,NULL,'2026-08-15 18:00:00'),
('EMP-2026-0001','2026-08-16','2026-08-31',25000.00,700.00,0.00,0.00,1250.00,26950.00,671.88,1125.00,312.50,200.00,75.00,1136.36,3520.74,23429.26,NULL,'2026-08-31 18:00:00'),
('EMP-2026-0002','2026-06-01','2026-06-15',22000.00,350.00,0.00,0.00,0.00,22350.00,7.75,990.00,275.00,200.00,75.00,0.00,1547.75,20802.25,NULL,'2026-06-15 18:00:00'),
('EMP-2026-0002','2026-06-16','2026-06-30',22000.00,700.00,0.00,0.00,1100.00,23800.00,225.25,990.00,275.00,200.00,150.00,0.00,1840.25,21959.75,NULL,'2026-06-30 18:00:00'),
('EMP-2026-0002','2026-07-01','2026-07-15',22000.00,0.00,0.00,0.00,0.00,22000.00,0.00,990.00,275.00,200.00,225.00,0.00,1690.00,20310.00,NULL,'2026-07-15 18:00:00'),
('EMP-2026-0002','2026-07-16','2026-07-31',22000.00,350.00,0.00,0.00,1100.00,23450.00,172.75,990.00,275.00,200.00,0.00,0.00,1637.75,21812.25,NULL,'2026-07-31 18:00:00'),
('EMP-2026-0002','2026-08-01','2026-08-15',22000.00,700.00,0.00,0.00,0.00,22700.00,60.25,990.00,275.00,200.00,75.00,1000.00,2600.25,20099.75,NULL,'2026-08-15 18:00:00'),
('EMP-2026-0002','2026-08-16','2026-08-31',22000.00,0.00,0.00,0.00,1100.00,23100.00,120.25,990.00,275.00,200.00,150.00,0.00,1735.25,21364.75,NULL,'2026-08-31 18:00:00'),
('EMP-2026-0003','2026-06-01','2026-06-15',22000.00,700.00,0.00,0.00,0.00,22700.00,60.25,990.00,275.00,200.00,150.00,0.00,1675.25,21024.75,NULL,'2026-06-15 18:00:00'),
('EMP-2026-0003','2026-06-16','2026-06-30',22000.00,0.00,0.00,0.00,1100.00,23100.00,120.25,990.00,275.00,200.00,225.00,0.00,1810.25,21289.75,NULL,'2026-06-30 18:00:00'),
('EMP-2026-0003','2026-07-01','2026-07-15',22000.00,350.00,0.00,0.00,0.00,22350.00,7.75,990.00,275.00,200.00,0.00,0.00,1472.75,20877.25,NULL,'2026-07-15 18:00:00'),
('EMP-2026-0003','2026-07-16','2026-07-31',22000.00,700.00,0.00,0.00,1100.00,23800.00,225.25,990.00,275.00,200.00,75.00,1000.00,2765.25,21034.75,NULL,'2026-07-31 18:00:00'),
('EMP-2026-0003','2026-08-01','2026-08-15',22000.00,0.00,0.00,0.00,0.00,22000.00,0.00,990.00,275.00,200.00,150.00,0.00,1615.00,20385.00,NULL,'2026-08-15 18:00:00'),
('EMP-2026-0003','2026-08-16','2026-08-31',22000.00,350.00,0.00,0.00,1100.00,23450.00,172.75,990.00,275.00,200.00,225.00,0.00,1862.75,21587.25,NULL,'2026-08-31 18:00:00'),
('EMP-2026-0004','2026-06-01','2026-06-15',18000.00,0.00,0.00,0.00,0.00,18000.00,0.00,810.00,225.00,200.00,225.00,0.00,1460.00,16540.00,NULL,'2026-06-15 18:00:00'),
('EMP-2026-0004','2026-06-16','2026-06-30',18000.00,350.00,0.00,0.00,900.00,19250.00,0.00,810.00,225.00,200.00,0.00,0.00,1235.00,18015.00,NULL,'2026-06-30 18:00:00'),
('EMP-2026-0004','2026-07-01','2026-07-15',18000.00,700.00,0.00,0.00,0.00,18700.00,0.00,810.00,225.00,200.00,75.00,818.18,2128.18,16571.82,NULL,'2026-07-15 18:00:00'),
('EMP-2026-0004','2026-07-16','2026-07-31',18000.00,0.00,0.00,0.00,900.00,18900.00,0.00,810.00,225.00,200.00,150.00,0.00,1385.00,17515.00,NULL,'2026-07-31 18:00:00'),
('EMP-2026-0004','2026-08-01','2026-08-15',18000.00,350.00,0.00,0.00,0.00,18350.00,0.00,810.00,225.00,200.00,225.00,0.00,1460.00,16890.00,NULL,'2026-08-15 18:00:00'),
('EMP-2026-0004','2026-08-16','2026-08-31',18000.00,700.00,0.00,0.00,900.00,19600.00,0.00,810.00,225.00,200.00,0.00,0.00,1235.00,18365.00,NULL,'2026-08-31 18:00:00'),
('EMP-2026-0005','2026-06-01','2026-06-15',24000.00,350.00,0.00,0.00,0.00,24350.00,290.50,1080.00,300.00,200.00,0.00,0.00,1870.50,22479.50,NULL,'2026-06-15 18:00:00'),
('EMP-2026-0005','2026-06-16','2026-06-30',24000.00,700.00,0.00,0.00,1200.00,25900.00,523.00,1080.00,300.00,200.00,75.00,1090.91,3268.91,22631.09,NULL,'2026-06-30 18:00:00'),
('EMP-2026-0005','2026-07-01','2026-07-15',24000.00,0.00,0.00,0.00,0.00,24000.00,238.00,1080.00,300.00,200.00,150.00,0.00,1968.00,22032.00,NULL,'2026-07-15 18:00:00'),
('EMP-2026-0005','2026-07-16','2026-07-31',24000.00,350.00,0.00,0.00,1200.00,25550.00,470.50,1080.00,300.00,200.00,225.00,0.00,2275.50,23274.50,NULL,'2026-07-31 18:00:00'),
('EMP-2026-0005','2026-08-01','2026-08-15',24000.00,700.00,0.00,0.00,0.00,24700.00,343.00,1080.00,300.00,200.00,0.00,0.00,1923.00,22777.00,NULL,'2026-08-15 18:00:00'),
('EMP-2026-0005','2026-08-16','2026-08-31',24000.00,0.00,0.00,0.00,1200.00,25200.00,418.00,1080.00,300.00,200.00,75.00,0.00,2073.00,23127.00,NULL,'2026-08-31 18:00:00');

INSERT INTO system_settings (setting_key, setting_value) VALUES ('demo_history_seed_v1','1') ON DUPLICATE KEY UPDATE setting_value='1';
