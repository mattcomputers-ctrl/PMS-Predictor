<?php

declare(strict_types=1);

namespace PantonePredictor\Services;

use PantonePredictor\Core\Database;

class SyncService
{
    /**
     * Pull all Pantone formulas from CMS into local database.
     * Only syncs items that have "PANTONE" in the description and a CostingRecipe.
     * Returns stats about what was synced.
     */
    public static function syncFromCMS(): array
    {
        $cms = CMSDatabase::getInstance();
        $db  = Database::getInstance();

        // Pull all Pantone items with their recipe components
        $items = $cms->fetchAll("
            SELECT
                i.ItemCode,
                i.Description,
                i.CostingRecipe
            FROM Item i
            WHERE i.Description LIKE '%PANTONE%'
              AND i.CostingRecipe IS NOT NULL
              AND CHARINDEX('-', i.ItemCode) = 0
            ORDER BY i.Description
        ");

        if (empty($items)) {
            return ['synced' => 0, 'components' => 0, 'message' => 'No Pantone formulas found in CMS.'];
        }

        // Batch load all recipe components
        $recipeIds = array_unique(array_filter(array_column($items, 'CostingRecipe')));
        $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
        $allComponents = $cms->fetchAll("
            SELECT
                rd.Recipe,
                ing.ItemCode AS component_code,
                ing.Description AS component_description,
                rd.QtyReqd AS percentage,
                rd.Line
            FROM RecipeDetail rd
            JOIN Item ing ON ing.Item = rd.Item
            WHERE rd.Recipe IN ({$placeholders})
              AND rd.Context = 'UI'
            ORDER BY rd.Recipe, rd.Line
        ", array_values($recipeIds));

        // Group components by recipe
        $componentsByRecipe = [];
        foreach ($allComponents as $c) {
            $componentsByRecipe[$c['Recipe']][] = $c;
        }

        // Write to local database
        $synced = 0;
        $totalComponents = 0;

        $db->beginTransaction();
        try {
            // Clear existing synced data
            $db->query("DELETE FROM cms_formula_components");
            $db->query("DELETE FROM cms_formulas");

            foreach ($items as $item) {
                $desc = $item['Description'];

                // Extract series prefix (everything before PANTONE)
                $pantonePos = stripos($desc, 'PANTONE');
                $seriesPrefix = $pantonePos > 0 ? rtrim(substr($desc, 0, $pantonePos)) : '';

                // Extract PMS number from description
                $detectedPms = '';
                if (preg_match('/PANTONE\s+(\S+)/i', $desc, $m)) {
                    $detectedPms = $m[1];
                }

                $formulaId = $db->insert('cms_formulas', [
                    'item_code'     => $item['ItemCode'],
                    'description'   => $desc,
                    'series_prefix' => $seriesPrefix,
                    'detected_pms'  => $detectedPms,
                    'user_pms'      => $detectedPms, // Pre-fill with detected
                    'is_anchor'     => 0,
                ]);

                $components = $componentsByRecipe[$item['CostingRecipe']] ?? [];
                foreach ($components as $i => $c) {
                    $isPigment = CMSFormulaService::isPigment($c['component_code']) ? 1 : 0;
                    $db->insert('cms_formula_components', [
                        'formula_id'            => $formulaId,
                        'component_code'        => $c['component_code'],
                        'component_description' => $c['component_description'],
                        'percentage'            => (float) $c['percentage'],
                        'is_pigment'            => $isPigment,
                        'sort_order'            => $i,
                    ]);
                    $totalComponents++;
                }

                $synced++;
            }

            // Store sync timestamp
            $db->query(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('last_sync', NOW())
                 ON DUPLICATE KEY UPDATE setting_value = NOW()"
            );

            $db->commit();

            return [
                'synced'     => $synced,
                'components' => $totalComponents,
                'message'    => "Synced {$synced} formulas with {$totalComponents} components from CMS.",
            ];
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Get distinct series prefixes from synced formulas.
     */
    public static function getSeriesList(): array
    {
        $db = Database::getInstance();
        return $db->fetchAll("
            SELECT series_prefix, COUNT(*) AS formula_count
            FROM cms_formulas
            WHERE series_prefix != ''
            GROUP BY series_prefix
            HAVING formula_count >= 3
            ORDER BY formula_count DESC
        ");
    }

    /**
     * Get formulas for a series, with pigment components.
     */
    public static function getSeriesFormulas(string $seriesPrefix): array
    {
        $db = Database::getInstance();
        $formulas = $db->fetchAll("
            SELECT *
            FROM cms_formulas
            WHERE series_prefix = ?
            ORDER BY description
        ", [$seriesPrefix]);

        if (empty($formulas)) {
            return [];
        }

        // Load pigment components
        $ids = array_column($formulas, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $comps = $db->fetchAll("
            SELECT * FROM cms_formula_components
            WHERE formula_id IN ({$ph}) AND is_pigment = 1
            ORDER BY formula_id, sort_order
        ", $ids);

        $compsByFormula = [];
        foreach ($comps as $c) {
            $compsByFormula[$c['formula_id']][] = $c;
        }

        foreach ($formulas as &$f) {
            $f['pigments'] = $compsByFormula[$f['id']] ?? [];
            $pigmentTotal = 0;
            foreach ($f['pigments'] as $p) {
                $pigmentTotal += (float) $p['percentage'];
            }
            $f['pigment_total'] = $pigmentTotal;
        }

        return $formulas;
    }

    /**
     * Update user-assigned PMS number and anchor status for a formula.
     */
    public static function updateFormulaPms(int $formulaId, string $pmsNumber, bool $isAnchor): void
    {
        Database::getInstance()->query(
            "UPDATE cms_formulas SET user_pms = ?, is_anchor = ? WHERE id = ?",
            [$pmsNumber, $isAnchor ? 1 : 0, $formulaId]
        );
    }

    /**
     * Bulk update anchor selections and PMS numbers.
     */
    public static function bulkUpdateAnchors(array $updates): void
    {
        $db = Database::getInstance();
        // First reset all anchors for affected series
        $db->beginTransaction();
        try {
            foreach ($updates as $u) {
                $db->query(
                    "UPDATE cms_formulas SET user_pms = ?, is_anchor = ? WHERE id = ?",
                    [$u['pms'] ?? '', $u['anchor'] ? 1 : 0, (int) $u['id']]
                );
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
