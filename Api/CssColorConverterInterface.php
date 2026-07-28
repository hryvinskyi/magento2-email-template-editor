<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Rewrite modern CSS colour notations into forms email clients actually render.
 *
 * Two families need translating:
 *
 * - Space-separated `rgb(R G B / A)` / `hsl(H S L / A)`. Emogrifier's CSS parser and a long
 *   tail of clients only understand the comma-separated `rgba()` / `hsla()` form.
 * - `oklch()` / `oklab()`. Tailwind v4's entire default palette is authored in OKLCH, so
 *   `border-gray-700`, `text-red-500` and friends emit `oklch(37.3% .034 259.733)`. Outlook,
 *   Yahoo and every pre-2023 client drop the declaration outright, which for a colour
 *   property means falling back to `currentColor` or the inherited value.
 */
interface CssColorConverterInterface
{
    /**
     * Convert every modern colour function in the CSS to a legacy equivalent
     *
     * `oklch()` / `oklab()` are converted through OKLab to sRGB and emitted as `#rrggbb`,
     * or as `rgba()` when the colour carries an alpha below 1. Colours whose arguments this
     * converter cannot evaluate statically - `calc()`, an unresolved `var()`, or the
     * relative-colour `from` syntax - are left untouched rather than mangled.
     *
     * @param string $css CSS that may contain modern colour notations
     * @return string The same CSS with legacy-compatible colour values
     */
    public function toLegacy(string $css): string;
}
