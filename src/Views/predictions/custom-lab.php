<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$db = \PantonePredictor\Core\Database::getInstance();
$savedSeries = $db->fetchAll("
    SELECT series_name, COUNT(*) AS formula_count,
           ROUND(AVG(confidence_score), 1) AS avg_confidence
    FROM predictions
    GROUP BY series_name
    ORDER BY series_name
");
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Enter Lab Values</h2>
    </div>
    <div class="card-body">
        <div class="lab-input-group">
            <div class="form-group">
                <label for="labL">L* (Lightness)</label>
                <input type="number" id="labL" min="0" max="100" step="0.1" placeholder="0 - 100">
                <span class="form-help">0 = black, 100 = white</span>
            </div>
            <div class="form-group">
                <label for="labA">a* (Green-Red)</label>
                <input type="number" id="labA" min="-128" max="128" step="0.1" placeholder="-128 to 128">
                <span class="form-help">- = green, + = red</span>
            </div>
            <div class="form-group">
                <label for="labB">b* (Blue-Yellow)</label>
                <input type="number" id="labB" min="-128" max="128" step="0.1" placeholder="-128 to 128">
                <span class="form-help">- = blue, + = yellow</span>
            </div>
        </div>

        <div class="lab-preview" id="labPreview">
            <div class="lab-preview-swatch" id="labSwatch" style="background:#808080;"></div>
            <div class="lab-preview-values" id="labValues">
                Enter L*, a*, b* values to see a preview.
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Select Saved Series</h2>
    </div>
    <div class="card-body">
        <?php if (empty($savedSeries)): ?>
            <div class="alert alert-warning">
                No saved series yet. Go to <a href="/predictions">Generate</a>, create predictions for a series, and save them first.
            </div>
        <?php else: ?>
            <div class="form-group mb-0">
                <select id="customSeriesSelect" style="max-width:500px;">
                    <option value="">— Choose a saved series —</option>
                    <?php foreach ($savedSeries as $s): ?>
                    <option value="<?= e($s['series_name']) ?>">
                        <?= e($s['series_name']) ?> (<?= $s['formula_count'] ?> formulas, avg <?= $s['avg_confidence'] ?>% confidence)
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="form-help">Uses saved predictions for this series as the interpolation basis</span>
            </div>
        <?php endif; ?>

        <div class="mt-2">
            <button class="btn btn-accent btn-lg" id="customMatchBtn" disabled>
                Find Starting Formula
            </button>
        </div>
    </div>
</div>

<!-- Results -->
<div class="card hidden" id="customResultsCard">
    <div class="card-header">
        <h2 class="card-title">Suggested Formula (Pigment Only — 100%)</h2>
        <div class="btn-group">
            <button class="btn btn-sm btn-primary" id="customSaveBtn">Save Prediction</button>
        </div>
    </div>
    <div class="card-body">
        <div id="customResultsSummary" class="results-summary mb-2"></div>
        <div id="customWarnings"></div>

        <div class="d-flex gap-2 mb-2">
            <div>
                <strong>Nearest Saved Formulas:</strong>
                <div id="customAnchorsInfo" class="mt-1" style="font-size:0.85rem;"></div>
            </div>
        </div>

        <table class="table table-compact" id="customComponentsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Component Code</th>
                    <th>Description</th>
                    <th class="text-right">Wt%</th>
                </tr>
            </thead>
            <tbody id="customComponentsBody"></tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="text-right">Total:</th>
                    <th id="customTotalPct" class="text-right"></th>
                </tr>
            </tfoot>
        </table>

        <div id="customMetamerism" class="mt-1" style="font-size:0.85rem;"></div>
    </div>
    <div id="customLoading" class="loading-overlay hidden">
        <div class="loading-message"><span class="spinner"></span> Finding formula...</div>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
