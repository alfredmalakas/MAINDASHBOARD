<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireRole('HR Administrator');

$pageTitle = 'Employee Management';

$search = trim($_GET['q'] ?? '');
$sql = "SELECT e.*, d.department_name, p.position_title FROM employees e
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN positions p ON e.position_id = p.position_id";
$params = [];
if ($search !== '') {
    $sql .= " WHERE e.employee_id LIKE ? OR e.first_name LIKE ? OR e.middle_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR d.department_name LIKE ? OR p.position_title LIKE ?";
    $like = "%$search%";
    $params = [$like, $like, $like, $like, $like, $like, $like];
}
$sql .= " ORDER BY e.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>All Employees <span id="employeeCount">(<?= count($employees) ?>)</span></h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><a href="<?= siteUrl('modules/employees/add.php') ?>" class="btn btn-primary">+ Add New Employee</a><a href="<?= siteUrl('modules/employees/archive.php') ?>" class="btn btn-secondary">Archive</a></div>
    </div>

    <form method="GET" class="toolbar">
        <div class="employee-search-wrap">
            <div class="search-box">
                <input type="search" id="employeeSearch" name="q"
                       placeholder="Search employee name, ID, email, department, or position..."
                       value="<?= htmlspecialchars($search) ?>"
                       autocomplete="off" aria-label="Search employees">
            </div>
        </div>
        <button type="submit" class="btn btn-secondary">Search</button>
        <?php if ($search): ?><a href="<?= siteUrl('modules/employees/list.php') ?>" class="btn btn-secondary">Clear</a><?php endif; ?>
    </form>

    <div class="table-wrap">
    <table id="dataTable">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Position</th>
                <th>Status</th>
                <th>Date Hired</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$employees): ?>
            <tr><td colspan="6" class="empty-state"><div class="icon">👥</div>No employees found.</td></tr>
        <?php endif; ?>
        <?php foreach ($employees as $emp): ?>
            <tr class="employee-row" data-search="<?= htmlspecialchars(strtolower($emp['employee_id'].' '.$emp['first_name'].' '.($emp['middle_name'] ?? '').' '.$emp['last_name'].' '.($emp['email'] ?? '').' '.($emp['department_name'] ?? '').' '.($emp['position_title'] ?? '')), ENT_QUOTES) ?>">
                <td>
                    <div class="employee-cell">
                        <img src="<?= assetUrl(htmlspecialchars($emp['photo'])) ?>" class="avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($emp['first_name'].' '.$emp['last_name']) ?>&background=e8f1f8&color=2c5f8a'">
                        <div>
                            <div class="emp-name"><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']) ?></div>
                            <div class="emp-id"><?= htmlspecialchars($emp['employee_id']) ?></div>
                        </div>
                    </div>
                </td>
                <td><?= htmlspecialchars($emp['department_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($emp['position_title'] ?? '—') ?></td>
                <td>
                    <?php
                    $statusClass = match($emp['employment_status']) {
                        'Regular' => 'badge-success',
                        'Resigned','Terminated' => 'badge-error',
                        default => 'badge-warning'
                    };
                    ?>
                    <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($emp['employment_status']) ?></span>
                </td>
                <td><?= htmlspecialchars($emp['date_hired']) ?></td>
                <td>
                    <a href="<?= siteUrl('modules/employees/view.php?id=' . urlencode($emp['employee_id'])) ?>" class="btn btn-secondary btn-sm">View</a>
                    <a href="<?= siteUrl('modules/employees/edit.php?id=' . urlencode($emp['employee_id'])) ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <form method="POST" action="<?= siteUrl('modules/employees/delete.php') ?>" style="display:inline" onsubmit="return confirm('Move this employee to Archive?');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= htmlspecialchars($emp['employee_id']) ?>"><button type="submit" class="btn btn-danger btn-sm">Archive</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr id="employeeLiveEmpty" class="no-live-results"><td colspan="6" class="empty-state">No employees match your search.</td></tr>
        </tbody>
    </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('employeeSearch');
    const rows = Array.from(document.querySelectorAll('.employee-row'));
    const count = document.getElementById('employeeCount');
    const empty = document.getElementById('employeeLiveEmpty');

    function filterEmployees() {
        const query = (input.value || '').trim().toLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const match = !query || (row.dataset.search || '').includes(query);
            row.classList.toggle('is-hidden', !match);
            if (match) visible++;
        });
        if (count) count.textContent = '(' + visible + ')';
        if (empty) empty.classList.toggle('visible', visible === 0);
    }

    if (input) {
        input.addEventListener('input', filterEmployees);
        filterEmployees();
    }
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
