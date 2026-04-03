<?php $pageTitle = '404 Not Found'; include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="card">
    <div class="empty-state">
        <div class="empty-state-icon">&#128533;</div>
        <h3>Page Not Found</h3>
        <p class="text-muted mb-2">The page you're looking for doesn't exist.</p>
        <a href="/" class="btn btn-primary">Back to Dashboard</a>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
