<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Color;

use Hryvinskyi\EmailTemplateEditor\Model\Color\Rgba;

/**
 * Interpolate two colours the way CSS `color-mix()` specifies.
 */
interface ColorMixerInterface
{
    /**
     * Mix two colours in the given interpolation space
     *
     * Interpolation uses premultiplied alpha, as CSS Color 5 requires; in polar spaces the
     * hue is excluded from premultiplication and interpolated as an angle instead.
     *
     * @param Rgba $first
     * @param Rgba $second
     * @param float $firstWeight Weight of $first, 0..1; $second gets the remainder
     * @param string $space Interpolation space keyword, e.g. "oklab", "oklch", "srgb"
     * @param string $hueMethod Hue interpolation method for polar spaces: "shorter"
     *                          (default), "longer", "increasing" or "decreasing"
     * @return Rgba|null Null when the interpolation space is not supported
     */
    public function mix(
        Rgba $first,
        Rgba $second,
        float $firstWeight,
        string $space,
        string $hueMethod = 'shorter'
    ): ?Rgba;
}
