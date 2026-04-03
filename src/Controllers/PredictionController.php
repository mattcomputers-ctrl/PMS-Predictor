<?php

namespace PantonePredictor\Controllers;

use PantonePredictor\Core\Database;

class PredictionController
{
    public function index(): void
    {
        $pageTitle = 'Generate Predictions';

        if (!is_cms_configured()) {
            $_SESSION['_flash']['warning'] = 'Configure the CMS database connection in Settings before generating predictions.';
        }

        include dirname(__DIR__) . '/Views/predictions/index.php';
    }

    public function saved(): void
    {
        $pageTitle = 'Saved Predictions';
        $db = Database::getInstance();

        $series = $_GET['series'] ?? '';
        $source = $_GET['source'] ?? '';
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if ($series !== '') {
            $where[]  = 'p.series_name = ?';
            $params[] = $series;
        }
        if ($source !== '') {
            $where[]  = 'p.source = ?';
            $params[] = $source;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $db->fetch(
            "SELECT COUNT(*) AS cnt FROM predictions p {$whereClause}",
            $params
        )['cnt'];

        $predictions = $db->fetchAll(
            "SELECT p.*, u.display_name AS creator_name
             FROM predictions p
             LEFT JOIN users u ON u.id = p.created_by
             {$whereClause}
             ORDER BY p.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        // Load components for each prediction
        $predictionIds = array_column($predictions, 'id');
        $componentsByPrediction = [];
        if (!empty($predictionIds)) {
            $placeholders = implode(',', array_fill(0, count($predictionIds), '?'));
            $allComponents = $db->fetchAll(
                "SELECT * FROM prediction_components WHERE prediction_id IN ({$placeholders}) ORDER BY sort_order",
                $predictionIds
            );
            foreach ($allComponents as $comp) {
                $componentsByPrediction[$comp['prediction_id']][] = $comp;
            }
        }

        // Get distinct series for filter dropdown
        $seriesList = $db->fetchAll("SELECT DISTINCT series_name FROM predictions ORDER BY series_name");

        $totalPages = ceil($total / $limit);

        include dirname(__DIR__) . '/Views/predictions/saved.php';
    }

    public function customLab(): void
    {
        $pageTitle = 'Custom Lab Match';

        if (!is_cms_configured()) {
            $_SESSION['_flash']['warning'] = 'Configure the CMS database connection in Settings before using this feature.';
        }

        include dirname(__DIR__) . '/Views/predictions/custom-lab.php';
    }
}
