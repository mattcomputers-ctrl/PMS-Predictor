<?php

declare(strict_types=1);

namespace PantonePredictor\Services;

class CMSFormulaService
{
    /**
     * Pigment material prefixes — only these affect color.
     * All other components are additives.
     */
    public const PIGMENT_PREFIXES = ['2A', '3A', 'FL', '3U', '2U', 'DS'];

    private CMSDatabase $cms;

    public function __construct()
    {
        $this->cms = CMSDatabase::getInstance();
    }

    /**
     * Check if a component code is a pigment (color-bearing material).
     */
    public static function isPigment(string|int $componentCode): bool
    {
        $prefix = strtoupper(substr((string) $componentCode, 0, 2));
        return in_array($prefix, self::PIGMENT_PREFIXES, true);
    }

    /**
     * Search for distinct series prefixes.
     * A "series" is the text before the first specific color identifier in the description.
     * We search all items that have a CostingRecipe.
     */
    public function searchSeries(string $query): array
    {
        // Search for distinct description prefixes that match
        // We look for items where the description contains the search term
        $sql = "
            SELECT
                RTRIM(LEFT(i.Description, CHARINDEX('PANTONE', i.Description) - 1)) AS series_name,
                COUNT(*) AS formula_count
            FROM Item i
            WHERE i.Description LIKE '%PANTONE%'
              AND i.CostingRecipe IS NOT NULL
              AND CHARINDEX('-', i.ItemCode) = 0
              AND CHARINDEX('PANTONE', i.Description) > 1
              AND RTRIM(LEFT(i.Description, CHARINDEX('PANTONE', i.Description) - 1)) LIKE ?
            GROUP BY RTRIM(LEFT(i.Description, CHARINDEX('PANTONE', i.Description) - 1))
            HAVING COUNT(*) >= 3
            ORDER BY formula_count DESC
        ";

        return $this->cms->fetchAll($sql, ['%' . $query . '%']);
    }

    /**
     * Get ALL formulas in a series — not just those with PANTONE in the name.
     * Returns items with their pigment components (2A,3A,FL,3U,2U,DS only).
     */
    public function getSeriesFormulas(string $seriesName): array
    {
        // Get all items whose description starts with this series prefix
        $sql = "
            SELECT
                i.Item AS item_id,
                i.ItemCode,
                i.Description,
                i.CostingRecipe
            FROM Item i
            WHERE i.Description LIKE ? + '%'
              AND i.CostingRecipe IS NOT NULL
              AND CHARINDEX('-', i.ItemCode) = 0
            ORDER BY i.Description
        ";

        $items = $this->cms->fetchAll($sql, [$seriesName]);
        if (empty($items)) {
            return [];
        }

        // Batch load ALL recipe components
        $recipeIds = array_unique(array_filter(array_column($items, 'CostingRecipe')));
        if (empty($recipeIds)) {
            return $items;
        }

        $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
        $sql = "
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
        ";

        $allComponents = $this->cms->fetchAll($sql, array_values($recipeIds));

        // Group components by recipe
        $componentsByRecipe = [];
        foreach ($allComponents as $comp) {
            $componentsByRecipe[$comp['Recipe']][] = $comp;
        }

        // Attach to items, separate pigments from additives
        foreach ($items as &$item) {
            $allComps = $componentsByRecipe[$item['CostingRecipe']] ?? [];
            $pigments = [];
            $additives = [];

            foreach ($allComps as $c) {
                if (self::isPigment($c['component_code'])) {
                    $pigments[] = $c;
                } else {
                    $additives[] = $c;
                }
            }

            $item['pigments'] = $pigments;
            $item['additives'] = $additives;
            $item['all_components'] = $allComps;
            $item['pigment_count'] = count($pigments);

            // Calculate pigment-only total
            $pigmentTotal = 0;
            foreach ($pigments as $p) {
                $pigmentTotal += (float) $p['percentage'];
            }
            $item['pigment_total'] = $pigmentTotal;

            // Try to extract existing Pantone number from description
            $item['detected_pms'] = self::extractPmsFromDescription($item['Description'], $seriesName);
        }

        return $items;
    }

    /**
     * Get pigment-only components for specific items.
     * Returns components normalized to 100% among pigments only.
     */
    public function getPigmentComponents(array $itemCodes): array
    {
        if (empty($itemCodes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemCodes), '?'));
        $sql = "
            SELECT
                parent.ItemCode AS parent_code,
                parent.Description AS parent_description,
                ing.ItemCode AS component_code,
                ing.Description AS component_description,
                rd.QtyReqd AS percentage,
                rd.Line
            FROM Item parent
            JOIN RecipeDetail rd ON rd.Recipe = parent.CostingRecipe AND rd.Context = 'UI'
            JOIN Item ing ON ing.Item = rd.Item
            WHERE parent.ItemCode IN ({$placeholders})
            ORDER BY parent.ItemCode, rd.Line
        ";

        $rows = $this->cms->fetchAll($sql, array_values($itemCodes));

        // Group by parent, keep only pigments, normalize to 100%
        $grouped = [];
        foreach ($rows as $row) {
            $code = $row['parent_code'];
            if (!isset($grouped[$code])) {
                $grouped[$code] = [
                    'itemCode'    => $code,
                    'description' => $row['parent_description'],
                    'pigments'    => [],
                    'pigmentTotal' => 0,
                ];
            }

            if (self::isPigment($row['component_code'])) {
                $pct = (float) $row['percentage'];
                $grouped[$code]['pigments'][] = [
                    'code'        => $row['component_code'],
                    'description' => $row['component_description'],
                    'percentage'  => $pct,
                ];
                $grouped[$code]['pigmentTotal'] += $pct;
            }
        }

        // Normalize pigment percentages to sum to 1.0
        foreach ($grouped as &$item) {
            if ($item['pigmentTotal'] > 0) {
                foreach ($item['pigments'] as &$p) {
                    $p['percentage'] = $p['percentage'] / $item['pigmentTotal'];
                }
                unset($p);
            }
        }

        return $grouped;
    }

    /**
     * Try to extract a Pantone number from item description.
     * e.g., "O/S S/F PRIMASET PANTONE 286 BLUE" -> "286"
     */
    public static function extractPmsFromDescription(string $description, string $seriesPrefix = ''): string
    {
        // Remove series prefix to get the color part
        if ($seriesPrefix && stripos($description, $seriesPrefix) === 0) {
            $colorPart = trim(substr($description, strlen($seriesPrefix)));
        } else {
            $colorPart = $description;
        }

        // Try "PANTONE XXX" pattern
        if (preg_match('/PANTONE\s+(\d+[A-Z]?(?:\s+\w+)?)/i', $colorPart, $m)) {
            // Return just the number part
            $parts = preg_split('/\s+/', trim($m[1]));
            return $parts[0] ?? '';
        }

        return '';
    }
}
