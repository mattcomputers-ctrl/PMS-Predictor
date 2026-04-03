<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$db = \PantonePredictor\Core\Database::getInstance();
$lastSync = get_setting('last_sync', '');
$formulaCount = (int)($db->fetch("SELECT COUNT(*) AS cnt FROM cms_formulas")['cnt'] ?? 0);
?>

<!-- Sync Bar -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title" style="display:inline">CMS Formulas</h2>
            <span class="text-muted" style="margin-left:0.5rem">
                <?= $formulaCount ?> formulas synced
                <?= $lastSync ? '(last: ' . format_date($lastSync) . ')' : '(never synced)' ?>
            </span>
        </div>
        <button class="btn btn-sm btn-primary" id="syncBtn" <?= !is_cms_configured() ? 'disabled' : '' ?>>
            Sync from CMS
        </button>
    </div>
</div>

<!-- Series Filter -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Series Name</h2>
    </div>
    <div class="card-body">
        <div class="form-group mb-0">
            <input type="text" id="seriesInput" placeholder="Type a series name (e.g. PRIMASET, PULSE UV, VINYLCURE)..."
                   style="max-width:500px;" <?= $formulaCount === 0 ? 'disabled' : '' ?>>
            <span class="form-help">Filters synced formulas whose description contains this text + PANTONE</span>
        </div>
        <div id="seriesMatchCount" class="mt-1 text-muted hidden" style="font-size:0.85rem;"></div>
    </div>
</div>

<!-- Formula Table -->
<div class="card hidden" id="formulasCard">
    <div class="card-header">
        <h2 class="card-title">Pantone Formulas</h2>
        <div class="d-flex align-center gap-1">
            <input type="text" id="formulaFilter" class="filter-input" placeholder="Filter..." style="max-width:200px">
            <button class="btn btn-sm btn-outline" id="selectAllBtn">Select All</button>
            <button class="btn btn-sm btn-outline" id="deselectAllBtn">Deselect All</button>
        </div>
    </div>
    <div class="card-body" style="position:relative;">
        <p class="text-muted mb-1" style="font-size:0.85rem;">
            Check formulas to use as anchors. Enter or confirm the Pantone number for each.
            Only pigment materials (2A, 3A, FL, 3U, 2U, DS) are used — additives are excluded.
        </p>
        <div class="selection-info mb-1">
            <strong id="selectedCount">0</strong> anchors selected
        </div>
        <div class="table-responsive" style="max-height:500px;overflow-y:auto;">
            <table class="table table-compact" id="formulasTable">
                <thead>
                    <tr>
                        <th class="checkbox-cell" style="width:40px"><input type="checkbox" id="headerCb"></th>
                        <th style="width:90px">Item Code</th>
                        <th>Description</th>
                        <th style="width:120px">PMS Number</th>
                        <th>Pigment Components</th>
                        <th style="width:70px">Pig. %</th>
                    </tr>
                </thead>
                <tbody id="formulasBody"></tbody>
            </table>
        </div>
        <div id="formulasLoading" class="loading-overlay hidden">
            <div class="loading-message"><span class="spinner"></span> Loading formulas...</div>
        </div>
    </div>
</div>

<!-- Generation Options + Button (always visible when formulas loaded) -->
<div class="card hidden" id="generateCard" style="position:sticky;bottom:0;z-index:50;border-top:3px solid var(--accent);">
    <div class="d-flex align-center justify-between gap-2" style="flex-wrap:wrap;">
        <div class="d-flex align-center gap-2">
            <div class="form-group mb-0">
                <label for="kValue" style="font-size:0.8rem">K Neighbors</label>
                <input type="number" id="kValue" value="<?= e(get_setting('prediction_k', '5')) ?>" min="3" max="15" style="width:70px">
            </div>
            <div class="form-group mb-0">
                <label for="noiseValue" style="font-size:0.8rem">Noise Threshold</label>
                <input type="number" id="noiseValue" value="<?= e(get_setting('noise_threshold', '2')) ?>" min="1" max="5" style="width:70px">
            </div>
            <span class="text-muted" style="font-size:0.85rem" id="generateInfo"></span>
        </div>
        <button class="btn btn-accent btn-lg" id="generateBtn" disabled>
            Generate Predictions
        </button>
    </div>
</div>

<!-- Results -->
<div class="card hidden" id="resultsCard">
    <div class="card-header">
        <h2 class="card-title">Predicted Formulas (Pigment Only — 100%)</h2>
        <div class="btn-group">
            <button class="btn btn-sm btn-primary" id="saveAllBtn">Save All</button>
            <button class="btn btn-sm btn-outline" id="saveSelectedBtn">Save Selected</button>
            <button class="btn btn-sm btn-outline" id="exportCsvBtn">Export CSV</button>
        </div>
    </div>
    <div class="card-body">
        <div id="resultsSummary" class="results-summary"></div>
        <div id="resultsWarnings"></div>
        <div id="skippedAnchorsInfo" class="hidden mb-1"></div>
        <div class="toolbar">
            <input type="text" id="resultsFilter" class="filter-input" placeholder="Filter by PMS number...">
            <div class="btn-group">
                <button class="btn btn-sm btn-outline" id="resultSelectAll">Select All</button>
                <button class="btn btn-sm btn-outline" id="resultDeselectAll">Deselect All</button>
            </div>
        </div>
        <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
            <table class="table" id="resultsTable">
                <thead>
                    <tr>
                        <th class="checkbox-cell"><input type="checkbox" id="resultHeaderCb"></th>
                        <th>Color</th>
                        <th>PMS</th>
                        <th>Name</th>
                        <th>Confidence</th>
                        <th>Pigment Components</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="resultsBody"></tbody>
            </table>
        </div>
        <div id="resultsLoading" class="loading-overlay hidden">
            <div class="loading-message"><span class="spinner"></span> Generating predictions...</div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
