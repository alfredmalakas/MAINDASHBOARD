<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireRole('HR Administrator');

$pageTitle = 'Add New Employee';
$errors = [];
$formData = [];

$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$positions   = $pdo->query("SELECT * FROM positions WHERE status='Active' ORDER BY position_title")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = $_POST;

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $gender     = $_POST['gender'] ?? '';
    $dob        = $_POST['date_of_birth'] ?? '';
    $email      = trim($_POST['email'] ?? '');
    $date_hired = $_POST['date_hired'] ?? '';

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '')  $errors[] = 'Last name is required.';
    if ($gender === '')     $errors[] = 'Gender is required.';
    if ($dob === '')        $errors[] = 'Date of birth is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if ($date_hired === '') $errors[] = 'Date hired is required.';

    // Check for duplicate email
    if (!$errors) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetchColumn() > 0) {
            $errors[] = 'An employee with this email already exists.';
        }
    }

    // Handle photo upload
    $photoPath = 'assets/uploads/default.png';
    if (!empty($_FILES['photo']['name'])) {
        $allowed = ['jpg','jpeg','png','gif'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Photo must be JPG, PNG, or GIF.';
        }
    }

    if (!$errors) {
        // Generate Employee ID: EMP-YYYY-NNNN
        $year = date('Y');
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_id LIKE ?");
        $countStmt->execute(["EMP-$year-%"]);
        $nextNum = $countStmt->fetchColumn() + 1;
        $employee_id = sprintf("EMP-%s-%04d", $year, $nextNum);

        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $filename = $employee_id . '_' . time() . '.' . $ext;
            $destination = __DIR__ . '/../../assets/uploads/' . $filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                $photoPath = 'assets/uploads/' . $filename;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO employees
            (employee_id, first_name, middle_name, last_name, gender, date_of_birth, address, contact_number, email,
             department_id, position_id, employment_status, date_hired, emergency_contact_name, emergency_contact_number, photo, basic_salary)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $employee_id,
            $first_name,
            trim($_POST['middle_name'] ?? ''),
            $last_name,
            $gender,
            $dob,
            trim($_POST['address'] ?? ''),
            trim($_POST['contact_number'] ?? ''),
            $email,
            $_POST['department_id'] ?: null,
            $_POST['position_id'] ?: null,
            $_POST['employment_status'] ?? 'Probationary',
            $date_hired,
            trim($_POST['emergency_contact_name'] ?? ''),
            trim($_POST['emergency_contact_number'] ?? ''),
            $photoPath,
            $_POST['basic_salary'] ?: 0,
        ]);

        // Seed leave balances for current year
        $currentYear = date('Y');
        $leaveTypes = [];
        $settingsStmt = $pdo->query("SELECT leave_type, default_days FROM leave_settings WHERE allow_employee_apply=1");
        foreach ($settingsStmt->fetchAll() as $setting) $leaveTypes[$setting['leave_type']] = (int)$setting['default_days'];
        $balStmt = $pdo->prepare("INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES (?,?,?,?,0)");
        foreach ($leaveTypes as $type => $days) {
            $balStmt->execute([$employee_id, $type, $currentYear, $days]);
        }

        flash('success', "Employee $employee_id added successfully.");
        redirect('/modules/employees/list.php');
    }
}
include __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Add New Employee</h2></div>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-group">
                <label>First Name *</label>
                <input type="text" name="first_name" value="<?= htmlspecialchars($formData['first_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Middle Name</label>
                <input type="text" name="middle_name" value="<?= htmlspecialchars($formData['middle_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Last Name *</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($formData['last_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Gender *</label>
                <select name="gender" required>
                    <option value="">-- Select --</option>
                    <?php foreach (['Male','Female','Other'] as $g): ?>
                        <option value="<?= $g ?>" <?= ($formData['gender'] ?? '')===$g?'selected':'' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Date of Birth *</label>
                <input type="date" name="date_of_birth" value="<?= htmlspecialchars($formData['date_of_birth'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Contact Number</label>
                <input type="text" name="contact_number" value="<?= htmlspecialchars($formData['contact_number'] ?? '') ?>" placeholder="09XXXXXXXXX">
            </div>
            <div class="form-group full-width">
                <label>Address</label>
                <input type="text" name="address" value="<?= htmlspecialchars($formData['address'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" value="<?= htmlspecialchars($formData['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Photo</label>
                <input type="file" name="photo" accept="image/*">
            </div>
            <div class="form-group">
                <label>Department</label>
                <select name="department_id">
                    <option value="">-- Select --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= ($formData['department_id'] ?? '')==$d['department_id']?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Position</label>
                <select name="position_id">
                    <option value="">-- Select --</option>
                    <?php foreach ($positions as $p): ?>
                        <option value="<?= $p['position_id'] ?>" data-description="<?= htmlspecialchars($p['description'] ?? '', ENT_QUOTES) ?>" <?= ($formData['position_id'] ?? '')==$p['position_id']?'selected':'' ?>><?= htmlspecialchars($p['position_title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full-width">
                <label>Position Description</label>
                <div id="positionDescription" class="alert" style="margin:0;">Select a position to view its description.</div>
            </div>
            <div class="form-group">
                <label>Employment Status *</label>
                <select name="employment_status" required>
                    <?php foreach (['Probationary','Regular','Contractual','Part-Time'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($formData['employment_status'] ?? '')===$s?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Date Hired *</label>
                <input type="date" name="date_hired" value="<?= htmlspecialchars($formData['date_hired'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Basic Salary (₱)</label>
                <input type="number" step="0.01" name="basic_salary" value="<?= htmlspecialchars($formData['basic_salary'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Emergency Contact Name</label>
                <input type="text" name="emergency_contact_name" value="<?= htmlspecialchars($formData['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Emergency Contact Number</label>
                <input type="text" name="emergency_contact_number" value="<?= htmlspecialchars($formData['emergency_contact_number'] ?? '') ?>">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Employee</button>
            <a href="<?= siteUrl('modules/employees/list.php') ?>" class="btn btn-secondary">Cancel</a>
        </div>
    <script>
document.addEventListener('DOMContentLoaded',function(){const sel=document.querySelector('select[name="position_id"]'), box=document.getElementById('positionDescription'); function update(){const o=sel&&sel.options[sel.selectedIndex]; box.textContent=o&&o.value?(o.dataset.description||'No description provided.'):'Select a position to view its description.';} if(sel){sel.addEventListener('change',update);update();}});
</script>
    </form>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
