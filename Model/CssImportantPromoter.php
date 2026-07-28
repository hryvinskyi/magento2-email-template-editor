<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\CssImportantPromoterInterface;

class CssImportantPromoter implements CssImportantPromoterInterface
{
    /**
     * At-rules whose blocks hold descriptors rather than declarations
     *
     * `!important` is invalid there and makes the browser drop the whole descriptor, so a
     * blanket promotion would silently break `@font-face` sources and keyframe steps.
     */
    private const DESCRIPTOR_AT_RULES = [
        'font-face',
        'property',
        'page',
        'counter-style',
        'font-feature-values',
        'font-palette-values',
        'color-profile',
        'viewport',
    ];

    /**
     * At-rules whose blocks contain further style rules and must be recursed into
     */
    private const GROUP_AT_RULES = [
        'media',
        'supports',
        'layer',
        'container',
        'scope',
        'document',
        '-moz-document',
    ];

    /**
     * {@inheritDoc}
     */
    public function promote(string $css): string
    {
        if (trim($css) === '') {
            return $css;
        }

        return $this->promoteRuleList($css);
    }

    /**
     * Walk a list of rules, promoting the declarations of every style rule it contains
     *
     * @param string $css
     * @return string
     */
    private function promoteRuleList(string $css): string
    {
        $result = '';
        $length = strlen($css);
        $preludeStart = 0;
        $position = 0;

        while ($position < $length) {
            $character = $css[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->skipString($css, $position);
                continue;
            }

            if ($character === '/' && ($css[$position + 1] ?? '') === '*') {
                $position = $this->skipComment($css, $position);
                continue;
            }

            if ($character !== '{') {
                $position++;
                continue;
            }

            $blockEnd = $this->findBlockEnd($css, $position);
            if ($blockEnd === -1) {
                // Unbalanced input - emit the remainder verbatim rather than corrupting it.
                break;
            }

            $prelude = substr($css, $preludeStart, $position - $preludeStart);
            $body = substr($css, $position + 1, $blockEnd - $position - 1);

            $result .= $prelude . '{' . $this->promoteBlock($prelude, $body) . '}';

            $position = $blockEnd + 1;
            $preludeStart = $position;
        }

        return $result . substr($css, $preludeStart);
    }

    /**
     * Decide how a block body should be treated based on its prelude
     *
     * @param string $prelude The selector or at-rule preceding the block
     * @param string $body The block content without the surrounding braces
     * @return string
     */
    private function promoteBlock(string $prelude, string $body): string
    {
        $atRule = $this->resolveAtRuleName($prelude);

        if ($atRule === null) {
            return $this->promoteDeclarations($body);
        }

        if (in_array($atRule, self::GROUP_AT_RULES, true)) {
            return $this->promoteRuleList($body);
        }

        // `@keyframes`, `@-webkit-keyframes`, `@-moz-keyframes`, …
        if (str_contains($atRule, 'keyframes') || in_array($atRule, self::DESCRIPTOR_AT_RULES, true)) {
            return $body;
        }

        // Unknown at-rule: leaving it untouched can only lose a promotion, never break output.
        return $body;
    }

    /**
     * Extract the lower-cased at-rule name from a block prelude
     *
     * A prelude starts right after the previous block, so it may still carry statement
     * at-rules that need no block of their own (`@charset`, `@import`, `@layer a, b;`).
     * Those are cut off at the last top-level `;` first, otherwise a selector following an
     * `@import` would be misread as an `@import` block and skipped.
     *
     * @param string $prelude
     * @return string|null Null when the prelude is a plain selector
     */
    private function resolveAtRuleName(string $prelude): ?string
    {
        $selector = substr($prelude, $this->findLastStatementEnd($prelude));
        $withoutComments = (string)preg_replace('#/\*.*?\*/#s', '', $selector);

        if (preg_match('/^\s*@([-\w]+)/', $withoutComments, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Offset just past the last statement-terminating `;` in a block prelude
     *
     * @param string $prelude
     * @return int Zero when the prelude holds no statement at-rule
     */
    private function findLastStatementEnd(string $prelude): int
    {
        $length = strlen($prelude);
        $position = 0;
        $parenDepth = 0;
        $end = 0;

        while ($position < $length) {
            $character = $prelude[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->skipString($prelude, $position);
                continue;
            }

            if ($character === '/' && ($prelude[$position + 1] ?? '') === '*') {
                $position = $this->skipComment($prelude, $position);
                continue;
            }

            if ($character === '(') {
                $parenDepth++;
            } elseif ($character === ')') {
                $parenDepth = max(0, $parenDepth - 1);
            } elseif ($character === ';' && $parenDepth === 0) {
                $end = $position + 1;
            }

            $position++;
        }

        return $end;
    }

    /**
     * Append `!important` to each eligible declaration of a declaration block
     *
     * @param string $body
     * @return string
     */
    private function promoteDeclarations(string $body): string
    {
        $result = '';
        $length = strlen($body);
        $segmentStart = 0;
        $position = 0;
        $parenDepth = 0;

        while ($position < $length) {
            $character = $body[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->skipString($body, $position);
                continue;
            }

            if ($character === '/' && ($body[$position + 1] ?? '') === '*') {
                $position = $this->skipComment($body, $position);
                continue;
            }

            if ($character === '{') {
                // A nested rule (CSS nesting). Skip it wholesale - `promoteDeclaration`
                // then leaves the containing segment alone.
                $blockEnd = $this->findBlockEnd($body, $position);
                if ($blockEnd === -1) {
                    break;
                }
                $position = $blockEnd + 1;
                continue;
            }

            if ($character === '(') {
                $parenDepth++;
                $position++;
                continue;
            }

            if ($character === ')') {
                $parenDepth = max(0, $parenDepth - 1);
                $position++;
                continue;
            }

            // A `;` inside parentheses belongs to the value (e.g. a `url(data:…;base64,…)`).
            if ($character === ';' && $parenDepth === 0) {
                $result .= $this->promoteDeclaration(substr($body, $segmentStart, $position - $segmentStart)) . ';';
                $segmentStart = $position + 1;
            }

            $position++;
        }

        return $result . $this->promoteDeclaration(substr($body, $segmentStart));
    }

    /**
     * Append `!important` to a single declaration when it is eligible
     *
     * @param string $declaration Raw declaration text, without its terminating semicolon
     * @return string
     */
    private function promoteDeclaration(string $declaration): string
    {
        $trimmed = trim($declaration);

        if ($trimmed === '' || str_contains($trimmed, '{')) {
            return $declaration;
        }

        if (preg_match('/!\s*important$/i', $trimmed) === 1) {
            return $declaration;
        }

        $colonPosition = strpos($trimmed, ':');
        if ($colonPosition === false || $colonPosition === 0) {
            return $declaration;
        }

        $property = trim(substr($trimmed, 0, $colonPosition));
        if ($property === '' || str_starts_with($property, '--') || str_starts_with($property, '@')) {
            return $declaration;
        }

        if (trim(substr($trimmed, $colonPosition + 1)) === '') {
            return $declaration;
        }

        return rtrim($declaration) . ' !important';
    }

    /**
     * Return the offset just past the closing quote of the string starting at $start
     *
     * @param string $css
     * @param int $start Offset of the opening quote
     * @return int
     */
    private function skipString(string $css, int $start): int
    {
        $quote = $css[$start];
        $length = strlen($css);

        for ($position = $start + 1; $position < $length; $position++) {
            if ($css[$position] === '\\') {
                $position++;
                continue;
            }

            if ($css[$position] === $quote) {
                return $position + 1;
            }
        }

        return $length;
    }

    /**
     * Return the offset just past the closing delimiter of the comment starting at $start
     *
     * @param string $css
     * @param int $start Offset of the opening slash
     * @return int
     */
    private function skipComment(string $css, int $start): int
    {
        $end = strpos($css, '*/', $start + 2);

        return $end === false ? strlen($css) : $end + 2;
    }

    /**
     * Locate the `}` matching the `{` at $openBrace
     *
     * @param string $css
     * @param int $openBrace
     * @return int Offset of the matching closing brace, or -1 when the input is unbalanced
     */
    private function findBlockEnd(string $css, int $openBrace): int
    {
        $length = strlen($css);
        $depth = 0;

        for ($position = $openBrace; $position < $length; $position++) {
            $character = $css[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->skipString($css, $position) - 1;
                continue;
            }

            if ($character === '/' && ($css[$position + 1] ?? '') === '*') {
                $position = $this->skipComment($css, $position) - 1;
                continue;
            }

            if ($character === '{') {
                $depth++;
                continue;
            }

            if ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return -1;
    }
}
