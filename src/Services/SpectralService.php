<?php

declare(strict_types=1);

namespace PantonePredictor\Services;

/**
 * Spectral reconstruction from Lab values and metamerism detection.
 *
 * Reconstructs approximate spectral reflectance curves (31 points, 400-700nm
 * at 10nm intervals) from CIE L*a*b* values using CIE 1931 2-degree observer
 * color matching functions as basis vectors.
 *
 * The curves are approximate — not spectrally exact — but since both target
 * and prediction are reconstructed the same way, the crossing comparison
 * is valid for detecting metamerism risk.
 */
class SpectralService
{
    /** Wavelength range: 400-700nm in 10nm steps = 31 points */
    private const WAVELENGTHS = 31;
    private const LAMBDA_START = 400;
    private const LAMBDA_STEP = 10;

    /**
     * CIE 1931 2-degree observer color matching functions (400-700nm, 10nm steps).
     * Each sub-array is [x_bar, y_bar, z_bar] at that wavelength.
     */
    private const CMF = [
        [0.01431, 0.00040, 0.06785],  // 400
        [0.02319, 0.00060, 0.11020],  // 410
        [0.04351, 0.00120, 0.20740],  // 420
        [0.07763, 0.00220, 0.37130],  // 430
        [0.13438, 0.00400, 0.64560],  // 440
        [0.21477, 0.00730, 1.03905],  // 450
        [0.28390, 0.01160, 1.38560],  // 460
        [0.32850, 0.01690, 1.62300],  // 470
        [0.34828, 0.02300, 1.74706],  // 480
        [0.34806, 0.02980, 1.78260],  // 490
        [0.33620, 0.03800, 1.77211],  // 500
        [0.31870, 0.04800, 1.74410],  // 510
        [0.29080, 0.06000, 1.66920],  // 520
        [0.23370, 0.07400, 1.52810],  // 530
        [0.15630, 0.09100, 1.28764],  // 540
        [0.09119, 0.11260, 1.04190],  // 550
        [0.05795, 0.13902, 0.81295],  // 560
        [0.03290, 0.16930, 0.61620],  // 570
        [0.01472, 0.20800, 0.46518],  // 580
        [0.00490, 0.25860, 0.35330],  // 590
        [0.02400, 0.32300, 0.27200],  // 600
        [0.07100, 0.40730, 0.21230],  // 610
        [0.13820, 0.50300, 0.15820],  // 620
        [0.22830, 0.60820, 0.11170],  // 630
        [0.34290, 0.71000, 0.07825],  // 640
        [0.46120, 0.79320, 0.05725],  // 650
        [0.54950, 0.86200, 0.04216],  // 660
        [0.63460, 0.91485, 0.02984],  // 670
        [0.70980, 0.95400, 0.02030],  // 680
        [0.76440, 0.98030, 0.01340],  // 690
        [0.81310, 0.99500, 0.00880],  // 700
    ];

    /**
     * D65 illuminant spectral power distribution (400-700nm, 10nm steps).
     */
    private const D65 = [
        82.75,  91.49,  93.43,  86.68,  104.86,
        117.01, 117.81, 114.86, 115.92, 108.81,
        109.35, 107.80, 104.79, 107.69, 104.41,
        104.05, 100.00, 96.33,  95.79,  88.69,
        90.01,  89.60,  87.70,  83.29,  83.70,
        80.03,  80.21,  82.28,  78.28,  69.72,
        71.61,
    ];

    /** D65 reference white XYZ (sum of CMF * D65) */
    private static ?array $whiteXYZ = null;

    private static function getWhiteXYZ(): array
    {
        if (self::$whiteXYZ !== null) return self::$whiteXYZ;

        $xw = 0; $yw = 0; $zw = 0;
        for ($i = 0; $i < self::WAVELENGTHS; $i++) {
            $s = self::D65[$i];
            $xw += self::CMF[$i][0] * $s;
            $yw += self::CMF[$i][1] * $s;
            $zw += self::CMF[$i][2] * $s;
        }
        self::$whiteXYZ = [$xw, $yw, $zw];
        return self::$whiteXYZ;
    }

    /**
     * Convert Lab to XYZ (D65).
     */
    public static function labToXYZ(float $L, float $a, float $b): array
    {
        $fy = ($L + 16) / 116;
        $fx = $a / 500 + $fy;
        $fz = $fy - $b / 200;

        $delta = 6.0 / 29.0;
        $xr = ($fx > $delta) ? $fx ** 3 : 3 * $delta * $delta * ($fx - 4.0 / 29.0);
        $yr = ($fy > $delta) ? $fy ** 3 : 3 * $delta * $delta * ($fy - 4.0 / 29.0);
        $zr = ($fz > $delta) ? $fz ** 3 : 3 * $delta * $delta * ($fz - 4.0 / 29.0);

        // D65 reference white
        return [$xr * 0.95047, $yr * 1.00000, $zr * 1.08883];
    }

    /**
     * Reconstruct an approximate spectral reflectance curve from Lab values.
     *
     * Uses a least-norm solution: R(λ) = Σ ci * basis_i(λ)
     * where the basis functions are derived from the CMF weighted by D65,
     * and coefficients are chosen so the resulting spectrum integrates
     * to the correct XYZ values.
     *
     * @return float[] 31 reflectance values (400-700nm, 10nm steps)
     */
    public static function labToSpectrum(float $L, float $a, float $b): array
    {
        [$X, $Y, $Z] = self::labToXYZ($L, $a, $b);
        [$Xw, $Yw, $Zw] = self::getWhiteXYZ();

        // Normalize XYZ relative to illuminant
        $targetX = $X * $Yw;
        $targetY = $Y * $Yw;
        $targetZ = $Z * $Yw;

        // Build basis matrix: 3 basis spectra from CMF * D65
        // B[i][j] = CMF_j[i] * D65[i]  for j=0,1,2 (x,y,z)
        $B = [];
        for ($i = 0; $i < self::WAVELENGTHS; $i++) {
            $B[$i] = [
                self::CMF[$i][0] * self::D65[$i],
                self::CMF[$i][1] * self::D65[$i],
                self::CMF[$i][2] * self::D65[$i],
            ];
        }

        // Gram matrix G = B^T * B (3x3)
        $G = [[0,0,0],[0,0,0],[0,0,0]];
        for ($i = 0; $i < self::WAVELENGTHS; $i++) {
            for ($j = 0; $j < 3; $j++) {
                for ($k = 0; $k < 3; $k++) {
                    $G[$j][$k] += $B[$i][$j] * $B[$i][$k];
                }
            }
        }

        // Right-hand side: B^T * target  (but target is XYZ, so it's simpler)
        // The target XYZ = integral(R(λ) * CMF * D65) = B^T * R
        // We want R = B * c where G * c = [X, Y, Z]
        $rhs = [$targetX, $targetY, $targetZ];

        // Solve 3x3 system G * c = rhs using Cramer's rule
        $c = self::solve3x3($G, $rhs);

        // Reconstruct spectrum: R(λ) = c0*B0(λ) + c1*B1(λ) + c2*B2(λ)
        $spectrum = [];
        for ($i = 0; $i < self::WAVELENGTHS; $i++) {
            $r = $c[0] * $B[$i][0] + $c[1] * $B[$i][1] + $c[2] * $B[$i][2];
            // Clamp to physically plausible range
            $spectrum[$i] = max(0.0, min(1.0, $r));
        }

        return $spectrum;
    }

    /**
     * Count the number of times two spectral curves cross each other.
     *
     * @param float[] $spectrum1 31-point reflectance curve
     * @param float[] $spectrum2 31-point reflectance curve
     * @return int Number of crossings
     */
    public static function countCrossings(array $spectrum1, array $spectrum2): int
    {
        $crossings = 0;
        $prevDiff = $spectrum1[0] - $spectrum2[0];

        for ($i = 1; $i < self::WAVELENGTHS; $i++) {
            $diff = $spectrum1[$i] - $spectrum2[$i];
            // A crossing occurs when the sign of the difference changes
            if (($prevDiff > 0 && $diff < 0) || ($prevDiff < 0 && $diff > 0)) {
                $crossings++;
            }
            // Only update prevDiff if diff is non-zero (skip touching points)
            if ($diff != 0) {
                $prevDiff = $diff;
            }
        }

        return $crossings;
    }

    /**
     * Compute a blended spectral curve from weighted anchor spectra.
     *
     * @param array $anchorSpectra Array of ['spectrum' => float[31], 'weight' => float]
     * @return float[] 31-point blended spectrum
     */
    public static function blendSpectra(array $anchorSpectra): array
    {
        $result = array_fill(0, self::WAVELENGTHS, 0.0);
        $totalWeight = 0;

        foreach ($anchorSpectra as $entry) {
            $totalWeight += $entry['weight'];
        }

        if ($totalWeight <= 0) return $result;

        foreach ($anchorSpectra as $entry) {
            $w = $entry['weight'] / $totalWeight;
            for ($i = 0; $i < self::WAVELENGTHS; $i++) {
                $result[$i] += $entry['spectrum'][$i] * $w;
            }
        }

        // Clamp
        for ($i = 0; $i < self::WAVELENGTHS; $i++) {
            $result[$i] = max(0.0, min(1.0, $result[$i]));
        }

        return $result;
    }

    /**
     * Evaluate metamerism risk for a prediction.
     *
     * @param array $targetLab ['L','a','b'] of the target color
     * @param array $nearestAnchors Array of anchors with 'lab' and 'normWeight'
     * @return array ['crossings' => int, 'risk' => 'low'|'medium'|'high']
     */
    public static function evaluateMetamerism(array $targetLab, array $nearestAnchors): array
    {
        // Build target spectral curve
        $targetSpectrum = self::labToSpectrum(
            (float) $targetLab['L'],
            (float) $targetLab['a'],
            (float) $targetLab['b']
        );

        // Build blended spectral curve from anchor weights
        $anchorSpectra = [];
        foreach ($nearestAnchors as $anchor) {
            $anchorSpectra[] = [
                'spectrum' => self::labToSpectrum(
                    (float) $anchor['lab']['L'],
                    (float) $anchor['lab']['a'],
                    (float) $anchor['lab']['b']
                ),
                'weight' => $anchor['normWeight'] ?? $anchor['weight'] ?? 1.0,
            ];
        }

        $blendedSpectrum = self::blendSpectra($anchorSpectra);

        // Count crossings
        $crossings = self::countCrossings($targetSpectrum, $blendedSpectrum);

        // Classify risk
        if ($crossings >= 3) {
            $risk = 'high';
        } elseif ($crossings === 2) {
            $risk = 'medium';
        } else {
            $risk = 'low';
        }

        return [
            'crossings' => $crossings,
            'risk'      => $risk,
        ];
    }

    /**
     * Solve a 3x3 linear system using Cramer's rule.
     */
    private static function solve3x3(array $A, array $b): array
    {
        $det = self::det3($A);
        if (abs($det) < 1e-12) {
            return [0, 0, 0]; // Singular — shouldn't happen with CMF data
        }

        $result = [];
        for ($col = 0; $col < 3; $col++) {
            $M = $A;
            for ($row = 0; $row < 3; $row++) {
                $M[$row][$col] = $b[$row];
            }
            $result[$col] = self::det3($M) / $det;
        }

        return $result;
    }

    private static function det3(array $M): float
    {
        return $M[0][0] * ($M[1][1] * $M[2][2] - $M[1][2] * $M[2][1])
             - $M[0][1] * ($M[1][0] * $M[2][2] - $M[1][2] * $M[2][0])
             + $M[0][2] * ($M[1][0] * $M[2][1] - $M[1][1] * $M[2][0]);
    }
}
