<?php
use PantonePredictor\Services\PantoneLabService;
include dirname(__DIR__) . '/layouts/main.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= number_format($totalPredictions) ?></span>
        <span class="stat-label">Saved Predictions</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $totalSeries ?></span>
        <span class="stat-label">Series with Predictions</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $avgConfidence > 0 ? number_format($avgConfidence, 1) . '%' : '—' ?></span>
        <span class="stat-label">Avg Confidence</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($pantoneCount) ?></span>
        <span class="stat-label">PMS Reference Colors</span>
    </div>
</div>

<?php if (!$cmsConfigured): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-state-icon">&#9881;</div>
            <h3>Welcome to Pantone Predictor</h3>
            <p class="text-muted mb-2">Get started by configuring the CMS database connection.</p>
            <?php if (is_admin()): ?>
                <a href="/settings" class="btn btn-primary">Configure Settings</a>
            <?php else: ?>
                <p class="text-muted">Ask an administrator to configure the CMS connection.</p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex gap-2 mb-2">
        <a href="/predictions" class="btn btn-primary btn-lg">Generate Predictions</a>
        <a href="/custom-lab" class="btn btn-outline btn-lg">Custom Lab Match</a>
    </div>
<?php endif; ?>

<?php if (!empty($recentPredictions)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Recent Predictions</h2>
        <a href="/predictions/saved" class="btn btn-sm btn-outline">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table table-compact">
            <thead>
                <tr>
                    <th>Color</th>
                    <th>PMS Number</th>
                    <th>Series</th>
                    <th>Confidence</th>
                    <th>Source</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentPredictions as $p): ?>
                <tr>
                    <td>
                        <span class="color-swatch" style="background:<?= e(PantoneLabService::labToHex((float)$p['lab_l'], (float)$p['lab_a'], (float)$p['lab_b'])) ?>"></span>
                    </td>
                    <td><strong><?= e($p['pms_number']) ?></strong></td>
                    <td><?= e($p['series_name']) ?></td>
                    <td>
                        <div class="confidence-bar">
                            <div class="confidence-bar-track">
                                <div class="confidence-bar-fill <?= $p['confidence_score'] >= 70 ? 'high' : ($p['confidence_score'] >= 40 ? 'medium' : 'low') ?>"
                                     style="width:<?= (float)$p['confidence_score'] ?>%"></div>
                            </div>
                            <span class="confidence-value"><?= number_format((float)$p['confidence_score'], 1) ?>%</span>
                        </div>
                    </td>
                    <td><span class="badge badge-<?= $p['source'] === 'custom_lab' ? 'custom' : 'predicted' ?>"><?= e($p['source']) ?></span></td>
                    <td class="text-muted"><?= format_date($p['created_at'], 'm/d H:i') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
