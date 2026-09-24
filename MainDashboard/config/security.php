<?php
require_once __DIR__ . '/session.php';
function csrf_token(): string { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8').'">'; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(419); exit('Invalid security token. Please refresh and try again.'); } }
function security_headers(): void {
    header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: strict-origin-when-cross-origin'); header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
function audit(PDO $pdo, string $action, string $entity, $entityId=null, string $details=''): void {
    try { $s=$pdo->prepare('INSERT INTO audit_logs(user_id,employee_id,action,entity,entity_id,details,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)'); $s->execute([$_SESSION['user_id']??null,$_SESSION['employee_id']??null,$action,$entity,$entityId,$details,$_SERVER['REMOTE_ADDR']??'',substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]); } catch(Throwable $e) {}
}

function notify_user(PDO $pdo, int $recipientUserId, string $title, string $message, string $type='info', ?string $entity=null, ?int $relatedId=null): void {
    try { $q=$pdo->prepare("INSERT INTO notifications(recipient_user_id,title,message,notification_type,related_entity,related_id) VALUES(?,?,?,?,?,?)"); $q->execute([$recipientUserId,$title,$message,$type,$entity,$relatedId]); } catch(Throwable $e) {}
}
function notify_admins(PDO $pdo, string $title, string $message, string $type='info', ?string $entity=null, ?int $relatedId=null, ?int $excludeUserId=null): void {
    try { $q=$pdo->query("SELECT user_id FROM users WHERE role IN ('HR Administrator','Super Admin') AND status='Active'"); foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid){ if($excludeUserId !== null && (int)$uid === $excludeUserId) continue; notify_user($pdo,(int)$uid,$title,$message,$type,$entity,$relatedId); } } catch(Throwable $e) {}
}
