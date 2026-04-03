<?php

declare(strict_types=1);

namespace PantonePredictor\Controllers;

use PantonePredictor\Core\CSRF;
use PantonePredictor\Core\Database;
use PantonePredictor\Services\CMSDatabase;
use PantonePredictor\Services\CMSFormulaService;
use PantonePredictor\Services\InterpolationEngine;
use PantonePredictor\Services\PantoneLabService;

class ApiController
{
    /**
     * GET /api/series/search?q=...
     * Autocomplete series names from CMS.
     */
    public function seriesSearch(): void
    {
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) < 2) {
            json_response([]);
            return;
        }

        if (!CMSDatabase::isConfigured()) {
            json_response(['error' => 'CMS not configured'], 503);
            return;
        }

        try {
            $svc = new CMSFormulaService();
            $results = $svc->searchSeries($query);
            json_response($results);
        } catch (\Throwable $e) {
            json_response(['error' => 'CMS query failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/series/formulas?series=...
     * Get all formulas in a series with Lab data status.
     */
    public function seriesFormulas(): void
    {
        $series = trim($_GET['series'] ?? '');
        if ($series === '') {
            json_response(['error' => 'Series name required'], 400);
            return;
        }

        if (!CMSDatabase::isConfigured()) {
            json_response(['error' => 'CMS not configured'], 503);
            return;
        }

        try {
            $svc   = new CMSFormulaService();
            $items = $svc->getSeriesFormulas($series);

            $formulas = [];
            foreach ($items as $item) {
                $colorPart = $item['color_part'] ?? '';
                $pmsNumber = CMSFormulaService::extractPmsNumber($colorPart);
                $labData   = PantoneLabService::getLabForColor($pmsNumber);

                $topComponents = array_slice($item['components'] ?? [], 0, 3);
                $topSummary = array_map(function ($c) {
                    return $c['component_code'] . ' (' . round($c['percentage'] * 100, 1) . '%)';
                }, $topComponents);

                $formulas[] = [
                    'itemCode'       => $item['ItemCode'],
                    'description'    => $item['Description'],
                    'colorPart'      => $colorPart,
                    'pmsNumber'      => $pmsNumber,
                    'componentCount' => count($item['components'] ?? []),
                    'topComponents'  => implode(', ', $topSummary),
                    'hasLabData'     => $labData !== null,
                    'lab'            => $labData ? ['L' => $labData['L'], 'a' => $labData['a'], 'b' => $labData['b']] : null,
                    'hex'            => $labData['hex'] ?? null,
                ];
            }

            json_response([
                'series'   => $series,
                'formulas' => $formulas,
                'total'    => count($formulas),
                'withLab'  => count(array_filter($formulas, fn($f) => $f['hasLabData'])),
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'Failed to load formulas: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/predictions/generate
     * Generate predictions for all PMS colors not in the series.
     */
    public function generate(): void
    {
        CSRF::validateRequest();

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $series       = trim($input['series'] ?? '');
        $anchorCodes  = $input['anchorCodes'] ?? [];
        $k            = (int) ($input['k'] ?? (int) get_setting('prediction_k', '5'));
        $noiseThreshold = (int) ($input['noiseThreshold'] ?? (int) get_setting('noise_threshold', '2'));

        if ($series === '' || empty($anchorCodes)) {
            json_response(['error' => 'Series and at least one anchor required'], 400);
            return;
        }

        try {
            $startTime = microtime(true);

            // Load anchor formulas from CMS
            $svc = new CMSFormulaService();
            $anchorData = $svc->getFormulaComponents($anchorCodes);

            // Build anchor list with Lab coordinates
            $anchors = [];
            foreach ($anchorData as $code => $item) {
                $desc = $item['description'] ?? '';
                if (preg_match('/PANTONE\s+(.+)$/i', $desc, $m)) {
                    $colorPart = trim($m[1]);
                } else {
                    continue;
                }

                $pmsNumber = CMSFormulaService::extractPmsNumber($colorPart);
                $labData = PantoneLabService::getLabForColor($pmsNumber);
                if (!$labData) {
                    continue;
                }

                $anchors[] = [
                    'itemCode'   => $code,
                    'pmsNumber'  => $pmsNumber,
                    'pmsName'    => $colorPart,
                    'lab'        => ['L' => (float) $labData['L'], 'a' => (float) $labData['a'], 'b' => (float) $labData['b']],
                    'components' => $item['components'],
                ];
            }

            if (empty($anchors)) {
                json_response(['error' => 'No valid anchors with Lab data found'], 400);
                return;
            }

            // Get all PMS colors in the dataset
            $allPms = PantoneLabService::getAllColors();

            // Get PMS numbers already in this series
            $existingPms = [];
            $seriesFormulas = $svc->getSeriesFormulas($series);
            foreach ($seriesFormulas as $item) {
                $colorPart = $item['color_part'] ?? '';
                $pmsNum = CMSFormulaService::extractPmsNumber($colorPart);
                $existingPms[$pmsNum] = true;
            }

            // Generate predictions for missing colors
            $predictions = [];
            $skipped = [];

            foreach ($allPms as $pmsKey => $pmsData) {
                // Skip if already exists in series
                if (isset($existingPms[$pmsKey])) {
                    continue;
                }

                $targetLab = [
                    'L' => (float) $pmsData['L'],
                    'a' => (float) $pmsData['a'],
                    'b' => (float) $pmsData['b'],
                ];

                $result = InterpolationEngine::predict($targetLab, $anchors, $k, $noiseThreshold);

                if (empty($result['components'])) {
                    $skipped[] = $pmsKey;
                    continue;
                }

                $predictions[] = [
                    'pmsNumber'      => $pmsKey,
                    'pmsName'        => $pmsData['name'] ?? $pmsKey,
                    'hex'            => $pmsData['hex'] ?? PantoneLabService::labToHex($targetLab['L'], $targetLab['a'], $targetLab['b']),
                    'lab'            => $targetLab,
                    'confidence'     => $result['confidence'],
                    'components'     => $result['components'],
                    'nearestAnchors' => $result['nearestAnchors'],
                    'warnings'       => $result['warnings'],
                ];
            }

            // Sort by confidence descending
            usort($predictions, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

            $elapsed = round((microtime(true) - $startTime) * 1000);

            json_response([
                'series'      => $series,
                'predictions' => $predictions,
                'total'       => count($predictions),
                'skipped'     => count($skipped),
                'anchorCount' => count($anchors),
                'elapsed_ms'  => $elapsed,
                'warnings'    => count($anchors) < 20
                    ? ['Fewer than 20 anchors selected. Predictions may have lower confidence.']
                    : [],
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'Prediction failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/predictions/save
     * Save a single prediction.
     */
    public function savePrediction(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $this->doSave($input);
    }

    /**
     * POST /api/predictions/save-batch
     * Save multiple predictions.
     */
    public function saveBatch(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $items = $input['predictions'] ?? [];
        $series = $input['series'] ?? '';

        if (empty($items)) {
            json_response(['error' => 'No predictions to save'], 400);
            return;
        }

        $db = Database::getInstance();
        $saved   = 0;
        $skipped = 0;

        $db->beginTransaction();
        try {
            foreach ($items as $item) {
                $item['series'] = $series;
                $result = $this->doSaveSingle($db, $item);
                if ($result === 'saved') {
                    $saved++;
                } else {
                    $skipped++;
                }
            }
            $db->commit();

            json_response([
                'success' => true,
                'saved'   => $saved,
                'skipped' => $skipped,
                'message' => "{$saved} predictions saved, {$skipped} skipped (already exist).",
            ]);
        } catch (\Throwable $e) {
            $db->rollback();
            json_response(['error' => 'Save failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/predictions/delete
     * Delete a saved prediction.
     */
    public function deletePrediction(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int) ($input['id'] ?? 0);

        if ($id <= 0) {
            json_response(['error' => 'Invalid ID'], 400);
            return;
        }

        $db = Database::getInstance();
        $db->query("DELETE FROM predictions WHERE id = ?", [$id]);
        json_response(['success' => true]);
    }

    /**
     * GET /api/predictions/export?series=...
     * Export predictions as CSV.
     */
    public function export(): void
    {
        $series = trim($_GET['series'] ?? '');
        $db = Database::getInstance();

        $where = '';
        $params = [];
        if ($series !== '') {
            $where = 'WHERE p.series_name = ?';
            $params = [$series];
        }

        $predictions = $db->fetchAll(
            "SELECT p.* FROM predictions p {$where} ORDER BY p.series_name, p.pms_number",
            $params
        );

        $predictionIds = array_column($predictions, 'id');
        $componentsByPrediction = [];
        if (!empty($predictionIds)) {
            $ph = implode(',', array_fill(0, count($predictionIds), '?'));
            $comps = $db->fetchAll(
                "SELECT * FROM prediction_components WHERE prediction_id IN ({$ph}) ORDER BY sort_order",
                $predictionIds
            );
            foreach ($comps as $c) {
                $componentsByPrediction[$c['prediction_id']][] = $c;
            }
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="predictions_' . date('Ymd') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Series', 'PMS Number', 'PMS Name', 'L*', 'a*', 'b*', 'Confidence', 'Source', 'Components']);

        foreach ($predictions as $p) {
            $comps = $componentsByPrediction[$p['id']] ?? [];
            $compStr = implode('; ', array_map(
                fn($c) => $c['component_code'] . ' ' . round($c['percentage'] * 100, 2) . '%',
                $comps
            ));

            fputcsv($out, [
                $p['series_name'],
                $p['pms_number'],
                $p['pms_name'],
                $p['lab_l'],
                $p['lab_a'],
                $p['lab_b'],
                $p['confidence_score'],
                $p['source'],
                $compStr,
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * POST /api/custom-lab/match
     * Match a custom Lab value against a series.
     */
    public function customLabMatch(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $L      = (float) ($input['L'] ?? 0);
        $a      = (float) ($input['a'] ?? 0);
        $b      = (float) ($input['b'] ?? 0);
        $series = trim($input['series'] ?? '');
        $k      = (int) ($input['k'] ?? (int) get_setting('prediction_k', '5'));
        $noiseThreshold = (int) ($input['noiseThreshold'] ?? (int) get_setting('noise_threshold', '2'));

        if ($series === '') {
            json_response(['error' => 'Series name required'], 400);
            return;
        }

        if ($L < 0 || $L > 100) {
            json_response(['error' => 'L* must be between 0 and 100'], 422);
            return;
        }
        if ($a < -128 || $a > 128 || $b < -128 || $b > 128) {
            json_response(['error' => 'a* and b* must be between -128 and 128'], 422);
            return;
        }

        try {
            $svc = new CMSFormulaService();
            $seriesFormulas = $svc->getSeriesFormulas($series);

            // Build anchor list
            $anchors = [];
            foreach ($seriesFormulas as $item) {
                $colorPart = $item['color_part'] ?? '';
                $pmsNumber = CMSFormulaService::extractPmsNumber($colorPart);
                $labData = PantoneLabService::getLabForColor($pmsNumber);
                if (!$labData || empty($item['components'])) {
                    continue;
                }

                $components = [];
                foreach ($item['components'] as $c) {
                    $components[] = [
                        'code'        => $c['component_code'],
                        'description' => $c['component_description'],
                        'percentage'  => (float) $c['percentage'],
                    ];
                }

                $anchors[] = [
                    'itemCode'   => $item['ItemCode'],
                    'pmsNumber'  => $pmsNumber,
                    'pmsName'    => $colorPart,
                    'lab'        => ['L' => (float) $labData['L'], 'a' => (float) $labData['a'], 'b' => (float) $labData['b']],
                    'components' => $components,
                ];
            }

            if (empty($anchors)) {
                json_response(['error' => 'No valid anchors with Lab data found in this series'], 400);
                return;
            }

            $targetLab = ['L' => $L, 'a' => $a, 'b' => $b];
            $result = InterpolationEngine::predict($targetLab, $anchors, $k, $noiseThreshold);
            $hex = PantoneLabService::labToHex($L, $a, $b);

            json_response([
                'lab'            => $targetLab,
                'hex'            => $hex,
                'series'         => $series,
                'confidence'     => $result['confidence'],
                'components'     => $result['components'],
                'nearestAnchors' => $result['nearestAnchors'],
                'warnings'       => $result['warnings'],
                'anchorCount'    => count($anchors),
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'Match failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/cms/test
     * Test the CMS database connection.
     */
    public function testCmsConnection(): void
    {
        if (!is_admin()) {
            json_response(['error' => 'Admin only'], 403);
            return;
        }

        CMSDatabase::reset();
        try {
            $result = CMSDatabase::getInstance()->testConnection();
            json_response($result);
        } catch (\Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ── Private helpers ──────────────────────────────────────

    private function doSave(array $input): void
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $result = $this->doSaveSingle($db, $input);
            $db->commit();

            if ($result === 'exists') {
                json_response(['error' => 'Prediction already exists for this series/color', 'exists' => true], 409);
            } else {
                json_response(['success' => true, 'id' => $result]);
            }
        } catch (\Throwable $e) {
            $db->rollback();
            json_response(['error' => 'Save failed: ' . $e->getMessage()], 500);
        }
    }

    private function doSaveSingle(Database $db, array $data): string|int
    {
        $series    = $data['series'] ?? '';
        $pmsNumber = $data['pmsNumber'] ?? '';
        $source    = $data['source'] ?? 'predicted';

        // Check for existing
        $existing = $db->fetch(
            "SELECT id FROM predictions WHERE series_name = ? AND pms_number = ?",
            [$series, $pmsNumber]
        );
        if ($existing) {
            return 'exists';
        }

        $predictionId = $db->insert('predictions', [
            'series_name'      => $series,
            'pms_number'       => $pmsNumber,
            'pms_name'         => $data['pmsName'] ?? '',
            'lab_l'            => (float) ($data['lab']['L'] ?? 0),
            'lab_a'            => (float) ($data['lab']['a'] ?? 0),
            'lab_b'            => (float) ($data['lab']['b'] ?? 0),
            'confidence_score' => (float) ($data['confidence'] ?? 0),
            'nearest_anchors'  => json_encode($data['nearestAnchors'] ?? []),
            'source'           => $source,
            'notes'            => $data['notes'] ?? null,
            'created_by'       => current_user_id(),
        ]);

        foreach ($data['components'] ?? [] as $i => $comp) {
            $db->insert('prediction_components', [
                'prediction_id'         => $predictionId,
                'component_code'        => $comp['code'] ?? '',
                'component_description' => $comp['description'] ?? '',
                'percentage'            => (float) ($comp['percentage'] ?? 0),
                'sort_order'            => $comp['sort_order'] ?? $i,
            ]);
        }

        return $predictionId;
    }
}
