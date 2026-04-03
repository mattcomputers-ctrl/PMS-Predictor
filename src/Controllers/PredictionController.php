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
        $pageTitle = 'Saved Series';
        include dirname(__DIR__) . '/Views/predictions/saved.php';
    }

    public function customLab(): void
    {
        $pageTitle = 'Custom Lab Match';
        include dirname(__DIR__) . '/Views/predictions/custom-lab.php';
    }
}
