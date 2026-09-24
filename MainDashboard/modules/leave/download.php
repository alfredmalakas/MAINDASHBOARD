<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireLogin();
$id=(int)($_GET['id']??0);
$s=$pdo->prepare('SELECT * FROM leave_requests WHERE leave_id=?'); $s->execute([$id]); $r=$s->fetch();
if(!$r || empty($r['attachment_path'])) { http_response_code(404); exit('File not found.'); }
if(!isAdmin() && $r['employee_id'] !== currentEmployeeId()) { http_response_code(403); exit('Forbidden.'); }
$file=__DIR__.'/../../'.$r['attachment_path']; if(!is_file($file)){http_response_code(404);exit('File not found.');}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
audit($pdo,'LEAVE_ATTACHMENT_VIEW','leave_requests',$id,'Viewed medical certificate');
header('Content-Type: '.$mime); header('Content-Length: '.filesize($file)); header('Content-Disposition: inline; filename="'.basename($r['attachment_original_name'] ?: 'medical-certificate').'"'); readfile($file); exit;
