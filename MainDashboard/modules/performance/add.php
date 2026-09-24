<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();
if (!isSuperAdmin()) { redirect('/modules/performance/list.php?error=unauthorized'); }
require_once __DIR__ . '/rubric.php';

$pageTitle = 'New Performance Evaluation';
$errors = [];
$employees = $pdo->query("SELECT * FROM employees WHERE employment_status NOT IN ('Resigned','Terminated') ORDER BY first_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeId = $_POST['employee_id'] ?? '';
    $period = trim($_POST['evaluation_period'] ?? '');
    $evalDate = $_POST['evaluation_date'] ?? '';
    $strengths = trim($_POST['strengths'] ?? '');
    $improve = trim($_POST['areas_for_improvement'] ?? '');
    $comments = trim($_POST['supervisor_comments'] ?? '');
    $scores = $_POST['scores'] ?? [];
    $flatScores = [];

    foreach ($performanceRubric as $category => $criteria) {
        foreach ($criteria as $i => $criterion) {
            $key = $category . '|' . $i;
            $value = isset($scores[$key]) ? (int)$scores[$key] : 0;
            if ($value < 1 || $value > 5) {
                $errors[] = 'Please rate every performance criterion from 1 to 5.';
                break 2;
            }
            $flatScores[$key] = $value;
        }
    }

    if (!$employeeId) $errors[] = 'Please select an employee.';
    if ($period === '') $errors[] = 'Evaluation period is required.';
    if (!$evalDate) $errors[] = 'Evaluation date is required.';

    if (!$errors) {
        $average = array_sum($flatScores) / count($flatScores);
        $kpiScore = round(($average / 5) * 100, 2);
        $rating = performanceRatingFromScore($average);

        $stmt = $pdo->prepare("INSERT INTO performance
            (employee_id, evaluator_id, evaluation_period, kpi_score, rating, strengths, areas_for_improvement, supervisor_comments, evaluation_date, rubric_scores)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $employeeId, $_SESSION['user_id'], $period, $kpiScore, $rating,
            $strengths, $improve, $comments, $evalDate, json_encode($flatScores)
        ]);
        audit($pdo,'PERFORMANCE_EVALUATED','performance',$pdo->lastInsertId(),"Super Admin evaluated {$employeeId} for {$period}");
        flash('success', 'Performance evaluation saved. Overall score: ' . number_format($kpiScore, 2) . '% (' . $rating . ').');
        redirect('/modules/performance/list.php');
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <div>
            <h2>Employee Performance Evaluation</h2>
            <p class="text-muted">Only the Super Admin can create the official evaluation. Rate each criterion from 1 to 5.</p>
        </div>
    </div>
    <?php if ($errors): ?>
        <div class="alert alert-error"><?= htmlspecialchars(implode(' ', array_unique($errors))) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>Employee *</label>
                <select name="employee_id" required>
                    <option value="">-- Select Employee --</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= htmlspecialchars($e['employee_id']) ?>" <?= ($_POST['employee_id'] ?? '')===$e['employee_id']?'selected':'' ?>>
                            <?= htmlspecialchars($e['first_name'].' '.$e['last_name'].' ('.$e['employee_id'].')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Evaluation Period *</label>
                <input type="text" name="evaluation_period" placeholder="e.g. Q2 2026" value="<?= htmlspecialchars($_POST['evaluation_period'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Evaluation Date *</label>
                <input type="date" name="evaluation_date" value="<?= htmlspecialchars($_POST['evaluation_date'] ?? date('Y-m-d')) ?>" required>
            </div>
        </div>

        <div class="alert alert-info">
            <strong>Rating Guide:</strong> 1 = Needs Significant Improvement &nbsp; 2 = Needs Improvement &nbsp; 3 = Meets Expectations &nbsp; 4 = Very Good &nbsp; 5 = Exceptional.
        </div>

        <?php foreach ($performanceRubric as $category => $criteria): ?>
            <div class="card" style="margin:18px 0;border:1px solid #e5e7eb;box-shadow:none;">
                <div class="card-header"><h3><?= htmlspecialchars($category) ?></h3></div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th style="width:60%;">Criteria</th><th class="text-center">1</th><th class="text-center">2</th><th class="text-center">3</th><th class="text-center">4</th><th class="text-center">5</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($criteria as $i => $criterion):
                            $key = $category . '|' . $i;
                            $selected = $_POST['scores'][$key] ?? '';
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($criterion) ?></td>
                                <?php for ($score=1; $score<=5; $score++): ?>
                                <td class="text-center">
                                    <input type="radio" name="scores[<?= htmlspecialchars($key) ?>]" value="<?= $score ?>" <?= (string)$selected===(string)$score?'checked':'' ?> required>
                                </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="form-grid">
            <div class="form-group">
                <label>Strengths</label>
                <textarea name="strengths" rows="3" placeholder="Key strengths observed"><?= htmlspecialchars($_POST['strengths'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Areas for Improvement</label>
                <textarea name="areas_for_improvement" rows="3" placeholder="Development areas"><?= htmlspecialchars($_POST['areas_for_improvement'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="form-group">
            <label>Supervisor Comments</label>
            <textarea name="supervisor_comments" rows="3" placeholder="Overall comments and recommendations"><?= htmlspecialchars($_POST['supervisor_comments'] ?? '') ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Evaluation</button>
            <a href="<?= siteUrl('modules/performance/list.php') ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
