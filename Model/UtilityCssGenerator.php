<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\UtilityCssGeneratorInterface;

class UtilityCssGenerator implements UtilityCssGeneratorInterface
{
    /**
     * Mapping of token section keys to their CSS custom property prefixes
     *
     * Names follow the Tailwind v4 @theme namespace (e.g. --text-*, --font-*, --container-*);
     * Tailwind v4 derives utility classes directly from these variables, so renaming is the
     * load-bearing part of the v4 migration.
     */
    private const TOKEN_PREFIX_MAP = [
        'colors' => 'color',
        'spacing' => 'spacing',
        'fontSize' => 'text',
        'fontFamily' => 'font',
        'borderRadius' => 'radius',
        'lineHeight' => 'leading',
        'fontWeight' => 'font-weight',
        'letterSpacing' => 'tracking',
        'maxWidth' => 'container',
        'boxShadow' => 'shadow',
        'opacity' => 'opacity',
        'zIndex' => 'z',
    ];

    /**
     * {@inheritDoc}
     *
     * The input is now a Tailwind v4 CSS-first theme string (typically a leading
     * `@theme { --color-*: …; --text-*: …; }` block, optionally followed by `@import url()`
     * for fonts and any plain CSS the user wants). The output passes the input through
     * verbatim, then appends derived utility class rules (`.bg-*`, `.text-*`, `.p-*`, etc.)
     * for the most common token namespaces. The duplication exists because Tailwind v4
     * derives utilities from CSS variables on the fly in a browser/Node compiler, but the
     * Emogrifier-based server inliner needs explicit selectors to match `class="..."` in
     * the markup.
     *
     * Legacy JSON input (pre-v4) is auto-detected and passed through the legacy path so
     * stored themes that have not been migrated yet still render.
     */
    public function generate(string $theme): string
    {
        $theme = trim($theme);
        if ($theme === '') {
            return '';
        }

        if ($this->looksLikeLegacyJson($theme)) {
            return $this->generateFromLegacyJson($theme);
        }

        $css = [$theme];
        $themeBody = $this->collectThemeBlockBodies($theme);

        $colors = $this->extractTokenMap($themeBody, 'color');
        if (!empty($colors)) {
            $css[] = $this->generateColorUtilities($colors);
        }

        $spacing = $this->extractTokenMap($themeBody, 'spacing');
        if (!empty($spacing)) {
            $css[] = $this->generateSpacingUtilities($spacing);
        }

        $fontSizes = $this->extractTokenMap($themeBody, 'text');
        if (!empty($fontSizes)) {
            $css[] = $this->generateFontSizeUtilities($fontSizes);
        }

        $fontWeights = $this->extractTokenMap($themeBody, 'font-weight');
        if (!empty($fontWeights)) {
            $css[] = $this->generateFontWeightUtilities($fontWeights);
        }

        // Font family extraction must exclude --font-weight-* to avoid capturing weights as
        // family names (e.g. "--font-weight-bold" would otherwise emit `.font-weight-bold`).
        $fontFamilies = $this->extractTokenMap($themeBody, 'font', ['weight']);
        if (!empty($fontFamilies)) {
            $css[] = $this->generateFontFamilyUtilities($fontFamilies);
        }

        $lineHeights = $this->extractTokenMap($themeBody, 'leading');
        if (!empty($lineHeights)) {
            $css[] = $this->generateLineHeightUtilities($lineHeights);
        }

        $letterSpacings = $this->extractTokenMap($themeBody, 'tracking');
        if (!empty($letterSpacings)) {
            $css[] = $this->generateLetterSpacingUtilities($letterSpacings);
        }

        $radii = $this->extractTokenMap($themeBody, 'radius');
        if (!empty($radii)) {
            $css[] = $this->generateBorderRadiusUtilities($radii);
        }

        $shadows = $this->extractTokenMap($themeBody, 'shadow');
        if (!empty($shadows)) {
            $css[] = $this->generateBoxShadowUtilities($shadows);
        }

        $opacities = $this->extractTokenMap($themeBody, 'opacity');
        if (!empty($opacities)) {
            $css[] = $this->generateOpacityUtilities($opacities);
        }

        $zIndexes = $this->extractTokenMap($themeBody, 'z');
        if (!empty($zIndexes)) {
            $css[] = $this->generateZIndexUtilities($zIndexes);
        }

        $maxWidths = $this->extractTokenMap($themeBody, 'container');
        if (!empty($maxWidths)) {
            $css[] = $this->generateMaxWidthUtilities($maxWidths);
        }

        return implode("\n\n", array_filter($css));
    }

    /**
     * Detect the legacy pre-v4 JSON theme shape
     *
     * @param string $value
     * @return bool
     */
    private function looksLikeLegacyJson(string $value): bool
    {
        if (!str_starts_with(ltrim($value), '{')) {
            return false;
        }

        $data = json_decode($value, true);

        return is_array($data) && (isset($data['tokens']) || isset($data['elements']) || isset($data['utilities']));
    }

    /**
     * Legacy JSON renderer kept for unmigrated stored themes
     *
     * @param string $themeJson
     * @return string
     */
    private function generateFromLegacyJson(string $themeJson): string
    {
        $data = json_decode($themeJson, true);
        if (!is_array($data)) {
            return '';
        }

        $css = [];

        if (!empty($data['tokens']['googleFonts']) && is_array($data['tokens']['googleFonts'])) {
            foreach ($data['tokens']['googleFonts'] as $font) {
                $css[] = sprintf(
                    "@import url('https://fonts.googleapis.com/css2?family=%s&display=swap');",
                    urlencode((string)$font)
                );
            }
        }

        $themeVariables = $this->generateThemeVariables($data);
        if ($themeVariables !== '') {
            $css[] = $themeVariables;
        }

        if (!empty($data['elements']) && is_array($data['elements'])) {
            $elementCss = $this->generateElementCss($data['elements']);
            if ($elementCss !== '') {
                $css[] = $elementCss;
            }
        }

        if (!empty($data['utilities']) && is_array($data['utilities'])) {
            $utilityCss = $this->generateUtilityCss($data['utilities']);
            if ($utilityCss !== '') {
                $css[] = $utilityCss;
            }
        }

        if (!empty($data['tokens']['colors']) && is_array($data['tokens']['colors'])) {
            $css[] = $this->generateColorUtilities($data['tokens']['colors']);
        }

        if (!empty($data['tokens']['spacing']) && is_array($data['tokens']['spacing'])) {
            $css[] = $this->generateSpacingUtilities($data['tokens']['spacing']);
        }

        if (!empty($data['tokens']['fontSize']) && is_array($data['tokens']['fontSize'])) {
            $css[] = $this->generateFontSizeUtilities($data['tokens']['fontSize']);
        }

        return implode("\n\n", array_filter($css));
    }

    /**
     * Concatenate the bodies of every `@theme { … }` block in the CSS
     *
     * @param string $css
     * @return string
     */
    private function collectThemeBlockBodies(string $css): string
    {
        if (!preg_match_all('/@theme\s*\{((?:[^{}]|\{[^{}]*\})*)\}/i', $css, $matches)) {
            return '';
        }

        return implode("\n", $matches[1]);
    }

    /**
     * Extract `--<prefix>-<name>: <value>;` declarations into a name => value map
     *
     * When extracting a shorter prefix that overlaps a longer one (e.g. `font` overlaps
     * `font-weight`), pass the conflicting sub-prefixes via $excludeNestedPrefixes to skip
     * declarations whose name starts with them. Example: `extractTokenMap($css, 'font',
     * ['weight'])` returns family tokens and ignores `--font-weight-*`.
     *
     * @param string $css
     * @param string $prefix
     * @param string[] $excludeNestedPrefixes
     * @return array<string, string>
     */
    private function extractTokenMap(string $css, string $prefix, array $excludeNestedPrefixes = []): array
    {
        $tokens = [];
        $pattern = '/--' . preg_quote($prefix, '/') . '-([A-Za-z0-9_-]+)\s*:\s*([^;]+);/';

        if (preg_match_all($pattern, $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];

                foreach ($excludeNestedPrefixes as $nested) {
                    if (str_starts_with($name, $nested . '-')) {
                        continue 2;
                    }
                }

                $tokens[$name] = trim($match[2]);
            }
        }

        return $tokens;
    }

    /**
     * Generate CSS custom properties in a @theme block from token definitions
     *
     * @param array<string, mixed> $data
     * @return string
     */
    private function generateThemeVariables(array $data): string
    {
        if (empty($data['tokens']) || !is_array($data['tokens'])) {
            return '';
        }

        $variables = [];

        foreach (self::TOKEN_PREFIX_MAP as $tokenKey => $cssPrefix) {
            if (empty($data['tokens'][$tokenKey]) || !is_array($data['tokens'][$tokenKey])) {
                continue;
            }

            foreach ($data['tokens'][$tokenKey] as $name => $value) {
                $safeName = $this->escapeClassName((string)$name);
                $safeValue = $this->escapeValue((string)$value);
                $variables[] = sprintf('    --%s-%s: %s;', $cssPrefix, $safeName, $safeValue);
            }
        }

        if (empty($variables)) {
            return '';
        }

        return "@theme {\n" . implode("\n", $variables) . "\n}";
    }

    /**
     * Generate CSS for element selectors
     *
     * @param array<string, array<string, string>> $elements
     * @return string
     */
    private function generateElementCss(array $elements): string
    {
        $css = [];

        foreach ($elements as $selector => $properties) {
            if (!is_array($properties) || empty($properties)) {
                continue;
            }

            $rules = [];
            foreach ($properties as $property => $value) {
                $rules[] = sprintf('    %s: %s;', $this->escapeProperty($property), $this->escapeValue($value));
            }

            $css[] = sprintf("%s {\n%s\n}", $this->escapeSelector($selector), implode("\n", $rules));
        }

        return implode("\n\n", $css);
    }

    /**
     * Generate CSS for utility classes
     *
     * @param array<string, array<string, string>> $utilities
     * @return string
     */
    private function generateUtilityCss(array $utilities): string
    {
        $css = [];

        foreach ($utilities as $className => $properties) {
            if (!is_array($properties) || empty($properties)) {
                continue;
            }

            $rules = [];
            foreach ($properties as $property => $value) {
                $rules[] = sprintf('    %s: %s;', $this->escapeProperty($property), $this->escapeValue($value));
            }

            $css[] = sprintf(".%s {\n%s\n}", $this->escapeClassName($className), implode("\n", $rules));
        }

        return implode("\n\n", $css);
    }

    /**
     * Generate text and background color utility classes from color tokens
     *
     * @param array<string, string> $colors
     * @return string
     */
    private function generateColorUtilities(array $colors): string
    {
        $css = [];

        // Tailwind utility prefixes derived from a color token. The `!` modifier
        // (`!bg-X`, `!text-X`, ...) is generated for every utility so override classes
        // win over baseline element rules like `.header { background-color: ... }`.
        $colorProps = [
            'text' => 'color',
            'bg' => 'background-color',
            'border' => 'border-color',
            'outline' => 'outline-color',
        ];

        foreach ($colors as $name => $value) {
            $safeName = $this->escapeClassName((string)$name);
            $safeValue = $this->escapeValue((string)$value);

            foreach ($colorProps as $prefix => $property) {
                $css[] = sprintf(".%s-%s {\n    %s: %s;\n}", $prefix, $safeName, $property, $safeValue);
                $css[] = sprintf(
                    ".\\!%s-%s {\n    %s: %s !important;\n}",
                    $prefix,
                    $safeName,
                    $property,
                    $safeValue
                );
            }
        }

        return implode("\n\n", $css);
    }

    /**
     * Generate margin, padding, width and height utility classes from spacing tokens
     *
     * @param array<string, string> $spacingMap
     * @return string
     */
    private function generateSpacingUtilities(array $spacingMap): string
    {
        $css = [];
        $prefixes = [
            'm' => 'margin',
            'mx' => ['margin-left', 'margin-right'],
            'my' => ['margin-top', 'margin-bottom'],
            'mt' => 'margin-top',
            'mr' => 'margin-right',
            'mb' => 'margin-bottom',
            'ml' => 'margin-left',
            'p' => 'padding',
            'px' => ['padding-left', 'padding-right'],
            'py' => ['padding-top', 'padding-bottom'],
            'pt' => 'padding-top',
            'pr' => 'padding-right',
            'pb' => 'padding-bottom',
            'pl' => 'padding-left',
            'w' => 'width',
            'h' => 'height',
        ];

        foreach ($spacingMap as $key => $value) {
            $safeKey = $this->escapeClassName((string)$key);
            $safeValue = $this->escapeValue((string)$value);

            foreach ($prefixes as $prefix => $prop) {
                $props = (array)$prop;

                $standard = [];
                $important = [];
                foreach ($props as $p) {
                    $standard[] = sprintf('    %s: %s;', $p, $safeValue);
                    $important[] = sprintf('    %s: %s !important;', $p, $safeValue);
                }

                $css[] = sprintf(".%s-%s {\n%s\n}", $prefix, $safeKey, implode("\n", $standard));
                $css[] = sprintf(".\\!%s-%s {\n%s\n}", $prefix, $safeKey, implode("\n", $important));
            }
        }

        return implode("\n\n", $css);
    }

    /**
     * Generate font-size utility classes from fontSize tokens
     *
     * @param array<string, string> $fontSizes
     * @return string
     */
    private function generateFontSizeUtilities(array $fontSizes): string
    {
        return $this->emitSingleProperty($fontSizes, 'text', 'font-size');
    }

    /**
     * Generate font-family utility classes from fontFamily tokens
     *
     * @param array<string, string> $fontFamilies
     * @return string
     */
    private function generateFontFamilyUtilities(array $fontFamilies): string
    {
        return $this->emitSingleProperty($fontFamilies, 'font', 'font-family');
    }

    /**
     * Generate font-weight utility classes from fontWeight tokens
     *
     * Shares the `.font-X` selector namespace with font-family tokens; declaration order
     * during inlining decides which property wins per class. By convention, font-weight
     * names (e.g. "bold", "medium") never collide with font-family names (e.g. "sans").
     *
     * @param array<string, string> $fontWeights
     * @return string
     */
    private function generateFontWeightUtilities(array $fontWeights): string
    {
        return $this->emitSingleProperty($fontWeights, 'font', 'font-weight');
    }

    /**
     * Generate line-height utility classes from lineHeight tokens
     *
     * @param array<string, string> $lineHeights
     * @return string
     */
    private function generateLineHeightUtilities(array $lineHeights): string
    {
        return $this->emitSingleProperty($lineHeights, 'leading', 'line-height');
    }

    /**
     * Generate letter-spacing utility classes from letterSpacing tokens
     *
     * @param array<string, string> $letterSpacings
     * @return string
     */
    private function generateLetterSpacingUtilities(array $letterSpacings): string
    {
        return $this->emitSingleProperty($letterSpacings, 'tracking', 'letter-spacing');
    }

    /**
     * Generate border-radius utility classes from borderRadius tokens
     *
     * @param array<string, string> $radii
     * @return string
     */
    private function generateBorderRadiusUtilities(array $radii): string
    {
        return $this->emitSingleProperty($radii, 'rounded', 'border-radius');
    }

    /**
     * Generate box-shadow utility classes from boxShadow tokens
     *
     * @param array<string, string> $shadows
     * @return string
     */
    private function generateBoxShadowUtilities(array $shadows): string
    {
        return $this->emitSingleProperty($shadows, 'shadow', 'box-shadow');
    }

    /**
     * Generate opacity utility classes from opacity tokens
     *
     * @param array<string, string> $opacities
     * @return string
     */
    private function generateOpacityUtilities(array $opacities): string
    {
        return $this->emitSingleProperty($opacities, 'opacity', 'opacity');
    }

    /**
     * Generate z-index utility classes from zIndex tokens
     *
     * @param array<string, string> $zIndexes
     * @return string
     */
    private function generateZIndexUtilities(array $zIndexes): string
    {
        return $this->emitSingleProperty($zIndexes, 'z', 'z-index');
    }

    /**
     * Generate max-width utility classes from maxWidth tokens
     *
     * @param array<string, string> $maxWidths
     * @return string
     */
    private function generateMaxWidthUtilities(array $maxWidths): string
    {
        return $this->emitSingleProperty($maxWidths, 'max-w', 'max-width');
    }

    /**
     * Emit a `.<prefix>-<name>` rule (plus the `!`-important variant) for each token
     *
     * @param array<string, string> $tokens
     * @param string $classPrefix
     * @param string $cssProperty
     * @return string
     */
    private function emitSingleProperty(array $tokens, string $classPrefix, string $cssProperty): string
    {
        $css = [];

        foreach ($tokens as $name => $value) {
            $safeName = $this->escapeClassName((string)$name);
            $safeValue = $this->escapeValue((string)$value);
            $css[] = sprintf(
                ".%s-%s {\n    %s: %s;\n}",
                $classPrefix,
                $safeName,
                $cssProperty,
                $safeValue
            );
            $css[] = sprintf(
                ".\\!%s-%s {\n    %s: %s !important;\n}",
                $classPrefix,
                $safeName,
                $cssProperty,
                $safeValue
            );
        }

        return implode("\n\n", $css);
    }

    /**
     * Escape a CSS selector to prevent injection
     *
     * @param string $selector
     * @return string
     */
    private function escapeSelector(string $selector): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\s,.*>#:+~\[\]=\'"()]/', '', $selector) ?? $selector;
    }

    /**
     * Escape a CSS class name to prevent injection
     *
     * @param string $className
     * @return string
     */
    private function escapeClassName(string $className): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '-', $className) ?? $className;
    }

    /**
     * Escape a CSS property name to prevent injection
     *
     * @param string $property
     * @return string
     */
    private function escapeProperty(string $property): string
    {
        return preg_replace('/[^a-zA-Z0-9\-]/', '', $property) ?? $property;
    }

    /**
     * Escape a CSS value to prevent injection
     *
     * @param string $value
     * @return string
     */
    private function escapeValue(string $value): string
    {
        return preg_replace('/[;\{\}]/', '', $value) ?? $value;
    }
}
