<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireRole('HR Administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/modules/leave/list.php');
verify_csrf();

$leaveId  = $_POST['leave_id'] ?? '';
$decision = $_POST['decision'] ?? '';

if (!in_array($decision, ['Approved','Rejected']) || !$leaveId) {
    flash('error', 'Invalid request.');
    redirect('/modules/leave/list.php');
}

$stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE leave_id = ?");
$stmt->execute([$leaveId]);
$leave = $stmt->fetch();

if (!$leave || $leave['status'] !== 'Pending') {
    flash('error', 'Leave request not found or already processed.');
    redirect('/modules/leave/list.php');
}

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE leave_requests SET status = ?, approved_by = ?, date_actioned = NOW() WHERE leave_id = ?");
    $upd->execute([$decision, $_SESSION['user_id'], $leaveId]);

    if ($decision === 'Approved' && in_array($leave['leave_type'], ['Vacation Leave','Sick Leave','Emergency Leave'])) {
        $year = date('Y', strtotime($leave['start_date']));
        $balUpd = $pdo->prepare("UPDATE leave_balances SET used_days = used_days + ? WHERE employee_id = ? AND leave_type = ? AND year = ?");
        $balUpd->execute([$leave['total_days'], $leave['employee_id'], $leave['leave_type'], $year]);

        // Also mark attendance as 'Leave' for each day in range
        $start = new DateTime($leave['start_date']);
        $end = new DateTime($leave['end_date']);
        $end->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        $attStmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status) VALUES (?,?,'Leave')
                                   ON DUPLICATE KEY UPDATE status='Leave'");
        foreach ($period as $date) {
            $attStmt->execute([$leave['employee_id'], $date->format('Y-m-d')]);
        }
    }

    $pdo->commit();
    audit($pdo,'LEAVE_'.$decision,'leave_requests',$leaveId,"Leave request {$leaveId} for {$leave['employee_id']}");
    $uq=$pdo->prepare("SELECT user_id FROM users WHERE employee_id=? AND status='Active'"); $uq->execute([$leave['employee_id']]); $employeeUserId=$uq->fetchColumn();
    if($employeeUserId){ notify_user($pdo,(int)$employeeUserId,'Leave request '.$decision,"Your {$leave['leave_type']} request for {$leave['start_date']} to {$leave['end_date']} was {$decision}.",'leave','leave_requests',(int)$leaveId); }
    flash('success', "Leave request has been $decision.");
} catch (Exception $e) {
    $pdo->rollBack();
    flash('error', 'Failed to process leave request: ' . $e->getMessage());
}

redirect('/modules/leave/list.php');
