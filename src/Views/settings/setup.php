<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Welcome to Pantone Predictor</h2>
    </div>
    <div class="card-body">
        <p class="mb-2">Configure the CMS database connection to get started. This connects to your existing ink formulation database (MSSQL) so the predictor can read existing formulas.</p>

        <form method="POST" action="/setup">
            <?= csrf_field() ?>

            <div class="form-grid-2col">
                <div class="form-group">
                    <label for="cms_host">SQL Server Host</label>
                    <input type="text" id="cms_host" name="cms_host" placeholder="192.168.1.100" required>
                </div>
                <div class="form-group">
                    <label for="cms_port">Port</label>
                    <input type="number" id="cms_port" name="cms_port" value="1433">
                </div>
                <div class="form-group">
                    <label for="cms_database">Database Name</label>
                    <input type="text" id="cms_database" name="cms_database" value="CMS" required>
                </div>
                <div class="form-group">
                    <label for="cms_username">Username</label>
                    <input type="text" id="cms_username" name="cms_username" required>
                </div>
                <div class="form-group">
                    <label for="cms_password">Password</label>
                    <input type="password" id="cms_password" name="cms_password" required>
                </div>
            </div>

            <div class="mt-2">
                <button type="submit" class="btn btn-accent btn-lg">Save & Connect</button>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
