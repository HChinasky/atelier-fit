<?php

namespace App\Services\Color;

/**
 * CIELAB -> an sRGB hex string, for painting a swatch next to the numbers.
 *
 * **For display only.** The measurement is the L*, a*, b* printed beside the
 * patch; this is a convenience so a human can see roughly what those numbers
 * mean without reading them as coordinates. Out-of-gamut colours are clipped,
 * which is a lie a swatch is allowed to tell and a measurement is not.
 *
 * The engine does its colour maths through colour-science and never hand-rolls
 * it; this is the one place PHP needs the conversion and there is no equivalent
 * library on this side. Kept small, D65 / CIE 1931 2 degree observer to match
 * the engine, and deliberately not used for anything that is compared, stored
 * or decided on.
 */
class LabSwatch
{
    /** D65 white point, CIE 1931 2 degree observer — the same one the engine uses. */
    private const WHITE = ['x' => 0.95047, 'y' => 1.0, 'z' => 1.08883];

    /**
     * @return string|null a #rrggbb string, or null if the sample is unusable
     */
    public static function hex(mixed $lightness, mixed $a, mixed $b): ?string
    {
        if (! is_numeric($lightness) || ! is_numeric($a) || ! is_numeric($b)) {
            return null;
        }

        [$x, $y, $z] = self::toXyz((float) $lightness, (float) $a, (float) $b);

        $red = 3.2406 * $x - 1.5372 * $y - 0.4986 * $z;
        $green = -0.9689 * $x + 1.8758 * $y + 0.0415 * $z;
        $blue = 0.0557 * $x - 0.2040 * $y + 1.0570 * $z;

        return sprintf(
            '#%02x%02x%02x',
            self::channel($red),
            self::channel($green),
            self::channel($blue),
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function toXyz(float $lightness, float $a, float $b): array
    {
        $fy = ($lightness + 16.0) / 116.0;
        $fx = $fy + $a / 500.0;
        $fz = $fy - $b / 200.0;

        return [
            self::inverseF($fx) * self::WHITE['x'],
            self::inverseF($fy) * self::WHITE['y'],
            self::inverseF($fz) * self::WHITE['z'],
        ];
    }

    private static function inverseF(float $t): float
    {
        // 6/29 and the linear segment below it: the CIE definition, not an
        // approximation, so a dark swatch is not quietly crushed.
        return $t > 6 / 29 ? $t ** 3 : 3 * (6 / 29) ** 2 * ($t - 4 / 29);
    }

    /** Linear light -> the sRGB transfer function -> 0..255, clipped. */
    private static function channel(float $value): int
    {
        $encoded = $value <= 0.0031308
            ? 12.92 * $value
            : 1.055 * $value ** (1 / 2.4) - 0.055;

        return (int) round(max(0.0, min(1.0, $encoded)) * 255);
    }
}
