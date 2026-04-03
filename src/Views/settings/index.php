<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<form method="POST" action="/settings">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">CMS Database Connection</h2>
            <button type="button" class="btn btn-sm btn-outline" id="testConnectionBtn">Test Connection</button>
        </div>
        <div class="card-body">
            <div id="connectionResult" class="hidden mb-2"></div>
            <div class="form-grid-2col">
                <div class="form-group">
                    <label for="cms_host">SQL Server Host</label>
                    <input type="text" id="cms_host" name="cms_host"
                           value="<?= e($settings['cms_host'] ?? '') ?>"
                           placeholder="192.168.1.100">
                </div>
                <div class="form-group">
                    <label for="cms_port">Port</label>
                    <input type="number" id="cms_port" name="cms_port"
                           value="<?= e($settings['cms_port'] ?? '1433') ?>">
                </div>
                <div class="form-group">
                    <label for="cms_database">Database Name</label>
                    <input type="text" id="cms_database" name="cms_database"
                           value="<?= e($settings['cms_database'] ?? 'CMS') ?>">
                </div>
                <div class="form-group">
                    <label for="cms_username">Username</label>
                    <input type="text" id="cms_username" name="cms_username"
                           value="<?= e($settings['cms_username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="cms_password">Password</label>
                    <input type="password" id="cms_password" name="cms_password"
                           placeholder="<?= !empty($settings['cms_password']) ? '(unchanged)' : '' ?>">
                    <span class="form-help">Leave blank to keep current password</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Application Settings</h2>
        </div>
        <div class="card-body">
            <div class="form-grid-3col">
                <div class="form-group">
                    <label for="app_name">Application Name</label>
                    <input type="text" id="app_name" name="app_name"
                           value="<?= e($settings['app_name'] ?? 'Pantone Predictor') ?>">
                </div>
                <div class="form-group">
                    <label for="prediction_k">Default K (Neighbors)</label>
                    <input type="number" id="prediction_k" name="prediction_k"
                           value="<?= e($settings['prediction_k'] ?? '5') ?>" min="3" max="15">
                </div>
                <div class="form-group">
                    <label for="noise_threshold">Default Noise Threshold</label>
                    <input type="number" id="noise_threshold" name="noise_threshold"
                           value="<?= e($settings['noise_threshold'] ?? '2') ?>" min="1" max="5">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-1">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<div class="card mt-2">
    <div class="card-header">
        <h2 class="card-title">Change Password</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="/settings/password">
            <?= csrf_field() ?>
            <div class="form-grid-3col">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8">
                    <span class="form-help">Minimum 8 characters</span>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Update Password</button>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
