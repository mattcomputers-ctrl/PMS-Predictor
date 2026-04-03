<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<!-- Card 1: Series Selection -->
<div class="card" id="seriesCard">
    <div class="card-header">
        <h2 class="card-title">1. Select Ink Series</h2>
    </div>
    <div class="card-body">
        <div class="form-group">
            <label for="seriesSearch">Series Name</label>
            <div class="search-wrapper">
                <input type="text" id="seriesSearch" class="search-input"
                       placeholder="Type to search series (e.g., PRIMASET, PULSE UV)..."
                       autocomplete="off" <?= !is_cms_configured() ? 'disabled' : '' ?>>
                <span class="search-spinner" id="seriesSpinner"><span class="spinner"></span></span>
                <div class="autocomplete-dropdown" id="seriesDropdown"></div>
            </div>
        </div>
        <div id="seriesInfo" class="hidden">
            <div class="d-flex align-center gap-1">
                <strong id="selectedSeriesName"></strong>
                <span class="badge badge-info" id="seriesFormulaCount"></span>
                <button class="btn btn-sm btn-outline" id="changeSeries">Change</button>
            </div>
        </div>
    </div>
</div>

<!-- Card 2: Anchor Selection -->
<div class="card hidden" id="anchorsCard">
    <div class="card-header">
        <h2 class="card-title">2. Select Anchor Formulas</h2>
        <div class="btn-group">
            <button class="btn btn-sm btn-outline" id="selectAll">Select All</button>
            <button class="btn btn-sm btn-outline" id="deselectAll">Deselect All</button>
        </div>
    </div>
    <div class="card-body" style="position:relative;">
        <div class="toolbar">
            <div class="toolbar-left">
                <input type="text" id="anchorFilter" class="filter-input" placeholder="Filter formulas...">
            </div>
            <div class="toolbar-right">
                <span class="selection-info">
                    <strong id="selectedCount">0</strong> of <span id="totalAnchors">0</span> selected
                </span>
            </div>
        </div>
        <div id="anchorWarning" class="warning-banner hidden">
            <span class="warning-banner-icon">&#9888;</span>
            <span id="anchorWarningText"></span>
        </div>
        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
            <table class="table table-compact" id="anchorsTable">
                <thead>
                    <tr>
                        <th class="checkbox-cell"><input type="checkbox" id="headerCheckbox"></th>
                        <th>Color</th>
                        <th>PMS Number</th>
                        <th>Item Code</th>
                        <th>Key Components</th>
                        <th>Lab</th>
                    </tr>
                </thead>
                <tbody id="anchorsBody"></tbody>
            </table>
        </div>
        <div id="anchorsLoading" class="loading-overlay hidden">
            <div class="loading-message"><span class="spinner"></span> Loading formulas...</div>
        </div>
    </div>
</div>

<!-- Card 3: Generation Options -->
<div class="card hidden" id="optionsCard">
    <div class="card-header">
        <h2 class="card-title">3. Generation Options</h2>
    </div>
    <div class="card-body">
        <div class="form-grid-3col">
            <div class="form-group">
                <label for="kValue">Nearest Neighbors (K)</label>
                <input type="number" id="kValue" value="<?= e(get_setting('prediction_k', '5')) ?>" min="3" max="15">
                <span class="form-help">Higher = smoother blends, lower = more distinct</span>
            </div>
            <div class="form-group">
                <label for="noiseValue">Noise Threshold</label>
                <input type="number" id="noiseValue" value="<?= e(get_setting('noise_threshold', '2')) ?>" min="1" max="5">
                <span class="form-help">Min anchors a component must appear in</span>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <button class="btn btn-accent btn-lg" id="generateBtn" disabled>
                    Generate Predictions
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Card 4: Results -->
<div class="card hidden" id="resultsCard">
    <div class="card-header">
        <h2 class="card-title">4. Prediction Results</h2>
        <div class="btn-group">
            <button class="btn btn-sm btn-primary" id="saveAllBtn">Save All</button>
            <button class="btn btn-sm btn-outline" id="saveSelectedBtn">Save Selected</button>
            <button class="btn btn-sm btn-outline" id="exportCsvBtn">Export CSV</button>
        </div>
    </div>
    <div class="card-body">
        <div id="resultsSummary" class="results-summary"></div>
        <div id="resultsWarnings"></div>
        <div class="toolbar">
            <input type="text" id="resultsFilter" class="filter-input" placeholder="Filter results...">
            <div class="btn-group">
                <button class="btn btn-sm btn-outline" id="resultSelectAll">Select All</button>
                <button class="btn btn-sm btn-outline" id="resultDeselectAll">Deselect All</button>
            </div>
        </div>
        <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
            <table class="table" id="resultsTable">
                <thead>
                    <tr>
                        <th class="checkbox-cell"><input type="checkbox" id="resultHeaderCheckbox"></th>
                        <th>Color</th>
                        <th>PMS Number</th>
                        <th>Name</th>
                        <th>Confidence</th>
                        <th>Components</th>
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
