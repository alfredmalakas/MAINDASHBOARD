<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireLogin();
$pageTitle='Employee Contracts & Agreements';
csrf_token();
$admin=isAdmin(); $employeeId=currentEmployeeId(); $errors=[];

$pdo->exec("CREATE TABLE IF NOT EXISTS employee_contracts (
 contract_id BIGINT AUTO_INCREMENT PRIMARY KEY,
 employee_id VARCHAR(20) NOT NULL, contract_type VARCHAR(80) NOT NULL DEFAULT 'Employment Agreement',
 start_date DATE NOT NULL, end_date DATE NULL, salary DECIMAL(10,2) DEFAULT 0.00,
 terms TEXT NOT NULL, status ENUM('Draft','Pending Agreement','Agreed','Rejected','Expired') NOT NULL DEFAULT 'Draft',
 created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 agreed_by_employee_at DATETIME NULL, employee_agreement_name VARCHAR(150) NULL,
 INDEX idx_contract_employee(employee_id), INDEX idx_contract_status(status),
 FOREIGN KEY(employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB");

if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) $errors[]='Invalid security token.';
 $action=$_POST['action']??'';
 if (!$errors && $admin && $action==='create') {
   $eid=trim($_POST['employee_id']??''); $type=trim($_POST['contract_type']??'Employment Agreement'); $start=$_POST['start_date']??''; $end=$_POST['end_date']?:null; $salary=(float)($_POST['salary']??0); $terms=trim($_POST['terms']??'');
   if($eid===''||$start===''||$terms==='') $errors[]='Employee, start date, and agreement terms are required.';
   if(!$errors){$s=$pdo->prepare('INSERT INTO employee_contracts(employee_id,contract_type,start_date,end_date,salary,terms,status,created_by) VALUES(?,?,?,?,?,?,?,?)');$s->execute([$eid,$type,$start,$end,$salary,$terms,'Pending Agreement',$_SESSION['user_id']]);audit($pdo,'CONTRACT_CREATED','employee_contracts',$pdo->lastInsertId(),'HR created employee contract');flash('success','Employee contract created and sent for agreement.');redirect('/modules/employees/contracts.php');}
 } elseif(!$errors && !$admin && $action==='agree') {
   $id=(int)$_POST['contract_id']; $name=trim($_POST['agreement_name']??'');
   if($name==='') $errors[]='Type your full name to confirm the agreement.';
   if(!$errors){$s=$pdo->prepare("UPDATE employee_contracts SET status='Agreed',agreed_by_employee_at=NOW(),employee_agreement_name=? WHERE contract_id=? AND employee_id=? AND status='Pending Agreement'");$s->execute([$name,$id,$employeeId]);audit($pdo,'CONTRACT_AGREED','employee_contracts',$id,'Employee accepted employment agreement');flash('success','Agreement confirmed successfully.');redirect('/modules/employees/contracts.php');}
 } elseif(!$errors && !$admin && $action==='reject') {
   $id=(int)$_POST['contract_id'];$s=$pdo->prepare("UPDATE employee_contracts SET status='Rejected' WHERE contract_id=? AND employee_id=? AND status='Pending Agreement'");$s->execute([$id,$employeeId]);audit($pdo,'CONTRACT_REJECTED','employee_contracts',$id,'Employee rejected employment agreement');flash('success','Agreement marked as rejected.');redirect('/modules/employees/contracts.php');
 }
}
if($admin){$employees=$pdo->query("SELECT employee_id,first_name,last_name,basic_salary FROM employees WHERE employment_status NOT IN ('Resigned','Terminated') ORDER BY first_name,last_name")->fetchAll();$s=$pdo->query("SELECT c.*,CONCAT(e.first_name,' ',e.last_name) employee_name,u.username creator FROM employee_contracts c JOIN employees e ON e.employee_id=c.employee_id LEFT JOIN users u ON u.user_id=c.created_by ORDER BY c.created_at DESC");$contracts=$s->fetchAll();}
else {$s=$pdo->prepare("SELECT c.*,CONCAT(e.first_name,' ',e.last_name) employee_name FROM employee_contracts c JOIN employees e ON e.employee_id=c.employee_id WHERE c.employee_id=? ORDER BY c.created_at DESC");$s->execute([$employeeId]);$contracts=$s->fetchAll();}
include __DIR__.'/../../includes/header.php';
?>
<div class="card">
 <div class="card-header"><h2>Employment Contract / Agreement</h2></div>
 <?php if($errors): ?><div class="alert alert-error"><?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?></div><?php endif; ?>
 <?php if($admin): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token']??'')?>"><input type="hidden" name="action" value="create"><div class="form-grid">
 <div class="form-group"><label>Employee *</label><select name="employee_id" required><option value="">-- Select Employee --</option><?php foreach($employees as $e): ?><option value="<?=$e['employee_id']?>"><?=htmlspecialchars($e['first_name'].' '.$e['last_name'].' — '.$e['employee_id'])?></option><?php endforeach; ?></select></div>
 <div class="form-group"><label>Contract Type</label><select name="contract_type"><option>Employment Agreement</option><option>Fixed-Term Contract</option><option>Probationary Agreement</option><option>Service Agreement</option></select></div>
 <div class="form-group"><label>Start Date *</label><input type="date" name="start_date" required></div><div class="form-group"><label>End Date</label><input type="date" name="end_date"></div>
 <div class="form-group"><label>Agreed Salary (₱)</label><input type="number" step="0.01" name="salary" value="0"></div>
 <div class="form-group full-width"><label>Agreement Terms *</label><textarea name="terms" rows="6" required placeholder="Duties, working schedule, compensation, confidentiality, company policies, termination terms, and other agreed conditions."></textarea></div>
 </div><button class="btn btn-primary">Create & Send Agreement</button></form><?php else: ?><p class="text-muted">Review your employment agreement below. If it is marked <strong>Pending Agreement</strong>, confirm it by typing your full name.</p><?php endif; ?>
</div>
<div class="card"><div class="card-header"><h2>Contract History</h2></div><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Type</th><th>Period</th><th>Salary</th><th>Status</th><th>Agreement</th></tr></thead><tbody>
<?php foreach($contracts as $c): ?><tr><td><?=htmlspecialchars($c['employee_name'])?></td><td><?=htmlspecialchars($c['contract_type'])?></td><td><?=htmlspecialchars($c['start_date'])?><?= $c['end_date']?' to '.htmlspecialchars($c['end_date']):' — Ongoing' ?></td><td>₱<?=number_format((float)$c['salary'],2)?></td><td><span class="badge <?=in_array($c['status'],['Agreed'],true)?'badge-success':($c['status']==='Rejected'?'badge-error':'badge-warning')?>"><?=htmlspecialchars($c['status'])?></span></td><td><?php if(!$admin && $c['status']==='Pending Agreement'): ?><form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token']??'')?>"><input type="hidden" name="contract_id" value="<?=$c['contract_id']?>"><input type="hidden" name="action" value="agree"><input type="text" name="agreement_name" required placeholder="Full name" style="max-width:150px"><button class="btn btn-primary btn-sm">I Agree</button></form> <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token']??'')?>"><input type="hidden" name="contract_id" value="<?=$c['contract_id']?>"><input type="hidden" name="action" value="reject"><button class="btn btn-secondary btn-sm">Reject</button></form><?php elseif($c['status']==='Agreed'): ?>Agreed <?=htmlspecialchars($c['agreed_by_employee_at']??'')?><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$contracts): ?><tr><td colspan="6">No contracts found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
