<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();
if (isAdmin()) { redirect('/modules/attendance/list.php'); }
$empId = currentEmployeeId();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $descriptor = trim((string)($_POST['face_descriptor'] ?? ''));
    $consent = isset($_POST['biometric_consent']) && $_POST['biometric_consent'] === '1';
    $arr = json_decode($descriptor, true);
    if (!$consent) { flash('error','Please confirm your consent for facial verification.'); }
    elseif (!is_array($arr) || count($arr) < 64 || count($arr) > 1024) { flash('error','Invalid facial data. Please capture your face again.'); }
    else {
        $clean=[]; foreach($arr as $v){ if(!is_numeric($v)){ $clean=[]; break; } $clean[]=(float)$v; }
        if (!$clean) flash('error','Invalid facial descriptor.');
        else {
            $stmt=$pdo->prepare("UPDATE employees SET face_descriptor=?, face_enrolled_at=NOW() WHERE employee_id=?");
            $stmt->execute([json_encode($clean),$empId]);
            if(function_exists('audit')) audit($pdo,'FACE_ENROLL','employee',$empId,'Employee enrolled facial verification for attendance');
            flash('success','Facial verification has been enrolled. You can now use facial attendance.');
            redirect('/modules/attendance/list.php');
        }
    }
}
$stmt=$pdo->prepare("SELECT face_enrolled_at FROM employees WHERE employee_id=?"); $stmt->execute([$empId]); $enrolled=$stmt->fetchColumn();
$pageTitle='Face Recognition Setup'; include __DIR__.'/../../includes/header.php';
?>
<div class="card" style="max-width:760px;margin:0 auto;">
 <div class="card-header"><div><h2>Employee Facial Recognition</h2><p class="text-muted">Used only to verify your Time In / Time Out.</p></div><a href="<?= siteUrl('modules/attendance/list.php') ?>" class="btn btn-secondary">Back</a></div>
 <div style="padding:10px 0;"><p><strong>Privacy:</strong> The system stores a mathematical face descriptor for matching. It does not save the camera photo/video.</p><p>Status: <strong><?= $enrolled ? 'Enrolled on '.htmlspecialchars($enrolled) : 'Not enrolled' ?></strong></p></div>
 <video id="setupVideo" autoplay muted playsinline style="width:100%;max-height:420px;border-radius:12px;background:#111;"></video>
 <div id="setupStatus" style="margin:12px 0;font-weight:600;">Click Start Camera.</div>
 <label style="display:flex;gap:8px;align-items:flex-start;margin:14px 0;"><input type="checkbox" id="consent"> <span>I consent to the use of my facial biometric descriptor for attendance verification.</span></label>
 <form method="POST" id="enrollForm"><input type="hidden" name="face_descriptor" id="enrollDescriptor"><input type="hidden" name="biometric_consent" id="biometricConsent" value="0"><button type="button" class="btn btn-secondary" id="startBtn" onclick="startCamera()">Start Camera</button> <button type="button" class="btn btn-primary" id="captureBtn" onclick="captureFace()" disabled>Enroll My Face</button></form>
</div>
<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js"></script>
<script>
let stream=null; const modelUrl='https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model';
async function startCamera(){const s=document.getElementById('setupStatus'); try{s.textContent='Loading facial recognition models…';await Promise.all([faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),faceapi.nets.faceLandmark68Net.loadFromUri(modelUrl),faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)]);stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:false});document.getElementById('setupVideo').srcObject=stream;document.getElementById('captureBtn').disabled=false;s.textContent='Camera ready. Look directly at the camera.';}catch(e){s.textContent='Unable to start camera/model: '+e.message;}}
async function captureFace(){const s=document.getElementById('setupStatus'); if(!document.getElementById('consent').checked){s.textContent='Please confirm consent first.';return;} s.textContent='Capturing face…'; const v=document.getElementById('setupVideo'); try{const d=await faceapi.detectSingleFace(v,new faceapi.TinyFaceDetectorOptions({inputSize:320,scoreThreshold:.5})).withFaceLandmarks().withFaceDescriptor(); if(!d) throw new Error('No face detected.');document.getElementById('enrollDescriptor').value=JSON.stringify(Array.from(d.descriptor));document.getElementById('biometricConsent').value='1';if(stream)stream.getTracks().forEach(t=>t.stop());document.getElementById('enrollForm').submit();}catch(e){s.textContent=e.message+' Please look directly at the camera and try again.';}}
</script>
<?php include __DIR__.'/../../includes/footer.php'; ?>
