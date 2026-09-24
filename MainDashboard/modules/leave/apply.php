<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireLogin();

if (isAdmin()) redirect('/modules/leave/list.php');

$pageTitle = 'Apply for Leave';
$empId = currentEmployeeId();
$errors = [];
$leaveSettings = $pdo->query("SELECT * FROM leave_settings WHERE allow_employee_apply=1 ORDER BY leave_type")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $leaveType = $_POST['leave_type'] ?? '';
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date'] ?? '';
    $reason    = trim($_POST['reason'] ?? '');
    $attachment = $_FILES['medical_certificate'] ?? null;
    $attachmentPath = null; $attachmentOriginal = null;

    if (!$leaveType) $errors[] = 'Leave type is required.';
    $allowedTypes = array_column($leaveSettings, 'leave_type');
    if ($leaveType && !in_array($leaveType, $allowedTypes, true)) $errors[] = 'This leave type is currently disabled for employee requests.';
    if (!$startDate) $errors[] = 'Start date is required.';
    if (!$endDate)   $errors[] = 'End date is required.';
    if ($leaveType === 'Sick Leave') {
        if (!$attachment || ($attachment['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) $errors[] = 'Medical certificate is required for Sick Leave.';
        elseif (($attachment['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) $errors[] = 'Medical certificate upload failed.';
        elseif (($attachment['size'] ?? 0) > 5 * 1024 * 1024) $errors[] = 'Medical certificate must not exceed 5 MB.';
        else {
            $allowed = ['application/pdf','image/jpeg','image/png'];
            $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($attachment['tmp_name']);
            if (!in_array($mime, $allowed, true)) $errors[] = 'Medical certificate must be PDF, JPG, or PNG.';
        }
    }
    if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
        $errors[] = 'End date cannot be before start date.';
    }

    $totalDays = 0;
    if (!$errors) {
        $totalDays = (strtotime($endDate) - strtotime($startDate)) / 86400 + 1;

        // Check balance (skip for Maternity/Paternity/Bereavement which are typically statutory and separate)
        if (in_array($leaveType, ['Vacation Leave','Sick Leave','Emergency Leave'])) {
            $year = date('Y', strtotime($startDate));
            $balStmt = $pdo->prepare("SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type = ? AND year = ?");
            $balStmt->execute([$empId, $leaveType, $year]);
            $bal = $balStmt->fetch();
            $remaining = $bal ? ($bal['allocated_days'] - $bal['used_days']) : 0;
            if ($totalDays > $remaining) {
                $errors[] = "Insufficient leave balance. You only have $remaining day(s) of $leaveType remaining.";
            }
        }
    }

    if (!$errors) {
        if ($leaveType === 'Sick Leave') {
            $dir = __DIR__ . '/../../storage/leave_docs';
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            $ext = strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION));
            $safeName = 'sick_' . preg_replace('/[^A-Za-z0-9_-]/','_', $empId) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($attachment['tmp_name'], $dir . '/' . $safeName)) { $errors[] = 'Unable to save the medical certificate.'; }
            else { $attachmentPath = 'storage/leave_docs/' . $safeName; $attachmentOriginal = basename($attachment['name']); }
        }
        if (!$errors) {
            $stmt = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, reason, attachment_path, attachment_original_name, status)
                                    VALUES (?,?,?,?,?,?,?,?,'Pending')");
            $stmt->execute([$empId, $leaveType, $startDate, $endDate, $totalDays, $reason, $attachmentPath, $attachmentOriginal]);
            $leaveId=$pdo->lastInsertId();
            audit($pdo,'LEAVE_REQUESTED','leave_requests',$leaveId,"{$leaveType} {$startDate} to {$endDate}");
            notify_admins($pdo,'New leave request',"Employee {$empId} submitted a {$leaveType} request for {$totalDays} day(s).",'leave','leave_requests',(int)$leaveId,$_SESSION['user_id']);
            flash('success', 'Leave request submitted successfully. Awaiting HR approval.');
            redirect('/modules/leave/list.php');
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="card" style="max-width:640px;">
    <div class="card-header"><h2>Apply for Leave</h2></div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="form-group">
            <label>Leave Type *</label>
            <select name="leave_type" required>
                <option value="">-- Select --</option>
                <?php foreach ($leaveSettings as $setting): $t=$setting['leave_type']; ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= ($_POST['leave_type'] ?? '')===$t?'selected':'' ?>><?= htmlspecialchars($t) ?> (<?= (int)$setting['default_days'] ?> default days)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label>Start Date *</label>
                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>End Date *</label>
                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label>Total Days</label>
            <input type="text" id="total_days" readonly placeholder="Auto-calculated">
        </div>
        <div class="form-group">
            <label>Reason</label>
            <textarea name="reason" rows="3" placeholder="Briefly describe your reason for leave"><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
        </div>
        <div class="form-group" id="medical_certificate_group">
            <label>Medical Certificate <span class="text-muted">(Required for Sick Leave; PDF/JPG/PNG, max 5 MB)</span></label>
            <input type="file" name="medical_certificate" accept="application/pdf,image/jpeg,image/png">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Submit Request</button>
            <a href="<?= siteUrl('modules/leave/list.php') ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
const lt=document.querySelector('select[name=leave_type]'), mg=document.getElementById('medical_certificate_group');
function toggleMed(){ if(!lt||!mg)return; const req=lt.value==='Sick Leave'; mg.style.display=req?'block':'none'; const f=mg.querySelector('input'); if(f) f.required=req; }
if(lt){lt.addEventListener('change',toggleMed);toggleMed();}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
