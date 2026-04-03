<?php

namespace PantonePredictor\Controllers;

use PantonePredictor\Core\Database;
use PantonePredictor\Services\PantoneLabService;

class DashboardController
{
    public function index(): void
    {
        $db = Database::getInstance();
        $pageTitle = 'Dashboard';

        $totalPredictions = (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM predictions")['cnt'] ?? 0);
        $totalSeries      = (int) ($db->fetch("SELECT COUNT(DISTINCT series_name) AS cnt FROM predictions")['cnt'] ?? 0);
        $avgConfidence     = (float) ($db->fetch("SELECT AVG(confidence_score) AS avg FROM predictions")['avg'] ?? 0);
        $pantoneCount      = PantoneLabService::getColorCount();
        $cmsConfigured     = is_cms_configured();

        $recentPredictions = $db->fetchAll(
            "SELECT p.*, u.display_name AS creator_name
             FROM predictions p
             LEFT JOIN users u ON u.id = p.created_by
             ORDER BY p.created_at DESC
             LIMIT 10"
        );

        include dirname(__DIR__) . '/Views/dashboard/index.php';
    }
}
