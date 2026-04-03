<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Pantone Predictor') ?> — <?= e(get_setting('app_name', 'Pantone Predictor')) ?></title>
    <link rel="stylesheet" href="/css/app.css">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/" class="sidebar-brand"><?= e(get_setting('app_name', 'Pantone Predictor')) ?></a>
        </div>

        <?php if (isset($_SESSION['_user'])): ?>
        <nav class="sidebar-nav">
            <ul class="sidebar-menu">
                <li><a href="/" class="<?= ($_SERVER['REQUEST_URI'] ?? '') === '/' ? 'active' : '' ?>"><span class="menu-icon">&#9632;</span> Dashboard</a></li>

                <li class="sidebar-section-label">Predictions</li>
                <li><a href="/predictions" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/predictions') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/saved') ? 'active' : '' ?>"><span class="menu-icon">&#127912;</span> Generate</a></li>
                <li><a href="/custom-lab" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/custom-lab') ? 'active' : '' ?>"><span class="menu-icon">&#128300;</span> Custom Lab Match</a></li>
                <li><a href="/predictions/saved" class="<?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/saved') ? 'active' : '' ?>"><span class="menu-icon">&#128190;</span> Saved Results</a></li>

                <?php if (is_admin()): ?>
                <li class="sidebar-section-label">Admin</li>
                <li><a href="/settings" class="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/settings') ? 'active' : '' ?>"><span class="menu-icon">&#9881;</span> Settings</a></li>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-info">
                    <span class="sidebar-user-name"><?= e($_SESSION['_user']['display_name'] ?: $_SESSION['_user']['username']) ?></span>
                    <?php if ($_SESSION['_user']['is_admin']): ?>
                    <span class="badge badge-admin">Admin</span>
                    <?php endif; ?>
                </div>
                <a href="/logout" class="btn btn-sm btn-logout">Logout</a>
            </div>
        </div>
        <?php endif; ?>
    </aside>

    <div class="main-wrapper" id="mainWrapper">
        <header class="topbar" id="topbar">
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                <span></span><span></span><span></span>
            </button>
            <span class="topbar-title"><?= e($pageTitle ?? 'Pantone Predictor') ?></span>
        </header>

        <main class="content">
            <?= flash_messages() ?>

            <?php if (!is_cms_configured() && is_admin()): ?>
            <div class="alert alert-warning">
                CMS database is not configured. <a href="/settings">Configure it now</a> to start generating predictions.
            </div>
            <?php endif; ?>

            <?php if (isset($pageTitle)): ?>
                <h1 class="page-title"><?= e($pageTitle) ?></h1>
            <?php endif; ?>
