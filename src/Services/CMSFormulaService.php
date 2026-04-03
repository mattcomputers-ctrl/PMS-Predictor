<?php

declare(strict_types=1);

namespace PantonePredictor\Services;

class CMSFormulaService
{
    private CMSDatabase $cms;

    public function __construct()
    {
        $this->cms = CMSDatabase::getInstance();
    }

    /**
     * Search for distinct series prefixes matching a query string.
     * Returns array of ['series_name' => ..., 'formula_count' => ...].
     */
    public function searchSeries(string $query): array
    {
        $sql = "
            SELECT
                RTRIM(LEFT(i.Description, CHARINDEX('PANTONE', i.Description) - 1)) AS series_name,
                COUNT(*) AS formula_count
            FROM Item i
            WHERE i.Description LIKE '%PANTONE%'
              AND i.CostingRecipe IS NOT NULL
              AND CHARINDEX('-', i.ItemCode) = 0
              AND RTRIM(LEFT(i.Description, CHARINDEX('PANTONE', i.Description) - 1)) LIKE ?
            GROUP BY RTRIM(LEFT(i.Description, CHARINDEX('PANTONE', i.Description) - 1))
            ORDER BY formula_count DESC
        ";

        return $this->cms->fetchAll($sql, ['%' . $query . '%']);
    }

    /**
     * Get all Pantone formulas in a series with their components.
     * Returns array of items with embedded component arrays.
     */
    public function getSeriesFormulas(string $seriesName): array
    {
        // First get all items in this series
        $itemsSql = "
            SELECT
                i.Item AS item_id,
                i.ItemCode,
                i.Description,
                i.CostingRecipe,
                LTRIM(SUBSTRING(i.Description, CHARINDEX('PANTONE', i.Description) + 8, 200)) AS color_part
            FROM Item i
            WHERE i.Description LIKE ? + ' PANTONE%'
              AND i.CostingRecipe IS NOT NULL
              AND CHARINDEX('-', i.ItemCode) = 0
            ORDER BY i.Description
        ";

        $items = $this->cms->fetchAll($itemsSql, [$seriesName]);

        if (empty($items)) {
            return [];
        }

        // Batch load all recipe components
        $recipeIds = array_unique(array_column($items, 'CostingRecipe'));
        if (empty($recipeIds)) {
            return $items;
        }

        $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
        $componentsSql = "
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

        $allComponents = $this->cms->fetchAll($componentsSql, array_values($recipeIds));

        // Group components by recipe ID
        $componentsByRecipe = [];
        foreach ($allComponents as $comp) {
            $componentsByRecipe[$comp['Recipe']][] = $comp;
        }

        // Attach components to items
        foreach ($items as &$item) {
            $item['components'] = $componentsByRecipe[$item['CostingRecipe']] ?? [];
        }

        return $items;
    }

    /**
     * Get components for specific items by their item codes.
     * Used when loading selected anchor formulas.
     */
    public function getFormulaComponents(array $itemCodes): array
    {
        if (empty($itemCodes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemCodes), '?'));
        $sql = "
            SELECT
                parent.ItemCode AS parent_code,
                parent.Description AS parent_description,
                parent.CostingRecipe,
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

        // Group by parent item code
        $grouped = [];
        foreach ($rows as $row) {
            $code = $row['parent_code'];
            if (!isset($grouped[$code])) {
                $grouped[$code] = [
                    'itemCode'    => $code,
                    'description' => $row['parent_description'],
                    'components'  => [],
                ];
            }
            $grouped[$code]['components'][] = [
                'code'        => $row['component_code'],
                'description' => $row['component_description'],
                'percentage'  => (float) $row['percentage'],
            ];
        }

        return $grouped;
    }

    /**
     * Extract the PMS number from a color part string.
     * e.g., "286 BLUE" -> "286", "WARM RED" -> "WARM RED"
     */
    public static function extractPmsNumber(string $colorPart): string
    {
        $colorPart = trim($colorPart);
        // Try extracting leading number with optional suffix
        if (preg_match('/^(\d+[A-Z]?)\b/', $colorPart, $m)) {
            return $m[1];
        }
        // Named color: take first meaningful words
        return strtoupper(trim(preg_replace('/\s*\(.*\)$/', '', $colorPart)));
    }
}
