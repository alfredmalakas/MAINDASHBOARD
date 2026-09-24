<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';
requireRole('HR Administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/modules/employees/list.php'); }
verify_csrf();
$id = trim($_POST['id'] ?? '');
$reason = trim($_POST['reason'] ?? 'Employee removed by administrator');
if (!$id) { flash('error','Invalid employee record.'); redirect('/modules/employees/list.php'); }
if ($id === currentEmployeeId()) { flash('error','You cannot archive your own employee record while logged in.'); redirect('/modules/employees/list.php'); }

$pdo->beginTransaction();
try {
    $s=$pdo->prepare("SELECT e.*,u.username AS login_username,u.role AS login_role,u.status AS login_status FROM employees e LEFT JOIN users u ON u.employee_id=e.employee_id WHERE e.employee_id=?");
    $s->execute([$id]); $e=$s->fetch();
    if (!$e) throw new RuntimeException('Employee not found.');
    $ins=$pdo->prepare("INSERT INTO employee_archive (original_employee_id,first_name,middle_name,last_name,gender,date_of_birth,address,contact_number,email,department_id,position_id,employment_status,date_hired,emergency_contact_name,emergency_contact_number,photo,basic_salary,login_username,login_role,login_status,archived_by,reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$e['employee_id'],$e['first_name'],$e['middle_name'],$e['last_name'],$e['gender'],$e['date_of_birth'],$e['address'],$e['contact_number'],$e['email'],$e['department_id'],$e['position_id'],$e['employment_status'],$e['date_hired'],$e['emergency_contact_name'],$e['emergency_contact_number'],$e['photo'],$e['basic_salary'],$e['login_username'],$e['login_role'],$e['login_status'],$_SESSION['user_id'],$reason]);
    $archiveId=$pdo->lastInsertId();
    $del=$pdo->prepare('DELETE FROM employees WHERE employee_id=?'); $del->execute([$id]);
    audit($pdo,'EMPLOYEE_ARCHIVED','employee_archive',$archiveId,"Employee {$id} archived");
    $pdo->commit();
    flash('success','Employee moved to Archive successfully.');
} catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); flash('error','Archive failed: '.$e->getMessage()); }
redirect('/modules/employees/list.php');
