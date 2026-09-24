<?php
require_once __DIR__.'/../../config/db.php'; require_once __DIR__.'/../../config/security.php'; requireRole('HR Administrator');
$pageTitle='Security & Compliance';
$counts=[]; foreach(['audit_logs','privacy_consents','system_jobs','system_backups','employee_archive'] as $t){ try{$counts[$t]=(int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();}catch(Throwable $e){$counts[$t]=0;} }
$logs=$pdo->query("SELECT a.*,COALESCE(NULLIF(CONCAT(e.first_name,' ',e.last_name),' '),u.username,'System') AS actor FROM audit_logs a LEFT JOIN users u ON a.user_id=u.user_id LEFT JOIN employees e ON a.employee_id=e.employee_id ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){ verify_csrf(); if(isset($_POST['consent'])){ $s=$pdo->prepare('INSERT INTO privacy_consents(user_id,policy_version,ip_address) VALUES(?,?,?)'); $s->execute([$_SESSION['user_id'],'1.0',$_SERVER['REMOTE_ADDR']??'']); audit($pdo,'CONSENT_GRANTED','privacy_consents',$pdo->lastInsertId(),'Privacy policy consent recorded'); flash('success','Consent recorded.'); redirect('/modules/compliance/index.php'); }}
include __DIR__.'/../../includes/header.php';
?>
<div class="card"><h2>Security & Compliance Center</h2><p>Monitor employee and administrator activity, privacy events, backups, and system operations.</p></div>
<div class="stats-grid">
<?php foreach([['audit_logs','Security Activity'],['privacy_consents','Privacy Consents'],['system_jobs','Background Jobs'],['system_backups','Backups'],['employee_archive','Archived Employees']] as $x): ?><div class="stat-card"><div class="stat-icon">✓</div><div><div class="stat-value"><?= $counts[$x[0]] ?></div><div class="stat-label"><?= $x[1] ?></div></div></div><?php endforeach; ?>
</div>
<div class="card"><div class="card-header"><div><h3>Security Activity Monitor</h3><p class="text-muted">Records who performed an action, what module/entity was affected, when it happened, and the source IP.</p></div></div><div class="table-wrap"><table><thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP Address</th></tr></thead><tbody>
<?php if(!$logs): ?><tr><td colspan="6" class="empty-state">No security activity recorded yet.</td></tr><?php endif; ?>
<?php foreach($logs as $l): ?><tr><td><?= htmlspecialchars(date('M d, Y h:i A',strtotime($l['created_at']))) ?></td><td><?= htmlspecialchars($l['actor']) ?></td><td><span class="badge badge-info"><?= htmlspecialchars($l['action']) ?></span></td><td><?= htmlspecialchars($l['entity'].' #'.($l['entity_id']??'—')) ?></td><td><?= htmlspecialchars($l['details']??'') ?></td><td><?= htmlspecialchars($l['ip_address']??'—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card"><h3>Implemented controls</h3><ul class="compliance-list"><li>✅ Session hardening and CSRF protection</li><li>✅ Role-based administrative access</li><li>✅ Security activity/audit logging</li><li>✅ Employee archive history</li><li>✅ Privacy consent and request records</li><li>⚠️ HTTPS/TLS depends on the production web server certificate</li></ul></div>
<div class="card"><h3>Privacy consent</h3><form method="post"><?= csrf_field() ?><button class="btn btn-primary" name="consent" value="1">Record Consent</button></form></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>