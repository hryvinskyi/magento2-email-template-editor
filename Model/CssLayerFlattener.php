<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\CssLayerFlattenerInterface;

class CssLayerFlattener implements CssLayerFlattenerInterface
{
    /**
     * {@inheritDoc}
     */
    public function flatten(string $css): string
    {
        // Drop @property rules (no nested braces inside)
        $css = (string)preg_replace(
            '/@property\s+--[\w-]+\s*\{[^{}]*\}/i',
            '',
            $css
        );

        // Drop statement-form @layer (declares layer order, no block):
        // e.g. "@layer theme, base, utilities;" or "@layer properties;"
        $css = (string)preg_replace(
            '/@layer\s+[^{;]+;/i',
            '',
            $css
        );

        // Process @layer { … } blocks. Tailwind v4's `@layer base` contains @supports
        // chains nested 3-4 levels deep, so we need a properly recursive matcher.
        // PCRE's (?2) re-runs the second capture group (the balanced-brace block) so
        // arbitrary nesting is handled.
        //
        // The loop catches blocks that become top-level only after a previous pass
        // unwrapped their parent (defensive - v4 doesn't currently nest @layer inside
        // @layer, but the cost is just one extra no-op pass).
        do {
            $count = 0;
            $css = (string)preg_replace_callback(
                '/@layer\s+([^{]+?)\s*(\{(?:[^{}]++|(?2))*+\})/u',
                static function (array $matches): string {
                    $names = trim($matches[1]);
                    $body = substr($matches[2], 1, -1);

                    if (preg_match('/\b(?:base|properties)\b/i', $names)) {
                        return '';
                    }

                    return $body;
                },
                $css,
                -1,
                $count
            );
        } while ($count > 0);

        return $css;
    }
}
