<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireRole('HR Administrator');
$pageTitle='Position Management';
csrf_token();
$errors=[];

try {
    $pdo->exec("ALTER TABLE positions ADD COLUMN description VARCHAR(500) NULL, ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'");
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { $errors[]='Invalid security token.'; }
    $action=$_POST['action'] ?? '';
    if (!$errors && $action==='save') {
        $id=(int)($_POST['position_id'] ?? 0);
        $title=trim($_POST['position_title'] ?? '');
        $desc=trim($_POST['description'] ?? '');
        $dept=$_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
        $salary=(float)($_POST['base_salary'] ?? 0);
        $status=$_POST['status'] ?? 'Active';
        if ($title==='') $errors[]='Position title is required.';
        if (!in_array($status,['Active','Inactive'],true)) $status='Active';
        if (!$errors) {
            if ($id) {
                $s=$pdo->prepare('UPDATE positions SET position_title=?,description=?,department_id=?,base_salary=?,status=? WHERE position_id=?');
                $s->execute([$title,$desc,$dept,$salary,$status,$id]);
                audit($pdo,'POSITION_UPDATED','positions',$id,'Position updated: '.$title);
            } else {
                $s=$pdo->prepare('INSERT INTO positions(position_title,description,department_id,base_salary,status) VALUES(?,?,?,?,?)');
                $s->execute([$title,$desc,$dept,$salary,$status]);
                audit($pdo,'POSITION_CREATED','positions',$pdo->lastInsertId(),'Position created: '.$title);
            }
            flash('success','Position saved successfully.'); redirect('/modules/employees/positions.php');
        }
    } elseif (!$errors && $action==='toggle') {
        $id=(int)$_POST['position_id'];
        $s=$pdo->prepare("UPDATE positions SET status=IF(status='Active','Inactive','Active') WHERE position_id=?"); $s->execute([$id]);
        audit($pdo,'POSITION_STATUS_CHANGED','positions',$id,'Position status changed');
        flash('success','Position status updated.'); redirect('/modules/employees/positions.php');
    }
}
$departments=$pdo->query('SELECT department_id,department_name FROM departments ORDER BY department_name')->fetchAll();
$positions=$pdo->query('SELECT p.*,d.department_name FROM positions p LEFT JOIN departments d ON d.department_id=p.department_id ORDER BY p.status DESC,p.position_title')->fetchAll();
$edit=null;
if (isset($_GET['edit'])) { $s=$pdo->prepare('SELECT * FROM positions WHERE position_id=?');$s->execute([(int)$_GET['edit']]);$edit=$s->fetch(); }
include __DIR__.'/../../includes/header.php';
?>
<div class="card">
 <div class="card-header"><h2><?= $edit?'Edit Position':'Add Position' ?></h2></div>
 <?php if($errors): ?><div class="alert alert-error"><?php foreach($errors as $e) echo htmlspecialchars($e).'<br>'; ?></div><?php endif; ?>
 <form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="position_id" value="<?= (int)($edit['position_id']??0) ?>">
  <div class="form-grid">
   <div class="form-group"><label>Position Title *</label><input name="position_title" required value="<?= htmlspecialchars($edit['position_title']??'') ?>"></div>
   <div class="form-group"><label>Department</label><select name="department_id"><option value="">-- Select --</option><?php foreach($departments as $d): ?><option value="<?=$d['department_id']?>" <?= (($edit['department_id']??'')==$d['department_id'])?'selected':'' ?>><?=htmlspecialchars($d['department_name'])?></option><?php endforeach; ?></select></div>
   <div class="form-group"><label>Base Salary (₱)</label><input type="number" step="0.01" name="base_salary" value="<?=htmlspecialchars($edit['base_salary']??'0')?>"></div>
   <div class="form-group"><label>Status</label><select name="status"><option <?=($edit['status']??'Active')==='Active'?'selected':''?>>Active</option><option <?=($edit['status']??'')==='Inactive'?'selected':''?>>Inactive</option></select></div>
   <div class="form-group full-width"><label>Position Description</label><textarea name="description" rows="3" placeholder="Describe the responsibilities, qualifications, and scope of this position."><?=htmlspecialchars($edit['description']??'')?></textarea></div>
  </div>
  <div class="form-actions"><button class="btn btn-primary">Save Position</button><?php if($edit): ?><a class="btn btn-secondary" href="<?=siteUrl('modules/employees/positions.php')?>">Cancel</a><?php endif; ?></div>
 </form>
</div>
<div class="card"><div class="card-header"><h2>Positions</h2></div><div class="table-wrap"><table><thead><tr><th>Position</th><th>Description</th><th>Department</th><th>Base Salary</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($positions as $p): ?><tr><td><strong><?=htmlspecialchars($p['position_title'])?></strong></td><td><?=htmlspecialchars($p['description']??'—')?></td><td><?=htmlspecialchars($p['department_name']??'—')?></td><td>₱<?=number_format((float)$p['base_salary'],2)?></td><td><span class="badge <?=$p['status']==='Active'?'badge-success':'badge-error'?>"><?=htmlspecialchars($p['status'])?></span></td><td><a class="btn btn-secondary btn-sm" href="?edit=<?=$p['position_id']?>">Edit</a> <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token']??'')?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="position_id" value="<?=$p['position_id']?>"><button class="btn btn-secondary btn-sm"><?= $p['status']==='Active'?'Deactivate':'Activate' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
