<?php

declare(strict_types=1);

namespace PantonePredictor\Services;

class InterpolationEngine
{
    /**
     * Metamerism penalty weight.
     * Higher = stronger preference for anchors sharing the same pigments.
     * 0 = pure Lab distance (original behavior).
     */
    private const METAMERISM_PENALTY = 15.0;

    /**
     * Predict a pigment-only formula for a target Lab color.
     * Uses a metamerism-aware selection: prefers anchors that share pigments
     * with the closest anchor, reducing the chance of mixing dissimilar
     * pigment chemistries that could cause metamerism.
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

        // Step 1: Compute Lab distances
        foreach ($anchorFormulas as &$anchor) {
            $anchor['distance'] = self::deltaE76($targetLab, $anchor['lab']);
            $anchor['pigmentSet'] = self::getPigmentSet($anchor['components']);
        }
        unset($anchor);

        // Sort by Lab distance to find the closest anchor
        usort($anchorFormulas, fn($a, $b) => $a['distance'] <=> $b['distance']);

        // Step 2: Anti-metamerism scoring
        // The reference pigment set is from the closest anchor in Lab space.
        // All other anchors are scored by Lab distance + a penalty for using
        // different pigments. This pushes the K selection toward anchors
        // that share pigment chemistry with the nearest match.
        $referencePigments = $anchorFormulas[0]['pigmentSet'];

        foreach ($anchorFormulas as &$anchor) {
            $pigmentDissimilarity = self::jaccardDistance($referencePigments, $anchor['pigmentSet']);
            $anchor['metamerismScore'] = $anchor['distance'] + ($pigmentDissimilarity * self::METAMERISM_PENALTY);
        }
        unset($anchor);

        // Re-sort by metamerism-aware score
        usort($anchorFormulas, fn($a, $b) => $a['metamerismScore'] <=> $b['metamerismScore']);

        // Step 3: Select K nearest (by metamerism-aware score)
        $actualK = min($k, count($anchorFormulas));
        $nearest = array_slice($anchorFormulas, 0, $actualK);

        if ($actualK < $k) {
            $warnings[] = "Only {$actualK} anchors available (recommended: {$k}).";
        }
        if ($actualK < 3) {
            $warnings[] = 'Very few anchors — prediction may be unreliable.';
        }

        // Step 4: Inverse-distance weights (using Lab distance, not metamerism score)
        // We select neighbors with the metamerism-aware score, but weight them
        // by Lab distance so closer colors still contribute more.
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

        // Step 5: Weighted blend of pigment components
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

        // Step 6: Noise filter
        if ($actualK >= $noiseThreshold) {
            foreach ($blended as $code => $comp) {
                if ($anchorCount[$code] < $noiseThreshold) {
                    unset($blended[$code]);
                }
            }
        }

        // Step 7: Normalize to 100%
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

        // Step 8: Confidence score
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

    /**
     * Extract the set of pigment codes from a component list.
     */
    private static function getPigmentSet(array $components): array
    {
        $set = [];
        foreach ($components as $c) {
            $set[$c['code']] = true;
        }
        return $set;
    }

    /**
     * Jaccard distance: 1 - (intersection / union).
     * 0 = identical pigment sets, 1 = completely different.
     */
    private static function jaccardDistance(array $setA, array $setB): float
    {
        if (empty($setA) && empty($setB)) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return 1.0 - ($intersection / $union);
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
