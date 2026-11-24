<?php

namespace App\Services\Color;

/**
 * CIEDE2000 colour difference between two CIELAB colours.
 *
 * The formula of Sharma, Wu and Dalal (2005), "The CIEDE2000 color-difference
 * formula: implementation notes, supplementary test data, and mathematical
 * observations" — the reference the engine's colour-science library follows,
 * and whose published test pairs pin this implementation (DeltaETest). kL, kC
 * and kH are 1, the textile-neutral defaults.
 *
 * Roughly: under 1 no one sees it, 2–3 a trained eye sees it side by side,
 * around 10 it is plainly another shade of the same colour family.
 */
final class DeltaE
{
    /**
     * @param  array{0: float, 1: float, 2: float}  $first  L*, a*, b*
     * @param  array{0: float, 1: float, 2: float}  $second  L*, a*, b*
     */
    public static function ciede2000(array $first, array $second): float
    {
        [$l1, $a1, $b1] = array_map('floatval', array_values($first));
        [$l2, $a2, $b2] = array_map('floatval', array_values($second));

        $c1 = hypot($a1, $b1);
        $c2 = hypot($a2, $b2);
        $cMean7 = (($c1 + $c2) / 2) ** 7;
        $g = 0.5 * (1 - sqrt($cMean7 / ($cMean7 + 25 ** 7)));

        $a1p = (1 + $g) * $a1;
        $a2p = (1 + $g) * $a2;
        $c1p = hypot($a1p, $b1);
        $c2p = hypot($a2p, $b2);
        $h1p = self::hue($a1p, $b1);
        $h2p = self::hue($a2p, $b2);

        $deltaL = $l2 - $l1;
        $deltaC = $c2p - $c1p;

        if ($c1p * $c2p == 0.0) {
            $deltaH = 0.0;
        } else {
            $deltaH = $h2p - $h1p;
            if ($deltaH > 180) {
                $deltaH -= 360;
            } elseif ($deltaH < -180) {
                $deltaH += 360;
            }
        }
        $deltaHp = 2 * sqrt($c1p * $c2p) * sin(deg2rad($deltaH / 2));

        $lMean = ($l1 + $l2) / 2;
        $cMeanP = ($c1p + $c2p) / 2;

        if ($c1p * $c2p == 0.0) {
            $hMean = $h1p + $h2p;
        } elseif (abs($h1p - $h2p) <= 180) {
            $hMean = ($h1p + $h2p) / 2;
        } elseif ($h1p + $h2p < 360) {
            $hMean = ($h1p + $h2p + 360) / 2;
        } else {
            $hMean = ($h1p + $h2p - 360) / 2;
        }

        $t = 1
            - 0.17 * cos(deg2rad($hMean - 30))
            + 0.24 * cos(deg2rad(2 * $hMean))
            + 0.32 * cos(deg2rad(3 * $hMean + 6))
            - 0.20 * cos(deg2rad(4 * $hMean - 63));

        $deltaTheta = 30 * exp(-((($hMean - 275) / 25) ** 2));
        $cMeanP7 = $cMeanP ** 7;
        $rc = 2 * sqrt($cMeanP7 / ($cMeanP7 + 25 ** 7));
        $sl = 1 + (0.015 * ($lMean - 50) ** 2) / sqrt(20 + ($lMean - 50) ** 2);
        $sc = 1 + 0.045 * $cMeanP;
        $sh = 1 + 0.015 * $cMeanP * $t;
        $rt = -sin(deg2rad(2 * $deltaTheta)) * $rc;

        return sqrt(
            ($deltaL / $sl) ** 2
            + ($deltaC / $sc) ** 2
            + ($deltaHp / $sh) ** 2
            + $rt * ($deltaC / $sc) * ($deltaHp / $sh)
        );
    }

    private static function hue(float $a, float $b): float
    {
        if ($a == 0.0 && $b == 0.0) {
            return 0.0;
        }

        $degrees = rad2deg(atan2($b, $a));

        return $degrees < 0 ? $degrees + 360 : $degrees;
    }
}
