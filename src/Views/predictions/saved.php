<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Saved Predictions</h2>
        <?php if ($total > 0): ?>
        <a href="/api/predictions/export<?= $series ? '?series=' . urlencode($series) : '' ?>" class="btn btn-sm btn-outline">Export CSV</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="toolbar mb-2">
            <div class="toolbar-left">
                <select name="series" style="max-width:250px;">
                    <option value="">All Series</option>
                    <?php foreach ($seriesList as $s): ?>
                    <option value="<?= e($s['series_name']) ?>" <?= $series === $s['series_name'] ? 'selected' : '' ?>>
                        <?= e($s['series_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="source" style="max-width:150px;">
                    <option value="">All Sources</option>
                    <option value="predicted" <?= $source === 'predicted' ? 'selected' : '' ?>>Predicted</option>
                    <option value="custom_lab" <?= $source === 'custom_lab' ? 'selected' : '' ?>>Custom Lab</option>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            </div>
            <div class="toolbar-right">
                <span class="text-muted"><?= number_format($total) ?> result<?= $total !== 1 ? 's' : '' ?></span>
            </div>
        </form>

        <?php if (empty($predictions)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">&#128190;</div>
                <h3>No saved predictions</h3>
                <p class="text-muted">Generate predictions from the <a href="/predictions">Predictions</a> page.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Color</th>
                            <th>PMS</th>
                            <th>Series</th>
                            <th>Confidence</th>
                            <th>Components</th>
                            <th>Source</th>
                            <th>Created</th>
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
                            <td>
                                <button class="expand-toggle" data-id="<?= $p['id'] ?>">&#9654;</button>
                                <?= count($comps) ?> components
                            </td>
                            <td><span class="badge badge-<?= $p['source'] === 'custom_lab' ? 'custom' : 'predicted' ?>"><?= e($p['source']) ?></span></td>
                            <td class="text-muted"><?= format_date($p['created_at']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-danger delete-prediction-btn" data-id="<?= $p['id'] ?>">Delete</button>
                            </td>
                        </tr>
                        <tr class="detail-row" id="detail-<?= $p['id'] ?>">
                            <td colspan="8">
                                <div class="detail-content">
                                    <table class="component-table">
                                        <thead><tr><th>#</th><th>Code</th><th>Description</th><th class="text-right">Wt%</th></tr></thead>
                                        <tbody>
                                        <?php
                                        $totalPct = 0;
                                        foreach ($comps as $i => $c):
                                            $pct = (float)$c['percentage'] * 100;
                                            $totalPct += $pct;
                                        ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><strong><?= e($c['component_code']) ?></strong></td>
                                            <td><?= e($c['component_description']) ?></td>
                                            <td class="text-right"><?= number_format($pct, 2) ?>%</td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr><th colspan="3" class="text-right">Total:</th><th class="text-right"><?= number_format($totalPct, 2) ?>%</th></tr>
                                        </tfoot>
                                    </table>
                                    <?php
                                    $anchors = json_decode($p['nearest_anchors'] ?? '[]', true);
                                    if (!empty($anchors)):
                                    ?>
                                    <div class="mt-1" style="font-size:0.8rem;color:var(--text-muted);">
                                        <strong>Nearest anchors:</strong>
                                        <?php foreach ($anchors as $a): ?>
                                            <?= e($a['pmsNumber'] ?? '') ?> (&#916;E <?= $a['distance'] ?? '?' ?>)<?= $a !== end($anchors) ? ', ' : '' ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-between align-center mt-2">
                <span class="text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
                <div class="btn-group">
                    <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-sm btn-outline">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-sm btn-outline">Next</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
