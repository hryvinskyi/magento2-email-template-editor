<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Color;

use Hryvinskyi\EmailTemplateEditor\Api\Color\ColorMixerInterface;

class ColorMixer implements ColorMixerInterface
{
    /**
     * Rectangular interpolation spaces, mapped to their coordinate accessor
     */
    private const RECTANGULAR_SPACES = ['srgb', 'srgb-linear', 'oklab'];

    /**
     * {@inheritDoc}
     */
    public function mix(
        Rgba $first,
        Rgba $second,
        float $firstWeight,
        string $space,
        string $hueMethod = 'shorter'
    ): ?Rgba {
        $space = strtolower(trim($space));
        $firstWeight = max(0.0, min(1.0, $firstWeight));
        $secondWeight = 1.0 - $firstWeight;

        // Alpha interpolates plainly; the colour coordinates are premultiplied by it so that
        // a transparent operand contributes no colour, which is what CSS Color 5 requires.
        $alpha = $first->getAlpha() * $firstWeight + $second->getAlpha() * $secondWeight;

        if ($space === 'oklch') {
            return $this->mixPolar($first, $second, $firstWeight, $secondWeight, $alpha, $hueMethod);
        }

        if (!in_array($space, self::RECTANGULAR_SPACES, true)) {
            return null;
        }

        $firstCoordinates = $this->toCoordinates($first, $space);
        $secondCoordinates = $this->toCoordinates($second, $space);

        $mixed = [];
        foreach ([0, 1, 2] as $index) {
            $premultiplied = $firstCoordinates[$index] * $first->getAlpha() * $firstWeight
                + $secondCoordinates[$index] * $second->getAlpha() * $secondWeight;
            $mixed[$index] = $alpha > 0.0 ? $premultiplied / $alpha : 0.0;
        }

        return $this->fromCoordinates($mixed, $space, $alpha);
    }

    /**
     * Mix in OKLCH, where the hue is an angle and must not be premultiplied
     *
     * @param Rgba $first
     * @param Rgba $second
     * @param float $firstWeight
     * @param float $secondWeight
     * @param float $alpha The already-interpolated alpha
     * @param string $hueMethod
     * @return Rgba
     */
    private function mixPolar(
        Rgba $first,
        Rgba $second,
        float $firstWeight,
        float $secondWeight,
        float $alpha,
        string $hueMethod
    ): Rgba {
        [$firstLightness, $firstChroma, $firstHue] = $first->toOkLch();
        [$secondLightness, $secondChroma, $secondHue] = $second->toOkLch();

        $secondHue = $this->alignHue($firstHue, $secondHue, $hueMethod);

        $premultipliedLightness = $firstLightness * $first->getAlpha() * $firstWeight
            + $secondLightness * $second->getAlpha() * $secondWeight;
        $premultipliedChroma = $firstChroma * $first->getAlpha() * $firstWeight
            + $secondChroma * $second->getAlpha() * $secondWeight;

        $hue = $firstHue * $firstWeight + $secondHue * $secondWeight;

        if ($alpha <= 0.0) {
            return Rgba::fromOkLch(0.0, 0.0, $hue, 0.0);
        }

        return Rgba::fromOkLch($premultipliedLightness / $alpha, $premultipliedChroma / $alpha, $hue, $alpha);
    }

    /**
     * Shift the second hue so that linear interpolation takes the requested arc
     *
     * @param float $firstHue Degrees
     * @param float $secondHue Degrees
     * @param string $hueMethod
     * @return float The adjusted second hue, in degrees
     */
    private function alignHue(float $firstHue, float $secondHue, string $hueMethod): float
    {
        $difference = $secondHue - $firstHue;

        return match (strtolower(trim($hueMethod))) {
            'longer' => abs($difference) < 180 ? $secondHue + ($difference >= 0 ? -360 : 360) : $secondHue,
            'increasing' => $difference < 0 ? $secondHue + 360 : $secondHue,
            'decreasing' => $difference > 0 ? $secondHue - 360 : $secondHue,
            default => abs($difference) > 180 ? $secondHue + ($difference > 0 ? -360 : 360) : $secondHue,
        };
    }

    /**
     * Project a colour into a rectangular interpolation space
     *
     * @param Rgba $color
     * @param string $space
     * @return array{0: float, 1: float, 2: float}
     */
    private function toCoordinates(Rgba $color, string $space): array
    {
        return match ($space) {
            'srgb-linear' => $color->toLinear(),
            'oklab' => $color->toOkLab(),
            default => [$color->getRed(), $color->getGreen(), $color->getBlue()],
        };
    }

    /**
     * Rebuild a colour from rectangular interpolation-space coordinates
     *
     * @param array{0: float, 1: float, 2: float} $coordinates
     * @param string $space
     * @param float $alpha
     * @return Rgba
     */
    private function fromCoordinates(array $coordinates, string $space, float $alpha): Rgba
    {
        return match ($space) {
            'srgb-linear' => Rgba::fromLinear($coordinates[0], $coordinates[1], $coordinates[2], $alpha),
            'oklab' => Rgba::fromOkLab($coordinates[0], $coordinates[1], $coordinates[2], $alpha),
            default => new Rgba(
                max(0.0, min(1.0, $coordinates[0])),
                max(0.0, min(1.0, $coordinates[1])),
                max(0.0, min(1.0, $coordinates[2])),
                $alpha
            ),
        };
    }
}
