<?php
require_once __DIR__ . '/../../config/db.php'; require_once __DIR__ . '/../../config/session.php'; requireLogin();
if (!isAdmin()) { redirect('/modules/attendance/list.php'); }
$pageTitle='Attendance Hours Report';
$preset=(int)($_GET['days']??15); if(!in_array($preset,[15,30],true)) $preset=15;
$end=$_GET['end']??date('Y-m-d'); $start=$_GET['start']??date('Y-m-d',strtotime($end.' -'.($preset-1).' days'));
$sql="SELECT e.employee_id,e.first_name,e.last_name,COUNT(a.attendance_id) records,
COALESCE(SUM(CASE WHEN a.time_in IS NOT NULL AND a.time_out IS NOT NULL THEN GREATEST(0,TIMESTAMPDIFF(MINUTE,a.time_in,a.time_out)-IF(a.break_start IS NOT NULL AND a.break_end IS NOT NULL,TIMESTAMPDIFF(MINUTE,a.break_start,a.break_end),0)) ELSE 0 END),0) total_minutes,
COALESCE(SUM(a.overtime_minutes),0) overtime_minutes,COALESCE(SUM(a.late_minutes),0) late_minutes
FROM employees e LEFT JOIN attendance a ON e.employee_id=a.employee_id AND a.attendance_date BETWEEN ? AND ?
WHERE e.employment_status NOT IN ('Resigned','Terminated') GROUP BY e.employee_id,e.first_name,e.last_name ORDER BY e.first_name,e.last_name";
$stmt=$pdo->prepare($sql); $stmt->execute([$start,$end]); $report=$stmt->fetchAll();
include __DIR__.'/../../includes/header.php';
?>
<div class="card"><div class="card-header"><div><h2>Total Hours — <?= $preset ?> Days</h2><p class="text-muted">Calculate worked hours for a 15-day or 30-day period. Break time is deducted when recorded.</p></div><a href="<?= siteUrl('modules/attendance/list.php') ?>" class="btn btn-secondary">Daily Attendance</a></div>
<form method="get" class="form-grid"><div class="form-group"><label>Period</label><select name="days"><option value="15" <?= $preset===15?'selected':'' ?>>15 Days</option><option value="30" <?= $preset===30?'selected':'' ?>>30 Days</option></select></div><div class="form-group"><label>End Date</label><input type="date" name="end" value="<?= htmlspecialchars($end) ?>"></div><div class="form-group"><label>Start Date</label><input type="date" name="start" value="<?= htmlspecialchars($start) ?>"></div><div class="form-actions" style="align-self:end"><button class="btn btn-primary">Filter</button><button type="button" onclick="window.print()" class="btn btn-secondary">Print Report</button></div></form>
<div class="table-wrap"><table><thead><tr><th>Employee</th><th>Records</th><th>Total Hours</th><th>OT Hours</th><th>Late Minutes</th></tr></thead><tbody>
<?php foreach($report as $r): $hours=intdiv((int)$r['total_minutes'],60); $mins=(int)$r['total_minutes']%60; $ot=round(((int)$r['overtime_minutes'])/60,2); ?><tr><td><?= htmlspecialchars($r['first_name'].' '.$r['last_name'].' ('.$r['employee_id'].')') ?></td><td><?= (int)$r['records'] ?></td><td><strong><?= $hours ?>h <?= $mins ?>m</strong></td><td><?= number_format($ot,2) ?>h</td><td><?= (int)$r['late_minutes'] ?></td></tr><?php endforeach; if(!$report): ?><tr><td colspan="5" class="empty-state">No attendance records for this period.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>