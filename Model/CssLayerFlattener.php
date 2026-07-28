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
        // Drop @property rules (no nested braces inside), but keep their `initial-value`
        // first. Tailwind v4 registers composition slots such as `--tw-border-style` with
        // `@property --tw-border-style { syntax: "*"; inherits: false; initial-value: solid }`
        // and only ever emits `border-style: var(--tw-border-style)` in the utility itself.
        // Dropping both that rule and the `@layer properties` fallback left the var()
        // unresolvable, so `.border-2` inlined as `border-style: var(--tw-border-style);
        // border-width: 2px` - an invalid declaration that computes to `border-style: none`,
        // i.e. an invisible border. Harvesting the registered defaults keeps them resolvable.
        $propertyDefaults = [];
        $css = (string)preg_replace_callback(
            '/@property\s+(--[\w-]+)\s*\{([^{}]*)\}/i',
            static function (array $matches) use (&$propertyDefaults): string {
                if (preg_match('/(?:^|;)\s*initial-value\s*:\s*([^;{}]+)/i', $matches[2], $valueMatch) === 1) {
                    $value = trim($valueMatch[1]);
                    if ($value !== '') {
                        $propertyDefaults[trim($matches[1])] = $value;
                    }
                }

                return '';
            },
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

        return $this->prependPropertyDefaults($css, $propertyDefaults);
    }

    /**
     * Emit the harvested `@property` defaults as a leading `:root { … }` block
     *
     * The block goes *first* so that it loses to any later declaration in `CssVariableResolver`'s
     * flat, last-wins variable map. The block itself never reaches the inliner - the resolver
     * strips custom-property declarations and then removes the rule block left empty behind them.
     *
     * KNOWN LIMITATION: that map is document-global, so it cannot express "this slot has this
     * value *inside this rule*". A single `.border-dashed { --tw-border-style: dashed }`
     * anywhere in the stylesheet therefore turns every `border-*` utility dashed, whether or
     * not the element carries the class. Hoisting the default does not introduce that bug -
     * it is how the resolver has always worked, and it affects every Tailwind v4 composition
     * slot equally (`--tw-shadow`, `--tw-rotate`, `--tw-gradient-from`, …). Fixing it properly
     * means giving the resolver per-rule scope rather than one global map.
     *
     * @param string $css
     * @param array<string, string> $propertyDefaults Map of custom property name to initial value
     * @return string
     */
    private function prependPropertyDefaults(string $css, array $propertyDefaults): string
    {
        if ($propertyDefaults === []) {
            return $css;
        }

        $declarations = '';
        foreach ($propertyDefaults as $name => $value) {
            $declarations .= $name . ': ' . $value . ';';
        }

        return ':root{' . $declarations . '}' . "\n" . $css;
    }
}
