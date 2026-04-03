<?php

declare(strict_types=1);

namespace PantonePredictor\Services;

class InterpolationEngine
{
    /**
     * Predict a pigment-only formula for a target Lab color.
     *
     * Each anchor's components should already be pigment-only and normalized to 100%.
     * The output formula will be 100% pigment materials.
     *
     * @param array $targetLab      ['L' => float, 'a' => float, 'b' => float]
     * @param array $anchorFormulas Array of anchors, each with:
     *   'pmsNumber', 'lab' => ['L','a','b'], 'components' => [['code','description','percentage'], ...]
     * @param int   $k              Number of nearest neighbors
     * @param int   $noiseThreshold Minimum anchor count for a component to survive
     */
    public static function predict(
        array $targetLab,
        array $anchorFormulas,
        int $k = 5,
        int $noiseThreshold = 2
    ): array {
        $warnings = [];

        if (empty($anchorFormulas)) {
            return [
                'components'     => [],
                'confidence'     => 0,
                'nearestAnchors' => [],
                'warnings'       => ['No anchor formulas available.'],
            ];
        }

        // Step 1: Compute distances (Delta-E76)
        foreach ($anchorFormulas as &$anchor) {
            $anchor['distance'] = self::deltaE76($targetLab, $anchor['lab']);
        }
        unset($anchor);

        usort($anchorFormulas, fn($a, $b) => $a['distance'] <=> $b['distance']);

        // Step 2: Select K nearest
        $actualK = min($k, count($anchorFormulas));
        $nearest = array_slice($anchorFormulas, 0, $actualK);

        if ($actualK < $k) {
            $warnings[] = "Only {$actualK} anchors available (recommended: {$k}).";
        }
        if ($actualK < 3) {
            $warnings[] = 'Very few anchors — prediction may be unreliable.';
        }

        // Exact match shortcut
        if ($nearest[0]['distance'] < 0.5) {
            $comps = [];
            foreach ($nearest[0]['components'] as $i => $c) {
                $comps[] = [
                    'code'        => $c['code'],
                    'description' => $c['description'],
                    'percentage'  => round($c['percentage'], 6),
                    'sort_order'  => $i,
                ];
            }
            return [
                'components'     => $comps,
                'confidence'     => 100.0,
                'nearestAnchors' => [self::anchorSummary($nearest[0])],
                'warnings'       => [],
            ];
        }

        // Step 3: Inverse-distance weights
        $epsilon = 0.0001;
        $totalWeight = 0;
        foreach ($nearest as &$anch) {
            $anch['weight'] = 1.0 / ($anch['distance'] + $epsilon);
            $totalWeight += $anch['weight'];
        }
        unset($anch);

        foreach ($nearest as &$anch) {
            $anch['normWeight'] = $anch['weight'] / $totalWeight;
        }
        unset($anch);

        // Step 4: Weighted blend of pigment components
        $blended = [];
        $anchorCount = [];

        foreach ($nearest as $anch) {
            foreach ($anch['components'] as $comp) {
                $code = $comp['code'];
                if (!isset($blended[$code])) {
                    $blended[$code] = [
                        'code'        => $code,
                        'description' => $comp['description'],
                        'weightedQty' => 0.0,
                    ];
                    $anchorCount[$code] = 0;
                }
                $blended[$code]['weightedQty'] += $comp['percentage'] * $anch['normWeight'];
                $anchorCount[$code]++;
            }
        }

        // Step 5: Noise filter
        if ($actualK >= $noiseThreshold) {
            foreach ($blended as $code => $comp) {
                if ($anchorCount[$code] < $noiseThreshold) {
                    unset($blended[$code]);
                }
            }
        }

        // Step 6: Normalize to 100% (pigment-only)
        $total = array_sum(array_column($blended, 'weightedQty'));
        if ($total > 0) {
            foreach ($blended as &$comp) {
                $comp['percentage'] = $comp['weightedQty'] / $total;
            }
            unset($comp);
        }

        // Sort by percentage descending
        usort($blended, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

        $components = [];
        foreach (array_values($blended) as $i => $comp) {
            $components[] = [
                'code'        => $comp['code'],
                'description' => $comp['description'],
                'percentage'  => round($comp['percentage'], 6),
                'sort_order'  => $i,
            ];
        }

        // Step 7: Confidence score
        $avgDist = array_sum(array_column($nearest, 'distance')) / $actualK;
        $confidence = max(0, min(100, 100 - ($avgDist * 2)));
        if ($actualK < $k) {
            $confidence *= ($actualK / $k);
        }
        $confidence = round($confidence, 1);

        return [
            'components'     => $components,
            'confidence'     => $confidence,
            'nearestAnchors' => array_map([self::class, 'anchorSummary'], $nearest),
            'warnings'       => $warnings,
        ];
    }

    /**
     * Euclidean distance in Lab space (Delta E76).
     */
    public static function deltaE76(array $lab1, array $lab2): float
    {
        return sqrt(
            ($lab1['L'] - $lab2['L']) ** 2 +
            ($lab1['a'] - $lab2['a']) ** 2 +
            ($lab1['b'] - $lab2['b']) ** 2
        );
    }

    private static function anchorSummary(array $anchor): array
    {
        return [
            'pmsNumber' => $anchor['pmsNumber'] ?? '',
            'pmsName'   => $anchor['pmsName'] ?? '',
            'distance'  => round($anchor['distance'], 2),
            'itemCode'  => $anchor['itemCode'] ?? '',
        ];
    }
}
