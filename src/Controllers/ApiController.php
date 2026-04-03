<?php

declare(strict_types=1);

namespace PantonePredictor\Controllers;

use PantonePredictor\Core\CSRF;
use PantonePredictor\Core\Database;
use PantonePredictor\Services\CMSDatabase;
use PantonePredictor\Services\CMSFormulaService;
use PantonePredictor\Services\InterpolationEngine;
use PantonePredictor\Services\PantoneLabService;
use PantonePredictor\Services\SyncService;

class ApiController
{
    /**
     * POST /api/sync — Pull Pantone formulas from CMS into local DB.
     */
    public function sync(): void
    {
        CSRF::validateRequest();

        if (!CMSDatabase::isConfigured()) {
            json_response(['error' => 'CMS not configured'], 503);
            return;
        }

        try {
            $result = SyncService::syncFromCMS();
            json_response($result);
        } catch (\Throwable $e) {
            json_response(['error' => 'Sync failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/series/list — Get distinct series from synced formulas.
     */
    public function seriesList(): void
    {
        try {
            $series = SyncService::getSeriesList();
            json_response($series);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/series/formulas?series=...
     * Search synced formulas whose description contains the series text AND "PANTONE".
     */
    public function seriesFormulas(): void
    {
        $series = trim($_GET['series'] ?? '');
        if ($series === '') {
            json_response(['error' => 'Series name required'], 400);
            return;
        }

        try {
            $formulas = SyncService::searchFormulas($series);

            $result = [];
            foreach ($formulas as $f) {
                $pigmentSummary = array_map(function ($p) {
                    return $p['component_code'] . ' (' . round((float)$p['percentage'] * 100, 1) . '%)';
                }, array_slice($f['pigments'], 0, 4));

                $result[] = [
                    'id'             => (int) $f['id'],
                    'itemCode'       => $f['item_code'],
                    'description'    => $f['description'],
                    'detectedPms'    => $f['detected_pms'],
                    'userPms'        => $f['user_pms'],
                    'isAnchor'       => (bool) $f['is_anchor'],
                    'pigmentCount'   => count($f['pigments']),
                    'pigmentTotal'   => round($f['pigment_total'] * 100, 1),
                    'pigmentSummary' => implode(', ', $pigmentSummary),
                    'hasPigments'    => count($f['pigments']) > 0,
                ];
            }

            json_response([
                'series'   => $series,
                'formulas' => $result,
                'total'    => count($result),
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/anchors/save — Save anchor selections and PMS numbers.
     * Body: { "updates": [{ "id": 123, "pms": "286", "anchor": true }, ...] }
     */
    public function saveAnchors(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $updates = $input['updates'] ?? [];

        if (empty($updates)) {
            json_response(['error' => 'No updates'], 400);
            return;
        }

        try {
            SyncService::bulkUpdateAnchors($updates);
            json_response(['success' => true, 'updated' => count($updates)]);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/predictions/generate
     * Generate predictions from checked anchors in local DB.
     */
    public function generate(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $series         = trim($input['series'] ?? '');
        $anchorInputs   = $input['anchors'] ?? []; // [{ id, pms }, ...]
        $k              = (int) ($input['k'] ?? (int) get_setting('prediction_k', '5'));
        $noiseThreshold = (int) ($input['noiseThreshold'] ?? (int) get_setting('noise_threshold', '2'));

        if ($series === '' || empty($anchorInputs)) {
            json_response(['error' => 'Series and at least one anchor required'], 400);
            return;
        }

        try {
            $startTime = microtime(true);
            $db = Database::getInstance();

            // Build anchors from local synced data
            $anchors = [];
            $skippedAnchors = [];

            foreach ($anchorInputs as $a) {
                $formulaId = (int) ($a['id'] ?? 0);
                $pmsNumber = trim($a['pms'] ?? '');

                if ($pmsNumber === '') {
                    $skippedAnchors[] = ['id' => $formulaId, 'reason' => 'No PMS number'];
                    continue;
                }

                $labData = PantoneLabService::getLabForColor($pmsNumber);
                if (!$labData) {
                    $skippedAnchors[] = ['id' => $formulaId, 'pms' => $pmsNumber, 'reason' => "PMS '$pmsNumber' not in Lab dataset"];
                    continue;
                }

                // Load pigment components for this formula
                $comps = $db->fetchAll(
                    "SELECT * FROM cms_formula_components WHERE formula_id = ? AND is_pigment = 1 ORDER BY sort_order",
                    [$formulaId]
                );

                if (empty($comps)) {
                    $skippedAnchors[] = ['id' => $formulaId, 'pms' => $pmsNumber, 'reason' => 'No pigment components'];
                    continue;
                }

                // Normalize pigments to 100%
                $pigTotal = 0;
                foreach ($comps as $c) { $pigTotal += (float) $c['percentage']; }

                $normalizedComps = [];
                foreach ($comps as $c) {
                    $normalizedComps[] = [
                        'code'        => $c['component_code'],
                        'description' => $c['component_description'],
                        'percentage'  => $pigTotal > 0 ? (float) $c['percentage'] / $pigTotal : 0,
                    ];
                }

                $formula = $db->fetch("SELECT item_code, description FROM cms_formulas WHERE id = ?", [$formulaId]);

                $anchors[] = [
                    'itemCode'   => $formula['item_code'] ?? '',
                    'pmsNumber'  => $pmsNumber,
                    'pmsName'    => $labData['name'] ?? $pmsNumber,
                    'lab'        => ['L' => (float) $labData['L'], 'a' => (float) $labData['a'], 'b' => (float) $labData['b']],
                    'components' => $normalizedComps,
                ];
            }

            if (empty($anchors)) {
                json_response([
                    'error' => 'No valid anchors. Check PMS numbers exist in the reference dataset.',
                    'skippedAnchors' => $skippedAnchors,
                ], 400);
                return;
            }

            // Get all PMS colors from reference dataset
            $allPms = PantoneLabService::getAllColors();

            // Generate predictions for ALL PMS colors (including anchors for comparison)
            $predictions = [];
            $skippedColors = 0;

            foreach ($allPms as $pmsKey => $pmsData) {

                $targetLab = [
                    'L' => (float) $pmsData['L'],
                    'a' => (float) $pmsData['a'],
                    'b' => (float) $pmsData['b'],
                ];

                // Exclude this color's own anchor so it must interpolate from others
                $filteredAnchors = array_values(array_filter($anchors, fn($a) => $a['pmsNumber'] !== $pmsKey));
                if (empty($filteredAnchors)) { $skippedColors++; continue; }

                $result = InterpolationEngine::predict($targetLab, $filteredAnchors, $k, $noiseThreshold);
                if (empty($result['components'])) { $skippedColors++; continue; }

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

            json_response([
                'series'         => $series,
                'predictions'    => $predictions,
                'total'          => count($predictions),
                'skippedColors'  => $skippedColors,
                'anchorCount'    => count($anchors),
                'skippedAnchors' => $skippedAnchors,
                'elapsed_ms'     => $elapsed,
                'warnings'       => count($anchors) < 20
                    ? ['Fewer than 20 anchors. More anchors = better predictions.'] : [],
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'Prediction failed: ' . $e->getMessage()], 500);
        }
    }

    // ── Save / Delete / Export (unchanged logic) ─────────────

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

    public function saveBatch(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $items  = $input['predictions'] ?? [];
        $series = $input['series'] ?? '';
        if (empty($items)) { json_response(['error' => 'No predictions to save'], 400); return; }

        $db = Database::getInstance();
        $saved = 0; $skipped = 0;
        $db->beginTransaction();
        try {
            foreach ($items as $item) {
                $item['series'] = $series;
                $result = $this->doSaveSingle($db, $item);
                if ($result === 'exists') { $skipped++; } else { $saved++; }
            }
            $db->commit();
            json_response(['success' => true, 'saved' => $saved, 'skipped' => $skipped,
                'message' => "{$saved} saved, {$skipped} skipped (already exist)."]);
        } catch (\Throwable $e) {
            $db->rollback();
            json_response(['error' => 'Save failed: ' . $e->getMessage()], 500);
        }
    }

    public function deletePrediction(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) { json_response(['error' => 'Invalid ID'], 400); return; }
        Database::getInstance()->query("DELETE FROM predictions WHERE id = ?", [$id]);
        json_response(['success' => true]);
    }

    public function export(): void
    {
        $series = trim($_GET['series'] ?? '');
        $db = Database::getInstance();
        $where = ''; $params = [];
        if ($series !== '') { $where = 'WHERE p.series_name = ?'; $params = [$series]; }

        $predictions = $db->fetchAll("SELECT p.* FROM predictions p {$where} ORDER BY p.series_name, p.pms_number", $params);
        $predictionIds = array_column($predictions, 'id');
        $cmap = [];
        if (!empty($predictionIds)) {
            $ph = implode(',', array_fill(0, count($predictionIds), '?'));
            foreach ($db->fetchAll("SELECT * FROM prediction_components WHERE prediction_id IN ({$ph}) ORDER BY sort_order", $predictionIds) as $c) {
                $cmap[$c['prediction_id']][] = $c;
            }
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="predictions_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Series', 'PMS Number', 'Name', 'L*', 'a*', 'b*', 'Confidence', 'Source', 'Pigment Components']);
        foreach ($predictions as $p) {
            $comps = $cmap[$p['id']] ?? [];
            fputcsv($out, [$p['series_name'], $p['pms_number'], $p['pms_name'],
                $p['lab_l'], $p['lab_a'], $p['lab_b'], $p['confidence_score'], $p['source'],
                implode('; ', array_map(fn($c) => $c['component_code'] . ' ' . round($c['percentage'] * 100, 2) . '%', $comps))]);
        }
        fclose($out);
        exit;
    }

    /**
     * GET /api/saved-series — List all saved series with counts.
     */
    public function savedSeriesList(): void
    {
        $db = Database::getInstance();
        $series = $db->fetchAll("
            SELECT series_name, COUNT(*) AS formula_count,
                   ROUND(AVG(confidence_score), 1) AS avg_confidence,
                   MAX(created_at) AS last_updated
            FROM predictions
            GROUP BY series_name
            ORDER BY series_name
        ");
        json_response($series);
    }

    /**
     * POST /api/saved-series/delete — Delete all predictions for a series.
     */
    public function deleteSeries(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $series = trim($input['series'] ?? '');
        if ($series === '') { json_response(['error' => 'Series required'], 400); return; }

        $db = Database::getInstance();
        // Delete components first (cascade should handle, but be explicit)
        $db->query("
            DELETE pc FROM prediction_components pc
            JOIN predictions p ON p.id = pc.prediction_id
            WHERE p.series_name = ?
        ", [$series]);
        $db->query("DELETE FROM predictions WHERE series_name = ?", [$series]);
        json_response(['success' => true, 'message' => "Deleted all predictions for '{$series}'."]);
    }

    /**
     * POST /api/custom-lab/match — Match custom Lab values against a SAVED series.
     * Uses saved predictions as anchors (not CMS formulas).
     */
    public function customLabMatch(): void
    {
        CSRF::validateRequest();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $L = (float)($input['L'] ?? 0); $a = (float)($input['a'] ?? 0); $b = (float)($input['b'] ?? 0);
        $series = trim($input['series'] ?? '');
        $k = (int)($input['k'] ?? (int) get_setting('prediction_k', '5'));
        $noiseThreshold = (int)($input['noiseThreshold'] ?? (int) get_setting('noise_threshold', '2'));

        if ($series === '') { json_response(['error' => 'Series required'], 400); return; }
        if ($L < 0 || $L > 100) { json_response(['error' => 'L* must be 0-100'], 422); return; }
        if ($a < -128 || $a > 128 || $b < -128 || $b > 128) { json_response(['error' => 'a*/b* must be -128 to 128'], 422); return; }

        try {
            $db = Database::getInstance();

            // Load saved predictions for this series as anchors
            $savedFormulas = $db->fetchAll("
                SELECT p.*, GROUP_CONCAT(pc.id) AS has_comps
                FROM predictions p
                LEFT JOIN prediction_components pc ON pc.prediction_id = p.id
                WHERE p.series_name = ?
                GROUP BY p.id
            ", [$series]);

            if (empty($savedFormulas)) {
                json_response(['error' => "No saved predictions for series '{$series}'. Generate and save predictions first."], 400);
                return;
            }

            // Build anchors from saved predictions
            $anchors = [];
            foreach ($savedFormulas as $f) {
                if (empty($f['has_comps'])) continue;

                $comps = $db->fetchAll(
                    "SELECT * FROM prediction_components WHERE prediction_id = ? ORDER BY sort_order",
                    [$f['id']]
                );

                $normalizedComps = [];
                foreach ($comps as $c) {
                    $normalizedComps[] = [
                        'code'        => $c['component_code'],
                        'description' => $c['component_description'],
                        'percentage'  => (float) $c['percentage'],
                    ];
                }

                $anchors[] = [
                    'itemCode'  => 'P-' . $f['id'],
                    'pmsNumber' => $f['pms_number'],
                    'pmsName'   => $f['pms_name'],
                    'lab'       => ['L' => (float)$f['lab_l'], 'a' => (float)$f['lab_a'], 'b' => (float)$f['lab_b']],
                    'components' => $normalizedComps,
                ];
            }

            if (empty($anchors)) {
                json_response(['error' => 'Saved predictions have no components.'], 400);
                return;
            }

            $targetLab = ['L' => $L, 'a' => $a, 'b' => $b];
            $result = InterpolationEngine::predict($targetLab, $anchors, $k, $noiseThreshold);

            json_response([
                'lab'            => $targetLab,
                'hex'            => PantoneLabService::labToHex($L, $a, $b),
                'series'         => $series,
                'confidence'     => $result['confidence'],
                'components'     => $result['components'],
                'nearestAnchors' => $result['nearestAnchors'],
                'warnings'       => $result['warnings'],
                'metamerism'     => $result['metamerism'] ?? null,
                'anchorCount'    => count($anchors),
            ]);
        } catch (\Throwable $e) {
            json_response(['error' => 'Match failed: ' . $e->getMessage()], 500);
        }
    }

    public function testCmsConnection(): void
    {
        if (!is_admin()) { json_response(['error' => 'Admin only'], 403); return; }
        CMSDatabase::reset();
        try { json_response(CMSDatabase::getInstance()->testConnection()); }
        catch (\Throwable $e) { json_response(['success' => false, 'message' => $e->getMessage()]); }
    }

    private function doSaveSingle(Database $db, array $data): string|int
    {
        $series = $data['series'] ?? ''; $pmsNumber = $data['pmsNumber'] ?? ''; $source = $data['source'] ?? 'predicted';
        $existing = $db->fetch("SELECT id FROM predictions WHERE series_name = ? AND pms_number = ?", [$series, $pmsNumber]);
        if ($existing) return 'exists';

        $predictionId = $db->insert('predictions', [
            'series_name' => $series, 'pms_number' => $pmsNumber, 'pms_name' => $data['pmsName'] ?? '',
            'lab_l' => (float)($data['lab']['L'] ?? 0), 'lab_a' => (float)($data['lab']['a'] ?? 0), 'lab_b' => (float)($data['lab']['b'] ?? 0),
            'confidence_score' => (float)($data['confidence'] ?? 0), 'nearest_anchors' => json_encode($data['nearestAnchors'] ?? []),
            'source' => $source, 'notes' => $data['notes'] ?? null, 'created_by' => current_user_id(),
        ]);
        foreach ($data['components'] ?? [] as $i => $comp) {
            $db->insert('prediction_components', [
                'prediction_id' => $predictionId, 'component_code' => $comp['code'] ?? '', 'component_description' => $comp['description'] ?? '',
                'percentage' => (float)($comp['percentage'] ?? 0), 'sort_order' => $comp['sort_order'] ?? $i,
            ]);
        }
        return $predictionId;
    }
}
