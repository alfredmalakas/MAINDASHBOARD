<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

$pageTitle = 'Dashboard';
$today = date('Y-m-d');
$totalEmployees = 0;
$todayPresent = 0;
$pendingLeaves = 0;
$departments = 0;
$recentLeaves = [];
$recentEmployees = [];
$vlBalance = null;
$slBalance = null;
$todayAtt = null;
$myRecentLeaves = [];

if (isAdmin()) {
    $totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status NOT IN ('Resigned','Terminated')")->fetchColumn();
    $todayPresent   = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND status IN ('Present','Late','Overtime')");
    $todayPresent->execute();
    $todayPresent   = $todayPresent->fetchColumn();
    $pendingLeaves  = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'")->fetchColumn();
    $departments    = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();

    $recentLeaves = $pdo->query("SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name
                                  FROM leave_requests lr JOIN employees e ON lr.employee_id = e.employee_id
                                  ORDER BY lr.date_filed DESC LIMIT 5")->fetchAll();

    $recentEmployees = $pdo->query("SELECT * FROM employees ORDER BY created_at DESC LIMIT 5")->fetchAll();

    // Admin dashboard module totals for the connected HRIS pie chart.
    $adminAttendance = (int)$pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
    $adminLeave = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests")->fetchColumn();
    $adminPayroll = (int)$pdo->query("SELECT COUNT(*) FROM payroll")->fetchColumn();
    $adminPerformance = (int)$pdo->query("SELECT COUNT(*) FROM performance")->fetchColumn();
    $adminChartTotal = $adminAttendance + $adminLeave + $adminPayroll + $adminPerformance;
    $adminAttendancePct = $adminChartTotal ? round(($adminAttendance / $adminChartTotal) * 100, 2) : 0;
    $adminLeavePct = $adminChartTotal ? round(($adminLeave / $adminChartTotal) * 100, 2) : 0;
    $adminPayrollPct = $adminChartTotal ? round(($adminPayroll / $adminChartTotal) * 100, 2) : 0;
    $adminPerformancePct = $adminChartTotal ? round(($adminPerformance / $adminChartTotal) * 100, 2) : 0;
} else {
    $empId = currentEmployeeId();
    $year = date('Y');
    $vlBalance = $pdo->prepare("SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type = 'Vacation Leave' AND year = ?");
    $vlBalance->execute([$empId, $year]);
    $vlBalance = $vlBalance->fetch();

    $slBalance = $pdo->prepare("SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type = 'Sick Leave' AND year = ?");
    $slBalance->execute([$empId, $year]);
    $slBalance = $slBalance->fetch();

    $todayAtt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $todayAtt->execute([$empId, $today]);
    $todayAtt = $todayAtt->fetch();

    $myRecentLeaves = $pdo->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY date_filed DESC LIMIT 5");
    $myRecentLeaves->execute([$empId]);
    $myRecentLeaves = $myRecentLeaves->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<?php if (isAdmin()): ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div><div class="stat-value"><?= (int)$totalEmployees ?></div><div class="stat-label">Active Employees</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div><div class="stat-value"><?= (int)$todayPresent ?></div><div class="stat-label">Present Today</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📅</div>
            <div><div class="stat-value"><?= (int)$pendingLeaves ?></div><div class="stat-label">Pending Leave Requests</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🏢</div>
            <div><div class="stat-value"><?= (int)$departments ?></div><div class="stat-label">Departments</div></div>
        </div>
    </div>

    <div class="admin-dashboard-grid">
        <div class="card activity-chart-card admin-activity-card">
            <div class="card-header">
                <div>
                    <h2>HRIS Module Activity</h2>
                    <p class="card-subtitle">Live record totals from Attendance, Leave Management, Payroll, and Performance.</p>
                </div>
            </div>
            <?php if ($adminChartTotal > 0): ?>
                <div class="activity-chart-wrap">
                    <div class="activity-pie"
                         style="--p1: <?= $adminAttendancePct ?>%; --p2: <?= $adminAttendancePct + $adminLeavePct ?>%; --p3: <?= $adminAttendancePct + $adminLeavePct + $adminPayrollPct ?>%; --p4: 100%;"
                         aria-label="HRIS module activity pie chart">
                    </div>
                    <div class="activity-legend">
                        <div><span class="legend-dot dot-attendance"></span><span>Attendance</span><strong><?= $adminAttendance ?></strong></div>
                        <div><span class="legend-dot dot-leave"></span><span>Leave Management</span><strong><?= $adminLeave ?></strong></div>
                        <div><span class="legend-dot dot-payroll"></span><span>Payroll</span><strong><?= $adminPayroll ?></strong></div>
                        <div><span class="legend-dot dot-performance"></span><span>Performance</span><strong><?= $adminPerformance ?></strong></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">No HRIS records are available yet.</div>
            <?php endif; ?>
        </div>
        <div class="card admin-chart-summary">
            <div class="ai-mini-icon">✦</div>
            <h2>HRIS Overview</h2>
            <p>This chart is connected directly to the four core transaction modules. When records are added or updated, the dashboard totals refresh automatically.</p>
            <div class="admin-module-list">
                <div><span>Attendance</span><strong><?= $adminAttendance ?></strong></div>
                <div><span>Leave Management</span><strong><?= $adminLeave ?></strong></div>
                <div><span>Payroll</span><strong><?= $adminPayroll ?></strong></div>
                <div><span>Performance</span><strong><?= $adminPerformance ?></strong></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Recent Leave Requests</h2>
            <a href="<?= siteUrl('modules/leave/list.php') ?>" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$recentLeaves): ?>
                <tr><td colspan="4" class="empty-state">No leave requests yet.</td></tr>
            <?php endif; foreach ($recentLeaves as $lr): ?>
                <tr>
                    <td><?= htmlspecialchars($lr['emp_name']) ?></td>
                    <td><?= htmlspecialchars($lr['leave_type']) ?></td>
                    <td><?= htmlspecialchars($lr['start_date']) ?> to <?= htmlspecialchars($lr['end_date']) ?></td>
                    <td>
                        <?php $cls = $lr['status']==='Approved'?'badge-success':($lr['status']==='Rejected'?'badge-error':'badge-warning'); ?>
                        <span class="badge <?= $cls ?>"><?= htmlspecialchars($lr['status']) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Recently Added Employees</h2>
            <a href="<?= siteUrl('modules/employees/list.php') ?>" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Employee ID</th><th>Name</th><th>Status</th><th>Date Hired</th></tr></thead>
            <tbody>
            <?php if (!$recentEmployees): ?>
                <tr><td colspan="4" class="empty-state">No employees yet.</td></tr>
            <?php endif; foreach ($recentEmployees as $emp): ?>
                <tr>
                    <td><?= htmlspecialchars($emp['employee_id']) ?></td>
                    <td><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']) ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($emp['employment_status']) ?></span></td>
                    <td><?= htmlspecialchars($emp['date_hired']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php else: ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">⏱️</div>
            <div>
                <div class="stat-value"><?= $todayAtt ? htmlspecialchars($todayAtt['status']) : 'Not Timed In' ?></div>
                <div class="stat-label">Today's Attendance</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🌴</div>
            <div>
                <div class="stat-value"><?= $vlBalance ? ($vlBalance['allocated_days'] - $vlBalance['used_days']) : 0 ?> days</div>
                <div class="stat-label">Vacation Leave Balance</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🤒</div>
            <div>
                <div class="stat-value"><?= $slBalance ? ($slBalance['allocated_days'] - $slBalance['used_days']) : 0 ?> days</div>
                <div class="stat-label">Sick Leave Balance</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>My Recent Leave Requests</h2>
            <a href="<?= siteUrl('modules/leave/apply.php') ?>" class="btn btn-primary btn-sm">+ Apply for Leave</a>
        </div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$myRecentLeaves): ?>
                <tr><td colspan="4" class="empty-state">No leave requests filed yet.</td></tr>
            <?php endif; foreach ($myRecentLeaves as $lr): ?>
                <tr>
                    <td><?= htmlspecialchars($lr['leave_type']) ?></td>
                    <td><?= htmlspecialchars($lr['start_date']) ?> to <?= htmlspecialchars($lr['end_date']) ?></td>
                    <td><?= (int)$lr['total_days'] ?></td>
                    <td>
                        <?php $cls = $lr['status']==='Approved'?'badge-success':($lr['status']==='Rejected'?'badge-error':'badge-warning'); ?>
                        <span class="badge <?= $cls ?>"><?= htmlspecialchars($lr['status']) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php if (isAdmin()): ?><script>setTimeout(function(){ location.reload(); }, 30000);</script><?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
