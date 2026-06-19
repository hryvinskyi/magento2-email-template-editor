<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\CssVariableResolverInterface;

class CssVariableResolver implements CssVariableResolverInterface
{
    /**
     * {@inheritDoc}
     */
    public function resolve(string $css): string
    {
        $variables = $this->extractVariables($css);
        $css = $this->replaceVarReferences($css, $variables);
        $css = $this->convertModernColorSyntax($css);
        $css = $this->removeVariableDeclarations($css);
        $css = $this->removeEmptyRuleBlocks($css);

        return trim($css);
    }

    /**
     * Extract all CSS custom property definitions from the CSS content
     *
     * @param string $css CSS content to parse
     * @return array<string, string> Map of variable names to their values
     */
    private function extractVariables(string $css): array
    {
        $variables = [];

        // Match --name: value; (semicolon-terminated, bounded by ; or {})
        if (preg_match_all('/(--[\w-]+)\s*:\s*([^;{}]+);/', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // A custom property's value never carries the !important flag into a var()
                // substitution - per spec the flag applies to the declaration that sets the
                // property, not to its substituted value. Strip it so substitutions stay valid
                // (e.g. "--tw-bg-opacity: 1 !important" must yield "1", not "1 !important",
                // otherwise it corrupts values like "rgb(255 255 255 / var(--tw-bg-opacity))").
                $value = trim((string)preg_replace('/\s*!important\s*$/i', '', trim($match[2])));
                if ($value !== '') {
                    $variables[trim($match[1])] = $value;
                }
            }
        }

        return $variables;
    }

    /**
     * Replace all var() references with resolved values
     *
     * Handles fallback values and iterates multiple passes
     * to resolve chained variable references.
     *
     * @param string $css CSS content with var() references
     * @param array<string, string> $variables Map of variable names to values
     * @return string CSS with var() references replaced by actual values
     */
    private function replaceVarReferences(string $css, array $variables): string
    {
        $maxIterations = 10;
        $iteration = 0;

        while (str_contains($css, 'var(') && $iteration < $maxIterations) {
            $css = (string) preg_replace_callback(
                // Allow empty fallback (`var(--x,)`) - Tailwind v4 emits this form for
                // `filter`/`transform` composition slots that default to "no contribution".
                '/var\(\s*(--[\w-]+)\s*(?:,\s*([^)]*))?\s*\)/',
                static function (array $matches) use ($variables): string {
                    $varName = trim($matches[1]);
                    $fallback = isset($matches[2]) ? trim($matches[2]) : null;

                    if (isset($variables[$varName])) {
                        return $variables[$varName];
                    }

                    if ($fallback !== null) {
                        return $fallback;
                    }

                    return $matches[0];
                },
                $css
            );
            $iteration++;
        }

        return $css;
    }

    /**
     * Convert modern CSS color syntax to legacy format for email client compatibility
     *
     * Converts rgb(R G B / alpha) to rgba(R, G, B, alpha) and
     * hsl(H S L / alpha) to hsla(H, S, L, alpha) since Emogrifier
     * and many email clients do not support the modern space-separated syntax.
     *
     * @param string $css CSS content to process
     * @return string CSS with modern color functions converted to legacy format
     */
    private function convertModernColorSyntax(string $css): string
    {
        // Match rgb(R G B / alpha) or hsl(H S L / alpha)
        return (string) preg_replace_callback(
            '/(rgb|hsl)a?\(\s*(\d+(?:\.\d+)?%?)\s+(\d+(?:\.\d+)?%?)\s+(\d+(?:\.\d+)?%?)\s*(?:\/\s*(\d+(?:\.\d+)?%?))?\s*\)/',
            static function (array $matches): string {
                $func = $matches[1];
                $v1 = $matches[2];
                $v2 = $matches[3];
                $v3 = $matches[4];
                $alpha = $matches[5] ?? null;

                if ($alpha !== null) {
                    return $func . 'a(' . $v1 . ', ' . $v2 . ', ' . $v3 . ', ' . $alpha . ')';
                }

                return $func . '(' . $v1 . ', ' . $v2 . ', ' . $v3 . ')';
            },
            $css
        );
    }

    /**
     * Remove all CSS custom property declarations from rule blocks
     *
     * Handles both semicolon-terminated declarations and the last
     * declaration in a block (which may lack a trailing semicolon).
     *
     * @param string $css CSS content to process
     * @return string CSS with custom property declarations removed
     */
    private function removeVariableDeclarations(string $css): string
    {
        // Remove semicolon-terminated variable declarations (bounded by ; or {})
        $css = (string) preg_replace('/--[\w-]+\s*:[^;{}]*;\s*/', '', $css);

        // Remove last variable declaration in a block (no trailing semicolon, followed by })
        $css = (string) preg_replace('/--[\w-]+\s*:[^;{}]*(?=\s*\})/', '', $css);

        return $css;
    }

    /**
     * Remove CSS rule blocks that have no declarations left after variable removal
     *
     * @param string $css CSS content to clean up
     * @return string CSS with empty rule blocks removed
     */
    private function removeEmptyRuleBlocks(string $css): string
    {
        return (string) preg_replace('/[^{}]+\{\s*\}/', '', $css);
    }
}
