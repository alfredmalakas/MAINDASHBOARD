<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireRole('HR Administrator');

$pageTitle='Employee Login Access';
$errors=[];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=$_POST['action'] ?? '';
    $employeeId=$_POST['employee_id'] ?? '';
    if ($action==='save') {
        $username=trim($_POST['username'] ?? '');
        $password=$_POST['password'] ?? '';
        $status=$_POST['status'] ?? 'Active';
        if (!$employeeId || !$username) $errors[]='Employee and username are required.';
        if ($password!=='' && strlen($password)<6) $errors[]='Password must be at least 6 characters.';
        if (!$errors) {
            $check=$pdo->prepare("SELECT user_id FROM users WHERE username=? AND employee_id<>?");
            $check->execute([$username,$employeeId]);
            if ($check->fetch()) $errors[]='Username is already in use.';
            else {
                $existing=$pdo->prepare("SELECT user_id FROM users WHERE employee_id=?");
                $existing->execute([$employeeId]); $u=$existing->fetch();
                if ($u) {
                    if ($password!=='') {
                        $stmt=$pdo->prepare("UPDATE users SET username=?, password=?, status=? WHERE user_id=?");
                        $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT),$status,$u['user_id']]);
                    } else {
                        $stmt=$pdo->prepare("UPDATE users SET username=?, status=? WHERE user_id=?");
                        $stmt->execute([$username,$status,$u['user_id']]);
                    }
                } else {
                    if ($password==='') $password='password123';
                    $stmt=$pdo->prepare("INSERT INTO users (employee_id,username,password,role,status) VALUES (?,?,?,'Employee',?)");
                    $stmt->execute([$employeeId,$username,password_hash($password,PASSWORD_DEFAULT),$status]);
                }
                audit($pdo,'LOGIN_ACCESS_SAVED','users',$u['user_id'] ?? null,"Login access updated for {$employeeId}");
                flash('success','Employee login access saved successfully.');
                redirect('/modules/users/access.php');
            }
        }
    } elseif ($action==='toggle') {
        $userId=(int)($_POST['user_id'] ?? 0);
        $stmt=$pdo->prepare("UPDATE users SET status=IF(status='Active','Inactive','Active') WHERE user_id=? AND role='Employee'");
        $stmt->execute([$userId]);
        audit($pdo,'LOGIN_ACCESS_TOGGLED','users',$userId,'Employee login access status changed');
        flash('success','Login access status updated.');
        redirect('/modules/users/access.php');
    }
}

$employees=$pdo->query("SELECT e.employee_id,e.first_name,e.last_name,e.email,e.employment_status,u.user_id,u.username,u.status AS login_status
                        FROM employees e LEFT JOIN users u ON e.employee_id=u.employee_id
                        WHERE e.employment_status NOT IN ('Resigned','Terminated') ORDER BY e.first_name,e.last_name")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <div><h2>Employee Login Access</h2><p class="text-muted">Admin can create, update, activate, or deactivate employee portal accounts.</p></div>
    </div>
    <?php if ($errors): ?><div class="alert alert-error"><?= htmlspecialchars(implode(' ',$errors)) ?></div><?php endif; ?>
    <form method="POST" class="form-grid" style="margin-bottom:25px;">
        <input type="hidden" name="action" value="save"><?= csrf_field() ?>
        <div class="form-group"><label>Employee *</label>
            <select name="employee_id" required>
                <option value="">-- Select Employee --</option>
                <?php foreach($employees as $e): ?>
                <option value="<?= htmlspecialchars($e['employee_id']) ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name'].' — '.$e['employee_id']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>Username *</label><input name="username" placeholder="e.g. juan.santos" required></div>
        <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="Leave blank to keep existing / use password123 for new"></div>
        <div class="form-group"><label>Status</label><select name="status"><option>Active</option><option>Inactive</option></select></div>
        <div style="grid-column:1/-1"><button class="btn btn-primary">Save Login Access</button></div>
    </form>

    <div class="table-wrap"><table>
        <thead><tr><th>Employee</th><th>Username</th><th>Login Status</th><th>Employment</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($employees as $e): ?>
        <tr>
            <td><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?><br><span class="text-muted"><?= htmlspecialchars($e['employee_id']) ?></span></td>
            <td><?= htmlspecialchars($e['username'] ?? '—') ?></td>
            <td><?= $e['user_id'] ? '<span class="badge '.($e['login_status']==='Active'?'badge-success':'badge-error').'">'.htmlspecialchars($e['login_status']).'</span>' : '<span class="badge badge-warning">No Account</span>' ?></td>
            <td><?= htmlspecialchars($e['employment_status']) ?></td>
            <td>
            <?php if($e['user_id']): ?>
                <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$e['user_id'] ?>"><button class="btn btn-secondary btn-sm"><?= $e['login_status']==='Active'?'Deactivate':'Activate' ?></button></form>
            <?php else: ?><span class="text-muted">Use form above</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
