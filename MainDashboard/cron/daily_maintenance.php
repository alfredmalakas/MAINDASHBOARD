<?php
// Run from Task Scheduler/cron once daily: php cron/daily_maintenance.php
require_once __DIR__.'/../config/db.php';
$s=$pdo->prepare("INSERT INTO system_jobs(job_name,status,started_at) VALUES('Daily maintenance','Running',NOW())"); $s->execute(); $id=$pdo->lastInsertId();
try { $pdo->exec("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)"); $s=$pdo->prepare("UPDATE system_jobs SET status='Completed',finished_at=NOW(),details=? WHERE job_id=?"); $s->execute(['365-day audit retention cleanup',$id]); echo "Daily maintenance completed.\n"; } catch(Throwable $e){$s=$pdo->prepare("UPDATE system_jobs SET status='Failed',finished_at=NOW(),details=? WHERE job_id=?");$s->execute([$e->getMessage(),$id]);exit(1);}
