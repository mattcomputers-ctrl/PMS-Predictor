<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Enter Lab Values</h2>
    </div>
    <div class="card-body">
        <div class="lab-input-group">
            <div class="form-group">
                <label for="labL">L* (Lightness)</label>
                <input type="number" id="labL" min="0" max="100" step="0.1" placeholder="0 - 100"
                       <?= !is_cms_configured() ? 'disabled' : '' ?>>
                <span class="form-help">0 = black, 100 = white</span>
            </div>
            <div class="form-group">
                <label for="labA">a* (Green-Red)</label>
                <input type="number" id="labA" min="-128" max="128" step="0.1" placeholder="-128 to 128"
                       <?= !is_cms_configured() ? 'disabled' : '' ?>>
                <span class="form-help">- = green, + = red</span>
            </div>
            <div class="form-group">
                <label for="labB">b* (Blue-Yellow)</label>
                <input type="number" id="labB" min="-128" max="128" step="0.1" placeholder="-128 to 128"
                       <?= !is_cms_configured() ? 'disabled' : '' ?>>
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
        <h2 class="card-title">Select Series</h2>
    </div>
    <div class="card-body">
        <div class="form-group">
            <label for="customSeriesSearch">Series Name</label>
            <div class="search-wrapper">
                <input type="text" id="customSeriesSearch" class="search-input"
                       placeholder="Type to search series..."
                       autocomplete="off" <?= !is_cms_configured() ? 'disabled' : '' ?>>
                <span class="search-spinner" id="customSeriesSpinner"><span class="spinner"></span></span>
                <div class="autocomplete-dropdown" id="customSeriesDropdown"></div>
            </div>
        </div>
        <div id="customSeriesInfo" class="hidden mt-1">
            <div class="d-flex align-center gap-1">
                <strong id="customSelectedSeries"></strong>
                <button class="btn btn-sm btn-outline" id="customChangeSeries">Change</button>
            </div>
        </div>

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
        <h2 class="card-title">Suggested Formula</h2>
        <div class="btn-group">
            <button class="btn btn-sm btn-primary" id="customSaveBtn">Save Prediction</button>
        </div>
    </div>
    <div class="card-body">
        <div id="customResultsSummary" class="results-summary mb-2"></div>
        <div id="customWarnings"></div>

        <div class="d-flex gap-2 mb-2">
            <div>
                <strong>Nearest Anchors:</strong>
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
    </div>
    <div id="customLoading" class="loading-overlay hidden">
        <div class="loading-message"><span class="spinner"></span> Finding formula...</div>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
