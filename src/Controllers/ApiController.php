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
     * Get ALL formulas in a series with pigment breakdown.
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
                // Build pigment summary
                $pigmentSummary = array_map(function ($p) {
                    return $p['component_code'] . ' (' . round((float)$p['percentage'] * 100, 1) . '%)';
                }, array_slice($item['pigments'], 0, 4));

                $formulas[] = [
                    'itemCode'       => $item['ItemCode'],
                    'description'    => $item['Description'],
                    'detectedPms'    => $item['detected_pms'] ?? '',
                    'pigmentCount'   => $item['pigment_count'],
                    'pigmentTotal'   => round($item['pigment_total'] * 100, 1),
                    'pigmentSummary' => implode(', ', $pigmentSummary),
                    'hasPigments'    => $item['pigment_count'] > 0,
                ];
            }

            json_response([
                'series'   => $series,
                'formulas' => $formulas,
                'total'    => count($formulas),
                'withPigments' => count(array_filter($formulas, fn($f) => $f['hasPigments'])),
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'Failed to load formulas: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/predictions/generate
     * Generate predictions using user-assigned PMS numbers on anchors.
     *
     * Expects JSON body:
     * {
     *   "series": "O/S S/F PRIMASET",
     *   "anchors": [
     *     {"itemCode": "E1026", "pmsNumber": "286"},
     *     {"itemCode": "E1054", "pmsNumber": "Yellow"},
     *     ...
     *   ],
     *   "k": 5,
     *   "noiseThreshold": 2
     * }
     */
    public function generate(): void
    {
        CSRF::validateRequest();

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $series        = trim($input['series'] ?? '');
        $anchorInputs  = $input['anchors'] ?? [];
        $k             = (int) ($input['k'] ?? (int) get_setting('prediction_k', '5'));
        $noiseThreshold = (int) ($input['noiseThreshold'] ?? (int) get_setting('noise_threshold', '2'));

        if ($series === '' || empty($anchorInputs)) {
            json_response(['error' => 'Series and at least one anchor required'], 400);
            return;
        }

        try {
            $startTime = microtime(true);

            // Get item codes from input
            $itemCodes = array_column($anchorInputs, 'itemCode');
            $pmsMap = []; // itemCode => pmsNumber
            foreach ($anchorInputs as $a) {
                $pmsMap[$a['itemCode']] = trim($a['pmsNumber'] ?? '');
            }

            // Load pigment-only components from CMS (normalized to 100%)
            $svc = new CMSFormulaService();
            $formulaData = $svc->getPigmentComponents($itemCodes);

            // Build anchor list with Lab coordinates
            $anchors = [];
            $skippedAnchors = [];

            foreach ($formulaData as $code => $item) {
                $pmsNumber = $pmsMap[$code] ?? '';
                if ($pmsNumber === '') {
                    $skippedAnchors[] = ['itemCode' => $code, 'reason' => 'No PMS number assigned'];
                    continue;
                }

                $labData = PantoneLabService::getLabForColor($pmsNumber);
                if (!$labData) {
                    $skippedAnchors[] = ['itemCode' => $code, 'pms' => $pmsNumber, 'reason' => 'PMS number not found in Lab dataset'];
                    continue;
                }

                if (empty($item['pigments'])) {
                    $skippedAnchors[] = ['itemCode' => $code, 'reason' => 'No pigment components'];
                    continue;
                }

                $anchors[] = [
                    'itemCode'   => $code,
                    'pmsNumber'  => $pmsNumber,
                    'pmsName'    => $labData['name'] ?? $pmsNumber,
                    'lab'        => ['L' => (float) $labData['L'], 'a' => (float) $labData['a'], 'b' => (float) $labData['b']],
                    'components' => $item['pigments'], // Already normalized to 100%
                ];
            }

            if (empty($anchors)) {
                json_response([
                    'error' => 'No valid anchors. Make sure PMS numbers are assigned and exist in the reference dataset.',
                    'skippedAnchors' => $skippedAnchors,
                ], 400);
                return;
            }

            // Get all PMS colors
            $allPms = PantoneLabService::getAllColors();

            // Get PMS numbers already used as anchors
            $usedPms = [];
            foreach ($anchors as $a) {
                $usedPms[$a['pmsNumber']] = true;
            }

            // Generate predictions for all PMS colors not already an anchor
            $predictions = [];
            $skippedColors = 0;

            foreach ($allPms as $pmsKey => $pmsData) {
                if (isset($usedPms[$pmsKey])) {
                    continue;
                }

                $targetLab = [
                    'L' => (float) $pmsData['L'],
                    'a' => (float) $pmsData['a'],
                    'b' => (float) $pmsData['b'],
                ];

                $result = InterpolationEngine::predict($targetLab, $anchors, $k, $noiseThreshold);

                if (empty($result['components'])) {
                    $skippedColors++;
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

            usort($predictions, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

            $elapsed = round((microtime(true) - $startTime) * 1000);

            $globalWarnings = [];
            if (count($anchors) < 20) {
                $globalWarnings[] = 'Fewer than 20 anchors. Predictions are more reliable with more anchors.';
            }
            if (!empty($skippedAnchors)) {
                $globalWarnings[] = count($skippedAnchors) . ' anchor(s) skipped (see details).';
            }

            json_response([
                'series'         => $series,
                'predictions'    => $predictions,
                'total'          => count($predictions),
                'skippedColors'  => $skippedColors,
                'anchorCount'    => count($anchors),
                'skippedAnchors' => $skippedAnchors,
                'elapsed_ms'     => $elapsed,
                'warnings'       => $globalWarnings,
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'Prediction failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/predictions/save-batch
     */
    public function saveBatch(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $items  = $input['predictions'] ?? [];
        $series = $input['series'] ?? '';

        if (empty($items)) {
            json_response(['error' => 'No predictions to save'], 400);
            return;
        }

        $db = Database::getInstance();
        $saved = 0;
        $skipped = 0;

        $db->beginTransaction();
        try {
            foreach ($items as $item) {
                $item['series'] = $series;
                $result = $this->doSaveSingle($db, $item);
                if ($result === 'exists') { $skipped++; } else { $saved++; }
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
     * POST /api/predictions/save
     */
    public function savePrediction(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $result = $this->doSaveSingle($db, $input);
            $db->commit();
            if ($result === 'exists') {
                json_response(['error' => 'Prediction already exists', 'exists' => true], 409);
            } else {
                json_response(['success' => true, 'id' => $result]);
            }
        } catch (\Throwable $e) {
            $db->rollback();
            json_response(['error' => 'Save failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/predictions/delete
     */
    public function deletePrediction(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) { json_response(['error' => 'Invalid ID'], 400); return; }
        Database::getInstance()->query("DELETE FROM predictions WHERE id = ?", [$id]);
        json_response(['success' => true]);
    }

    /**
     * GET /api/predictions/export?series=...
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
        fputcsv($out, ['Series', 'PMS Number', 'PMS Name', 'L*', 'a*', 'b*', 'Confidence', 'Source', 'Pigment Components']);

        foreach ($predictions as $p) {
            $comps = $componentsByPrediction[$p['id']] ?? [];
            $compStr = implode('; ', array_map(
                fn($c) => $c['component_code'] . ' ' . round($c['percentage'] * 100, 2) . '%',
                $comps
            ));
            fputcsv($out, [
                $p['series_name'], $p['pms_number'], $p['pms_name'],
                $p['lab_l'], $p['lab_a'], $p['lab_b'],
                $p['confidence_score'], $p['source'], $compStr,
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * POST /api/custom-lab/match
     */
    public function customLabMatch(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $L      = (float) ($input['L'] ?? 0);
        $a      = (float) ($input['a'] ?? 0);
        $b      = (float) ($input['b'] ?? 0);
        $series = trim($input['series'] ?? '');
        $anchorInputs = $input['anchors'] ?? [];
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

            // If specific anchors provided, use those; otherwise use all series formulas
            if (!empty($anchorInputs)) {
                $itemCodes = array_column($anchorInputs, 'itemCode');
                $pmsMap = [];
                foreach ($anchorInputs as $ai) {
                    $pmsMap[$ai['itemCode']] = trim($ai['pmsNumber'] ?? '');
                }
                $formulaData = $svc->getPigmentComponents($itemCodes);
            } else {
                // Fallback: use all formulas with detected PMS numbers
                $seriesFormulas = $svc->getSeriesFormulas($series);
                $itemCodes = [];
                $pmsMap = [];
                foreach ($seriesFormulas as $item) {
                    if (!empty($item['detected_pms']) && $item['pigment_count'] > 0) {
                        $itemCodes[] = $item['ItemCode'];
                        $pmsMap[$item['ItemCode']] = $item['detected_pms'];
                    }
                }
                $formulaData = $svc->getPigmentComponents($itemCodes);
            }

            // Build anchor list
            $anchors = [];
            foreach ($formulaData as $code => $item) {
                $pmsNumber = $pmsMap[$code] ?? '';
                if ($pmsNumber === '') continue;
                $labData = PantoneLabService::getLabForColor($pmsNumber);
                if (!$labData || empty($item['pigments'])) continue;

                $anchors[] = [
                    'itemCode'   => $code,
                    'pmsNumber'  => $pmsNumber,
                    'pmsName'    => $labData['name'] ?? $pmsNumber,
                    'lab'        => ['L' => (float) $labData['L'], 'a' => (float) $labData['a'], 'b' => (float) $labData['b']],
                    'components' => $item['pigments'],
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
     */
    public function testCmsConnection(): void
    {
        if (!is_admin()) { json_response(['error' => 'Admin only'], 403); return; }
        CMSDatabase::reset();
        try {
            json_response(CMSDatabase::getInstance()->testConnection());
        } catch (\Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ── Private ──────────────────────────────────────────────

    private function doSaveSingle(Database $db, array $data): string|int
    {
        $series    = $data['series'] ?? '';
        $pmsNumber = $data['pmsNumber'] ?? '';
        $source    = $data['source'] ?? 'predicted';

        $existing = $db->fetch(
            "SELECT id FROM predictions WHERE series_name = ? AND pms_number = ?",
            [$series, $pmsNumber]
        );
        if ($existing) return 'exists';

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
