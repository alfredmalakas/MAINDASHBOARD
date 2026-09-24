<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/rubric.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT pf.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code,
                       CONCAT(COALESCE(ev.first_name,''),' ',COALESCE(ev.last_name,'')) AS evaluator_name
                       FROM performance pf
                       JOIN employees e ON pf.employee_id=e.employee_id
                       LEFT JOIN users u ON pf.evaluator_id=u.user_id
                       LEFT JOIN employees ev ON u.employee_id=ev.employee_id
                       WHERE pf.performance_id=?");
$stmt->execute([$id]);
$ev = $stmt->fetch();

if (!$ev || (!isAdmin() && $ev['employee_id'] !== currentEmployeeId())) {
    flash('error','Performance evaluation not found or access denied.');
    redirect('/modules/performance/list.php');
}
$pageTitle='Performance Evaluation Details';
$scores = json_decode($ev['rubric_scores'] ?? '{}', true) ?: [];

function scoreLabel($score) {
    return [1=>'Needs Significant Improvement',2=>'Needs Improvement',3=>'Meets Expectations',4=>'Very Good',5=>'Exceptional'][$score] ?? '—';
}
?>
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="card">
    <div class="card-header">
        <div>
            <h2><?= htmlspecialchars($ev['emp_name']) ?></h2>
            <p class="text-muted"><?= htmlspecialchars($ev['emp_code']) ?> · <?= htmlspecialchars($ev['evaluation_period']) ?></p>
        </div>
        <div>
            <strong style="font-size:28px;"><?= number_format($ev['kpi_score'],2) ?>%</strong><br>
            <span class="badge badge-success"><?= htmlspecialchars($ev['rating']) ?></span>
        </div>
    </div>

    <?php foreach ($performanceRubric as $category => $criteria):
        $categoryScores=[];
        foreach ($criteria as $i=>$criterion) {
            $categoryScores[]=(int)($scores[$category.'|'.$i] ?? 0);
        }
        $catAvg = $categoryScores ? array_sum($categoryScores)/count($categoryScores) : 0;
    ?>
    <div class="card" style="margin:18px 0;border:1px solid #e5e7eb;box-shadow:none;">
        <div class="card-header">
            <h3><?= htmlspecialchars($category) ?></h3>
            <span class="badge badge-info">Average: <?= number_format($catAvg,2) ?>/5</span>
        </div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Criteria</th><th>Score</th><th>Rating</th></tr></thead>
            <tbody>
            <?php foreach ($criteria as $i=>$criterion):
                $score=(int)($scores[$category.'|'.$i] ?? 0);
            ?>
                <tr>
                    <td><?= htmlspecialchars($criterion) ?></td>
                    <td><strong><?= $score ?>/5</strong></td>
                    <td><?= htmlspecialchars(scoreLabel($score)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="form-grid">
        <div class="card" style="box-shadow:none;border:1px solid #e5e7eb;"><h3>Strengths</h3><p><?= nl2br(htmlspecialchars($ev['strengths'] ?: '—')) ?></p></div>
        <div class="card" style="box-shadow:none;border:1px solid #e5e7eb;"><h3>Areas for Improvement</h3><p><?= nl2br(htmlspecialchars($ev['areas_for_improvement'] ?: '—')) ?></p></div>
    </div>
    <div class="card" style="box-shadow:none;border:1px solid #e5e7eb;margin-top:18px;">
        <h3>Supervisor Comments</h3>
        <p><?= nl2br(htmlspecialchars($ev['supervisor_comments'] ?: '—')) ?></p>
        <p class="text-muted">Evaluated on <?= htmlspecialchars($ev['evaluation_date']) ?><?= $ev['evaluator_name'] ? ' by '.htmlspecialchars(trim($ev['evaluator_name'])) : '' ?>.</p>
    </div>
    <a href="<?= siteUrl('modules/performance/list.php') ?>" class="btn btn-secondary">Back to Evaluations</a>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
