<?php
declare(strict_types=1);


namespace Flames\Stealth;

/**
 * Generates human-like mouse paths (Bezier curve, easing, jitter).
 *
 * @internal
 */
final class HumanMouse
{
    /**
     * @return list<array{0: float, 1: float}>
     */
    public static function path(float $fromX, float $fromY, float $toX, float $toY): array
    {
        $distance = hypot($toX - $fromX, $toY - $fromY);

        if ($distance < 1.0) {
            return [[$toX, $toY]];
        }

        $steps = (int) max(10, min(140, round($distance / random_int(5, 11))));

        $dx = $toX - $fromX;
        $dy = $toY - $fromY;
        $spread = max(18.0, $distance * random_int(25, 40) / 100);

        $cp1x = $fromX + $dx * random_int(20, 35) / 100 + self::spread($spread);
        $cp1y = $fromY + $dy * random_int(20, 35) / 100 + self::spread($spread);
        $cp2x = $fromX + $dx * random_int(65, 80) / 100 + self::spread($spread);
        $cp2y = $fromY + $dy * random_int(65, 80) / 100 + self::spread($spread);

        $points = [];

        for ($i = 0; $i <= $steps; $i++) {
            $t = self::easeInOutCubic($i / $steps);
            [$x, $y] = self::cubicBezier($t, $fromX, $fromY, $cp1x, $cp1y, $cp2x, $cp2y, $toX, $toY);

            if ($i < $steps) {
                $x += self::microJitter();
                $y += self::microJitter();
            }

            $points[] = [$x, $y];
        }

        return $points;
    }

    /**
     * @param list<int|float> $content Box model content quad [x1,y1,…,x4,y4].
     * @return array{0: float, 1: float}
     */
    public static function randomPointInBox(array $content): array
    {
        $xs = [$content[0], $content[2], $content[4], $content[6]];
        $ys = [$content[1], $content[3], $content[5], $content[7]];

        $minX = (float) min($xs);
        $maxX = (float) max($xs);
        $minY = (float) min($ys);
        $maxY = (float) max($ys);

        $width = $maxX - $minX;
        $height = $maxY - $minY;
        $inset = random_int(12, 22) / 100;

        $x = $minX + $width * ($inset + (1 - 2 * $inset) * (random_int(0, 10_000) / 10_000));
        $y = $minY + $height * ($inset + (1 - 2 * $inset) * (random_int(0, 10_000) / 10_000));

        return [$x, $y];
    }

    private static function cubicBezier(
        float $t,
        float $x0,
        float $y0,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $x3,
        float $y3,
    ): array {
        $u = 1.0 - $t;
        $u2 = $u * $u;
        $u3 = $u2 * $u;
        $t2 = $t * $t;
        $t3 = $t2 * $t;

        $x = $u3 * $x0
            + 3 * $u2 * $t * $x1
            + 3 * $u * $t2 * $x2
            + $t3 * $x3;

        $y = $u3 * $y0
            + 3 * $u2 * $t * $y1
            + 3 * $u * $t2 * $y2
            + $t3 * $y3;

        return [$x, $y];
    }

    private static function easeInOutCubic(float $t): float
    {
        return $t < 0.5
            ? 4 * $t * $t * $t
            : 1 - ((-2 * $t + 2) ** 3) / 2;
    }

    private static function spread(float $magnitude): float
    {
        return (random_int(0, 1) === 0 ? -1 : 1) * (random_int(0, 10_000) / 10_000) * $magnitude;
    }

    private static function microJitter(): float
    {
        return (random_int(-10, 10) / 10.0);
    }
}
