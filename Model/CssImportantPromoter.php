<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Css\CssStructureParserInterface;
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
     * @param CssStructureParserInterface $structureParser
     */
    public function __construct(
        private readonly CssStructureParserInterface $structureParser
    ) {
    }

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

        foreach ($this->structureParser->splitRuleList($css) as $node) {
            if ($node['body'] === null) {
                // Trailing text, or an unbalanced remainder - emit it verbatim rather than
                // guessing where its block was meant to end.
                $result .= $node['prelude'];
                continue;
            }

            $result .= $node['prelude'] . '{' . $this->promoteBlock($node['prelude'], $node['body']) . '}';
        }

        return $result;
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
        $atRule = $this->structureParser->resolveAtRuleName($prelude);

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
     * Append `!important` to each eligible declaration of a declaration block
     *
     * @param string $body
     * @return string
     */
    private function promoteDeclarations(string $body): string
    {
        $promoted = array_map(
            function (string $declaration): string {
                return $this->promoteDeclaration($declaration);
            },
            $this->structureParser->splitDeclarations($body)
        );

        return implode(';', $promoted);
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
}
