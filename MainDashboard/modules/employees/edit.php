<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireRole('HR Administrator');

$pageTitle = 'Edit Employee';
$id = $_GET['id'] ?? '';
$errors = [];

$stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp) {
    flash('error', 'Employee not found.');
    redirect('/modules/employees/list.php');
}

$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$positions   = $pdo->query("SELECT * FROM positions ORDER BY position_title")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '')  $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

    if (!$errors) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE email = ? AND employee_id != ?");
        $check->execute([$email, $id]);
        if ($check->fetchColumn() > 0) $errors[] = 'Another employee already uses this email.';
    }

    $photoPath = $emp['photo'];
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
        $allowed = ['jpg','jpeg','png','gif'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $filename = $id . '_' . time() . '.' . $ext;
            $destination = __DIR__ . '/../../assets/uploads/' . $filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                $photoPath = 'assets/uploads/' . $filename;
            }
        } else {
            $errors[] = 'Photo must be JPG, PNG, or GIF.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare("UPDATE employees SET
            first_name=?, middle_name=?, last_name=?, gender=?, date_of_birth=?, address=?, contact_number=?, email=?,
            department_id=?, position_id=?, employment_status=?, date_hired=?, emergency_contact_name=?, emergency_contact_number=?, photo=?, basic_salary=?
            WHERE employee_id=?");
        $stmt->execute([
            $first_name,
            trim($_POST['middle_name'] ?? ''),
            $last_name,
            $_POST['gender'],
            $_POST['date_of_birth'],
            trim($_POST['address'] ?? ''),
            trim($_POST['contact_number'] ?? ''),
            $email,
            $_POST['department_id'] ?: null,
            $_POST['position_id'] ?: null,
            $_POST['employment_status'],
            $_POST['date_hired'],
            trim($_POST['emergency_contact_name'] ?? ''),
            trim($_POST['emergency_contact_number'] ?? ''),
            $photoPath,
            $_POST['basic_salary'] ?: 0,
            $id
        ]);
        audit($pdo,'EMPLOYEE_UPDATED','employees',$id,'Employee profile updated');
        flash('success', 'Employee updated successfully.');
        redirect('/modules/employees/view.php?id=' . urlencode($id));
    } else {
        $emp = array_merge($emp, $_POST);
    }
}
include __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Edit Employee — <?= htmlspecialchars($emp['employee_id']) ?></h2></div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>First Name *</label>
                <input type="text" name="first_name" value="<?= htmlspecialchars($emp['first_name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Middle Name</label>
                <input type="text" name="middle_name" value="<?= htmlspecialchars($emp['middle_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Last Name *</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($emp['last_name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Gender *</label>
                <select name="gender" required>
                    <?php foreach (['Male','Female','Other'] as $g): ?>
                        <option value="<?= $g ?>" <?= $emp['gender']===$g?'selected':'' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Date of Birth *</label>
                <input type="date" name="date_of_birth" value="<?= htmlspecialchars($emp['date_of_birth']) ?>" required>
            </div>
            <div class="form-group">
                <label>Contact Number</label>
                <input type="text" name="contact_number" value="<?= htmlspecialchars($emp['contact_number'] ?? '') ?>">
            </div>
            <div class="form-group full-width">
                <label>Address</label>
                <input type="text" name="address" value="<?= htmlspecialchars($emp['address'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" value="<?= htmlspecialchars($emp['email']) ?>" required>
            </div>
            <div class="form-group">
                <label>Photo (leave blank to keep current)</label>
                <input type="file" name="photo" accept="image/*">
            </div>
            <div class="form-group">
                <label>Department</label>
                <select name="department_id">
                    <option value="">-- Select --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= $emp['department_id']==$d['department_id']?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Position</label>
                <select name="position_id">
                    <option value="">-- Select --</option>
                    <?php foreach ($positions as $p): ?>
                        <option value="<?= $p['position_id'] ?>" <?= $emp['position_id']==$p['position_id']?'selected':'' ?>><?= htmlspecialchars($p['position_title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Employment Status *</label>
                <select name="employment_status" required>
                    <?php foreach (['Probationary','Regular','Contractual','Part-Time','Resigned','Terminated'] as $s): ?>
                        <option value="<?= $s ?>" <?= $emp['employment_status']===$s?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Date Hired *</label>
                <input type="date" name="date_hired" value="<?= htmlspecialchars($emp['date_hired']) ?>" required>
            </div>
            <div class="form-group">
                <label>Basic Salary (₱)</label>
                <input type="number" step="0.01" name="basic_salary" value="<?= htmlspecialchars($emp['basic_salary']) ?>">
            </div>
            <div class="form-group">
                <label>Emergency Contact Name</label>
                <input type="text" name="emergency_contact_name" value="<?= htmlspecialchars($emp['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Emergency Contact Number</label>
                <input type="text" name="emergency_contact_number" value="<?= htmlspecialchars($emp['emergency_contact_number'] ?? '') ?>">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Update Employee</button>
            <a href="<?= siteUrl('modules/employees/view.php?id=' . urlencode($emp['employee_id'])) ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
