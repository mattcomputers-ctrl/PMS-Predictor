<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$db = \PantonePredictor\Core\Database::getInstance();
$savedSeries = $db->fetchAll("
    SELECT series_name, COUNT(*) AS formula_count,
           ROUND(AVG(confidence_score), 1) AS avg_confidence,
           MAX(created_at) AS last_updated
    FROM predictions
    GROUP BY series_name
    ORDER BY series_name
");
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Saved Series</h2>
    </div>
    <div class="card-body">
        <?php if (empty($savedSeries)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">&#128190;</div>
                <h3>No saved series</h3>
                <p class="text-muted">Go to <a href="/predictions">Generate</a> to create and save predictions for a series.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Series Name</th>
                            <th>Formulas</th>
                            <th>Avg Confidence</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($savedSeries as $s): ?>
                        <tr>
                            <td><strong><?= e($s['series_name']) ?></strong></td>
                            <td><?= number_format((int)$s['formula_count']) ?></td>
                            <td>
                                <div class="confidence-bar">
                                    <div class="confidence-bar-track">
                                        <div class="confidence-bar-fill <?= $s['avg_confidence'] >= 70 ? 'high' : ($s['avg_confidence'] >= 40 ? 'medium' : 'low') ?>"
                                             style="width:<?= (float)$s['avg_confidence'] ?>%"></div>
                                    </div>
                                    <span class="confidence-value"><?= $s['avg_confidence'] ?>%</span>
                                </div>
                            </td>
                            <td class="text-muted"><?= format_date($s['last_updated']) ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="/api/predictions/export?series=<?= urlencode($s['series_name']) ?>" class="btn btn-sm btn-outline">Export CSV</a>
                                    <a href="/predictions/saved/view?series=<?= urlencode($s['series_name']) ?>" class="btn btn-sm btn-outline">View</a>
                                    <button class="btn btn-sm btn-danger delete-series-btn" data-series="<?= e($s['series_name']) ?>">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// If viewing a specific series
$viewSeries = trim($_GET['series'] ?? '');
if ($viewSeries !== ''):
    $predictions = $db->fetchAll(
        "SELECT p.* FROM predictions p WHERE p.series_name = ? ORDER BY p.pms_number",
        [$viewSeries]
    );
    $predictionIds = array_column($predictions, 'id');
    $componentsByPrediction = [];
    if (!empty($predictionIds)) {
        $ph = implode(',', array_fill(0, count($predictionIds), '?'));
        $allComps = $db->fetchAll(
            "SELECT * FROM prediction_components WHERE prediction_id IN ({$ph}) ORDER BY sort_order",
            $predictionIds
        );
        foreach ($allComps as $c) {
            $componentsByPrediction[$c['prediction_id']][] = $c;
        }
    }
?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= e($viewSeries) ?> — <?= count($predictions) ?> Formulas</h2>
        <a href="/api/predictions/export?series=<?= urlencode($viewSeries) ?>" class="btn btn-sm btn-outline">Export CSV</a>
    </div>
    <div class="card-body">
        <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
            <table class="table table-compact">
                <thead>
                    <tr>
                        <th>Color</th>
                        <th>PMS</th>
                        <th>Name</th>
                        <th>Confidence</th>
                        <th>Components</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($predictions as $p):
                        $comps = $componentsByPrediction[$p['id']] ?? [];
                        $hex = \PantonePredictor\Services\PantoneLabService::labToHex(
                            (float)$p['lab_l'], (float)$p['lab_a'], (float)$p['lab_b']
                        );
                    ?>
                    <tr>
                        <td><span class="color-swatch" style="background:<?= e($hex) ?>"></span></td>
                        <td><strong><?= e($p['pms_number']) ?></strong></td>
                        <td style="font-size:0.85rem"><?= e($p['pms_name']) ?></td>
                        <td>
                            <div class="confidence-bar">
                                <div class="confidence-bar-track">
                                    <div class="confidence-bar-fill <?= $p['confidence_score'] >= 70 ? 'high' : ($p['confidence_score'] >= 40 ? 'medium' : 'low') ?>"
                                         style="width:<?= (float)$p['confidence_score'] ?>%"></div>
                                </div>
                                <span class="confidence-value"><?= number_format((float)$p['confidence_score'], 1) ?>%</span>
                            </div>
                        </td>
                        <td>
                            <button class="expand-toggle" data-id="<?= $p['id'] ?>">&#9654;</button>
                            <?= count($comps) ?> pigments
                        </td>
                        <td>
                            <button class="btn btn-sm btn-danger delete-prediction-btn" data-id="<?= $p['id'] ?>">Delete</button>
                        </td>
                    </tr>
                    <tr class="detail-row" id="detail-<?= $p['id'] ?>">
                        <td colspan="6">
                            <div class="detail-content">
                                <table class="component-table">
                                    <thead><tr><th>#</th><th>Code</th><th>Description</th><th class="text-right">Wt%</th></tr></thead>
                                    <tbody>
                                    <?php $totalPct = 0; foreach ($comps as $i => $c):
                                        $pct = (float)$c['percentage'] * 100; $totalPct += $pct; ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td><strong><?= e($c['component_code']) ?></strong></td>
                                        <td><?= e($c['component_description']) ?></td>
                                        <td class="text-right"><?= number_format($pct, 2) ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot><tr><th colspan="3" class="text-right">Total:</th><th class="text-right"><?= number_format($totalPct, 2) ?>%</th></tr></tfoot>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.delete-series-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const series = this.dataset.series;
        if (!confirm(`Delete ALL predictions for "${series}"? This cannot be undone.`)) return;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        try {
            const r = await fetch('/api/saved-series/delete', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
                body: JSON.stringify({ series })
            });
            const data = await r.json();
            if (data.success) { location.reload(); } else { alert(data.error); }
        } catch(e) { alert('Delete failed: ' + e.message); }
    });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
