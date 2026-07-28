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
 * Parse a single CSS colour token into an sRGB value.
 *
 * Needed because `color-mix()` has to be evaluated server-side: its operands can be written
 * in any colour notation, and the mix cannot be computed without resolving them first.
 */
interface ColorParserInterface
{
    /**
     * Resolve a CSS colour token
     *
     * Understands `transparent`, the CSS named colours, `#rgb` / `#rgba` / `#rrggbb` /
     * `#rrggbbaa`, `rgb()` / `rgba()`, `hsl()` / `hsla()` (both comma- and space-separated),
     * and `oklch()` / `oklab()`.
     *
     * @param string $color A single colour token, already trimmed of surrounding whitespace
     * @return Rgba|null Null when the token is not a statically resolvable colour - notably
     *                   `currentColor`, an unresolved `var()`, or a `calc()` channel
     */
    public function parse(string $color): ?Rgba;
}
