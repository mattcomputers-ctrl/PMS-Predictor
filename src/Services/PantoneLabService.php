<?php

declare(strict_types=1);

namespace PantonePredictor\Services;

use PantonePredictor\Core\App;

class PantoneLabService
{
    private static ?array $data = null;

    /**
     * Load and cache the Pantone Lab dataset.
     */
    private static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = App::config('paths.data', App::basePath() . '/data') . '/pantone_lab.json';
        if (!file_exists($path)) {
            throw new \RuntimeException('Pantone Lab dataset not found: ' . $path);
        }

        $json = json_decode(file_get_contents($path), true);
        if (!$json || !isset($json['colors'])) {
            throw new \RuntimeException('Invalid Pantone Lab dataset format.');
        }

        // Force all keys to strings (PHP json_decode converts numeric keys to int)
        $colors = [];
        foreach ($json['colors'] as $key => $value) {
            $colors[(string) $key] = $value;
        }
        self::$data = $colors;
        return self::$data;
    }

    /**
     * Get Lab values for a PMS color.
     * Tries multiple matching strategies.
     *
     * @return array|null ['L' => float, 'a' => float, 'b' => float, 'name' => string, 'hex' => string]
     */
    public static function getLabForColor(string|int $colorIdentifier): ?array
    {
        $colors = self::load();
        $id = trim((string) $colorIdentifier);

        // Strategy 1: Direct key match
        if (isset($colors[$id])) {
            return $colors[$id];
        }

        // Strategy 2: Strip C/U suffix
        $stripped = preg_replace('/\s*[CU]$/i', '', $id);
        if ($stripped !== $id && isset($colors[$stripped])) {
            return $colors[$stripped];
        }

        // Strategy 3: Extract leading number
        if (preg_match('/^(\d+)/', $id, $m)) {
            $num = $m[1];
            if (isset($colors[$num])) {
                return $colors[$num];
            }
        }

        // Strategy 4: Named color lookup (case-insensitive search)
        $upperId = strtoupper($id);
        foreach ($colors as $key => $entry) {
            if (strtoupper((string) $key) === $upperId) {
                return $entry;
            }
            // Match against the name field
            if (isset($entry['name'])) {
                $nameUpper = strtoupper($entry['name']);
                if (str_contains($nameUpper, $upperId) || $nameUpper === 'PANTONE ' . $upperId . ' C') {
                    return $entry;
                }
            }
        }

        // Strategy 5: Try common name mappings
        $nameMap = [
            'WARM RED'      => 'Warm Red',
            'RUBINE RED'    => 'Rubine Red',
            'RHODAMINE RED' => 'Rhodamine Red',
            'PURPLE'        => 'Purple',
            'VIOLET'        => 'Violet',
            'BLUE 072'      => 'Blue 072',
            'REFLEX BLUE'   => 'Reflex Blue',
            'PROCESS BLUE'  => 'Process Blue',
            'GREEN'         => 'Green',
            'YELLOW'        => 'Yellow',
            'YELLOW 012'    => 'Yellow 012',
            'ORANGE 021'    => 'Orange 021',
            'RED 032'       => 'Red 032',
            'BLACK'         => 'Black',
            'WHITE'         => 'White',
        ];

        $mappedKey = $nameMap[$upperId] ?? null;
        if ($mappedKey && isset($colors[$mappedKey])) {
            return $colors[$mappedKey];
        }

        return null;
    }

    /**
     * Get all available PMS colors with Lab values.
     * Returns keyed by PMS identifier.
     */
    public static function getAllColors(): array
    {
        return self::load();
    }

    /**
     * Get the total count of colors in the dataset.
     */
    public static function getColorCount(): int
    {
        return count(self::load());
    }

    /**
     * Convert Lab values to approximate sRGB hex for display.
     */
    public static function labToHex(float $L, float $a, float $b): string
    {
        // Lab -> XYZ (D65)
        $fy = ($L + 16) / 116;
        $fx = $a / 500 + $fy;
        $fz = $fy - $b / 200;

        $delta = 6 / 29;
        $xr = ($fx > $delta) ? $fx ** 3 : 3 * $delta * $delta * ($fx - 4 / 29);
        $yr = ($fy > $delta) ? $fy ** 3 : 3 * $delta * $delta * ($fy - 4 / 29);
        $zr = ($fz > $delta) ? $fz ** 3 : 3 * $delta * $delta * ($fz - 4 / 29);

        // D65 reference white
        $x = $xr * 0.95047;
        $y = $yr * 1.00000;
        $z = $zr * 1.08883;

        // XYZ -> sRGB
        $r = $x *  3.2406 + $y * -1.5372 + $z * -0.4986;
        $g = $x * -0.9689 + $y *  1.8758 + $z *  0.0415;
        $b_rgb = $x *  0.0557 + $y * -0.2040 + $z *  1.0570;

        // Gamma correction
        $gamma = function (float $v): float {
            return $v <= 0.0031308
                ? 12.92 * $v
                : 1.055 * pow($v, 1 / 2.4) - 0.055;
        };

        $ri = max(0, min(255, (int) round($gamma($r) * 255)));
        $gi = max(0, min(255, (int) round($gamma($g) * 255)));
        $bi = max(0, min(255, (int) round($gamma($b_rgb) * 255)));

        return sprintf('#%02X%02X%02X', $ri, $gi, $bi);
    }
}
