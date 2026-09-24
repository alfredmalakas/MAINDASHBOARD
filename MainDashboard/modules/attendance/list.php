<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireLogin();

$pageTitle = 'Attendance';
$today = date('Y-m-d');
$history = [];
$records = [];
$todayRecord = null;
$filterDate = $today;

// Handle Time In / Time Out actions (Employee only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isAdmin()) {
    $empId = currentEmployeeId();
    $action = $_POST['action'] ?? '';
    $faceJson = trim((string)($_POST['face_descriptor'] ?? ''));

    $existing = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $existing->execute([$empId, $today]);
    $record = $existing->fetch();

    // Facial verification: browser captures a descriptor; PHP compares it with the
    // employee's enrolled descriptor. No face image is stored.
    $empFace = $pdo->prepare("SELECT face_descriptor FROM employees WHERE employee_id = ?");
    $empFace->execute([$empId]);
    $storedFace = $empFace->fetchColumn();
    $faceOk = false;
    if ($storedFace && $faceJson) {
        $a = json_decode($storedFace, true);
        $b = json_decode($faceJson, true);
        if (is_array($a) && is_array($b) && count($a) === count($b) && count($a) >= 64) {
            $sum = 0.0;
            foreach ($a as $i => $v) {
                $d = (float)$v - (float)$b[$i];
                $sum += $d * $d;
            }
            $distance = sqrt($sum);
            $faceOk = $distance <= 0.52;
        }
    }
    if (!$faceOk) {
        flash('error', $storedFace ? 'Facial verification failed. Please face the camera and try again.' : 'Please enroll your face first before recording attendance.');
        redirect('/modules/attendance/list.php');
    }

    $officialStart = '08:00:00';
    $officialEnd   = '17:00:00';

    if ($action === 'time_in') {
        if ($record) {
            flash('error', 'You have already timed in today.');
        } else {
            $now = date('H:i:s');
            $lateMinutes = 0;
            $status = 'Present';
            if ($now > $officialStart) {
                $lateMinutes = (strtotime($now) - strtotime($officialStart)) / 60;
                $status = 'Late';
            }
            $ins = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes, remarks) VALUES (?,?,?,?,?,?)");
            $ins->execute([$empId, $today, $now, $status, (int)$lateMinutes, 'Facial verification passed']);
            if (function_exists('audit')) audit($pdo,'TIME_IN','attendance',$pdo->lastInsertId(),'Employee recorded time in using facial verification');
            flash('success', 'Facial verification passed. Time in recorded at ' . date('h:i A', strtotime($now)) . '.');
        }
    } elseif ($action === 'time_out') {
        if (!$record) {
            flash('error', 'You need to time in first.');
        } elseif ($record['time_out']) {
            flash('error', 'You have already timed out today.');
        } else {
            $now = date('H:i:s');
            $overtimeMinutes = 0;
            $status = $record['status'];
            if ($now > $officialEnd) {
                $overtimeMinutes = (strtotime($now) - strtotime($officialEnd)) / 60;
                if ($status === 'Present') $status = 'Overtime';
            }
            $upd = $pdo->prepare("UPDATE attendance SET time_out=?, overtime_minutes=?, status=?, remarks=CONCAT(COALESCE(remarks,''),' | Facial verification passed') WHERE attendance_id = ?");
            $upd->execute([$now, (int)$overtimeMinutes, $status, $record['attendance_id']]);
            if (function_exists('audit')) audit($pdo,'TIME_OUT','attendance',$record['attendance_id'],'Employee recorded time out using facial verification');
            flash('success', 'Facial verification passed. Time out recorded at ' . date('h:i A', strtotime($now)) . '.');
        }
    }
    redirect('/modules/attendance/list.php');
}

if (isAdmin()) {
    $filterDate = $_GET['date'] ?? $today;
    $stmt = $pdo->prepare("SELECT a.*, e.first_name, e.last_name, e.employee_id AS emp_id FROM attendance a
                            JOIN employees e ON a.employee_id = e.employee_id
                            WHERE a.attendance_date = ? ORDER BY a.time_in DESC");
    $stmt->execute([$filterDate]);
    $records = $stmt->fetchAll();
} else {
    $empId = currentEmployeeId();
    $todayStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $todayStmt->execute([$empId, $today]);
    $todayRecord = $todayStmt->fetch();

    $histStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 30");
    $histStmt->execute([$empId]);
    $history = $histStmt->fetchAll();
}

include __DIR__ . '/../../includes/header.php';

function statusBadge($status) {
    $map = [
        'Present'   => 'badge-success',
        'Overtime'  => 'badge-info',
        'Late'      => 'badge-warning',
        'Absent'    => 'badge-error',
        'Leave'     => 'badge-gray',
        'Half-Day'  => 'badge-warning',
    ];
    $cls = $map[$status] ?? 'badge-gray';
    return "<span class=\"badge $cls\">" . htmlspecialchars($status) . "</span>";
}
?>

<?php if (!isAdmin()): ?>
<div class="card">
    <div class="card-header"><h3>Time In / Time Out</h3></div>
    <div class="clock-widget">
        <div class="clock-time" id="clockTime">--:--:--</div>
        <div class="clock-date" id="clockDate">Loading...</div>
    </div>
    <div class="form-actions" style="justify-content:center;">
        <button type="button" class="btn btn-success" onclick="openFaceAttendance('time_in')" <?= ($todayRecord && $todayRecord['time_in']) ? 'disabled' : '' ?>>
            📷 Time In <?= ($todayRecord && $todayRecord['time_in']) ? '(' . date('h:i A', strtotime($todayRecord['time_in'])) . ')' : '' ?>
        </button>
        <button type="button" class="btn btn-warning" onclick="openFaceAttendance('time_out')" <?= (!$todayRecord || !$todayRecord['time_in'] || $todayRecord['time_out']) ? 'disabled' : '' ?>>
            📷 Time Out <?= ($todayRecord && $todayRecord['time_out']) ? '(' . date('h:i A', strtotime($todayRecord['time_out'])) . ')' : '' ?>
        </button>
    </div>
    <p class="text-muted" style="text-align:center;margin-top:10px;">Attendance requires facial verification. No camera photo is stored.</p>
    <div style="text-align:center;margin-top:8px;"><a class="btn btn-secondary btn-sm" href="<?= siteUrl('modules/attendance/face_setup.php') ?>">⚙️ Set Up / Update Face Recognition</a></div>
</div>

<div id="faceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:14px;max-width:620px;width:100%;padding:20px;text-align:center;">
    <h3>Facial Verification</h3><p class="text-muted">Allow camera access, look directly at the camera, then verify.</p>
    <video id="attendanceVideo" autoplay muted playsinline style="width:100%;max-height:360px;border-radius:12px;background:#111;"></video>
    <div id="faceStatus" style="margin:12px 0;font-weight:600;">Starting camera…</div>
    <form id="faceAttendanceForm" method="POST">
      <input type="hidden" name="action" id="faceAction">
      <input type="hidden" name="face_descriptor" id="faceDescriptor">
      <button type="button" class="btn btn-primary" id="verifyFaceBtn" onclick="verifyFaceAttendance()">Verify Face</button>
      <button type="button" class="btn btn-secondary" onclick="closeFaceAttendance()">Cancel</button>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js"></script>
<script>
let attendanceStream=null, faceAction='';
const faceModelUrl='https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model';
async function loadFaceModels(){
  await Promise.all([faceapi.nets.tinyFaceDetector.loadFromUri(faceModelUrl),faceapi.nets.faceLandmark68Net.loadFromUri(faceModelUrl),faceapi.nets.faceRecognitionNet.loadFromUri(faceModelUrl)]);
}
async function openFaceAttendance(action){
  faceAction=action; document.getElementById('faceAction').value=action; document.getElementById('faceModal').style.display='flex';
  const status=document.getElementById('faceStatus'); status.textContent='Loading facial recognition…';
  try{ await loadFaceModels(); attendanceStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:false}); document.getElementById('attendanceVideo').srcObject=attendanceStream; status.textContent='Camera ready. Look directly at the camera.'; }
  catch(e){ status.textContent='Camera/model access failed. Use HTTPS/localhost and allow camera permission.'; }
}
function closeFaceAttendance(){ if(attendanceStream){attendanceStream.getTracks().forEach(t=>t.stop());attendanceStream=null;} document.getElementById('faceModal').style.display='none'; }
async function verifyFaceAttendance(){
  const status=document.getElementById('faceStatus'), video=document.getElementById('attendanceVideo'), btn=document.getElementById('verifyFaceBtn'); btn.disabled=true; status.textContent='Verifying face…';
  try{ const d=await faceapi.detectSingleFace(video,new faceapi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:.5})).withFaceLandmarks().withFaceDescriptor();
    if(!d) throw new Error('No face detected.');
    document.getElementById('faceDescriptor').value=JSON.stringify(Array.from(d.descriptor)); closeFaceAttendance(); document.getElementById('faceAttendanceForm').submit();
  }catch(e){ status.textContent=e.message+' Please try again.'; btn.disabled=false; }
}
</script>

<div class="card">
    <div class="card-header"><h3>My Attendance History (Last 30 Records)</h3></div>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Late (min)</th><th>OT (min)</th></tr></thead>
        <tbody>
        <?php if (!$history): ?>
            <tr><td colspan="6" class="empty-state">No attendance records yet.</td></tr>
        <?php endif; foreach ($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['attendance_date']) ?></td>
                <td><?= $h['time_in'] ? date('h:i A', strtotime($h['time_in'])) : '—' ?></td>
                <td><?= $h['time_out'] ? date('h:i A', strtotime($h['time_out'])) : '—' ?></td>
                <td><?= statusBadge($h['status']) ?></td>
                <td><?= (int)$h['late_minutes'] ?></td>
                <td><?= (int)$h['overtime_minutes'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Daily Attendance Report</h2>
        <a href="<?= siteUrl('modules/attendance/report.php') ?>" class="btn btn-secondary btn-sm">Monthly Report</a>
    </div>
    <form method="GET" class="toolbar">
        <div class="form-group mb-0">
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()">
        </div>
    </form>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Employee</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Late (min)</th><th>OT (min)</th></tr></thead>
        <tbody>
        <?php if (!$records): ?>
            <tr><td colspan="6" class="empty-state">No attendance records for this date.</td></tr>
        <?php endif; foreach ($records as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?> <span class="text-muted">(<?= htmlspecialchars($r['emp_id']) ?>)</span></td>
                <td><?= $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '—' ?></td>
                <td><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—' ?></td>
                <td><?= statusBadge($r['status']) ?></td>
                <td><?= (int)$r['late_minutes'] ?></td>
                <td><?= (int)$r['overtime_minutes'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
