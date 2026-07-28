<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Css\CssStructureParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssLayerFlattenerInterface;

class CssLayerFlattener implements CssLayerFlattenerInterface
{
    /**
     * Layer names whose contents must never reach the inliner
     *
     * `base` is Tailwind's preflight - resets aimed at `*`, `html` and `body` that would be
     * inlined onto every element of a table-based email. `properties` is the custom-property
     * scope reset, which only exists to give registered slots an initial value in browsers.
     */
    private const DROPPED_LAYER_NAME_PATTERN = '/\b(?:base|properties)\b/i';

    /**
     * At-rules whose blocks hold further rules, so a nested `@layer` can hide inside them
     */
    private const GROUP_AT_RULES = [
        'media',
        'supports',
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
    public function flatten(string $css): string
    {
        if (trim($css) === '') {
            return $css;
        }

        $propertyDefaults = [];
        $flattened = $this->flattenRuleList($css, $propertyDefaults);

        return $this->prependPropertyDefaults($flattened, $propertyDefaults);
    }

    /**
     * Walk a rule list, unwrapping layers and harvesting `@property` defaults as it goes
     *
     * @param string $css A stylesheet or the body of a group at-rule
     * @param array<string, string> $propertyDefaults Collected registrations, name to initial value
     * @return string
     */
    private function flattenRuleList(string $css, array &$propertyDefaults): string
    {
        $result = '';

        foreach ($this->structureParser->splitRuleList($css) as $node) {
            ['statements' => $statements, 'selector' => $selector] =
                $this->structureParser->splitPrelude($node['prelude']);

            // Statement-form `@layer a, b;` only declares layer order; nothing else in the
            // prelude - `@charset`, `@import` - may be lost with it.
            $result .= $this->dropLayerStatements($statements);

            if ($node['body'] === null) {
                $result .= $selector;
                continue;
            }

            $result .= $this->flattenBlock($selector, $node['body'], $propertyDefaults);
        }

        return $result;
    }

    /**
     * Flatten one block according to the at-rule its prelude opens
     *
     * @param string $selector Block selector or at-rule, statement at-rules already removed
     * @param string $body Block content without the surrounding braces
     * @param array<string, string> $propertyDefaults Collected registrations, name to initial value
     * @return string
     */
    private function flattenBlock(string $selector, string $body, array &$propertyDefaults): string
    {
        $atRule = $this->structureParser->resolveAtRuleName($selector);

        if ($atRule === 'layer') {
            if (preg_match(self::DROPPED_LAYER_NAME_PATTERN, $this->readLayerNames($selector)) === 1) {
                // Walk the body anyway: a `@property` registration inside a dropped layer
                // still has to contribute its initial value, or the slot it registers becomes
                // unresolvable in the utilities that use it.
                $this->flattenRuleList($body, $propertyDefaults);

                return '';
            }

            return $this->flattenRuleList($body, $propertyDefaults);
        }

        if ($atRule === 'property') {
            $this->harvestPropertyDefault($selector, $body, $propertyDefaults);

            return '';
        }

        if ($atRule !== null && in_array($atRule, self::GROUP_AT_RULES, true)) {
            return $selector . '{' . $this->flattenRuleList($body, $propertyDefaults) . '}';
        }

        return $selector . '{' . $body . '}';
    }

    /**
     * Remove statement-form `@layer` declarations from a prelude's statement run
     *
     * @param string $statements Statement at-rules preceding a block, terminating `;` included
     * @return string
     */
    private function dropLayerStatements(string $statements): string
    {
        if (!str_contains($statements, '@')) {
            return $statements;
        }

        $segments = $this->structureParser->splitDeclarations($statements);
        $trailing = array_pop($segments) ?? '';
        $result = '';

        foreach ($segments as $segment) {
            if (preg_match('/^\s*@layer\b/i', $segment) === 1) {
                continue;
            }

            $result .= $segment . ';';
        }

        return $result . $trailing;
    }

    /**
     * Read the layer name list a `@layer` prelude carries
     *
     * @param string $selector Prelude opening the layer block
     * @return string The raw name list, empty for an anonymous layer
     */
    private function readLayerNames(string $selector): string
    {
        $withoutComments = preg_replace('#/\*.*?\*/#s', '', $selector) ?? $selector;

        if (preg_match('/@layer\b(.*)$/is', $withoutComments, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    /**
     * Record the `initial-value` a `@property` rule registers, if it declares one
     *
     * Tailwind v4 registers composition slots such as `--tw-border-style` with
     * `@property --tw-border-style { syntax: "*"; inherits: false; initial-value: solid }`
     * and then only ever emits `border-style: var(--tw-border-style)` in the utility itself.
     * Dropping the registration without keeping its default leaves that `var()` unresolvable,
     * so `.border-2` inlines as an invalid declaration that computes to `border-style: none` -
     * an invisible border.
     *
     * @param string $selector Prelude opening the `@property` block
     * @param string $body Block content without the surrounding braces
     * @param array<string, string> $propertyDefaults Collected registrations, name to initial value
     * @return void
     */
    private function harvestPropertyDefault(string $selector, string $body, array &$propertyDefaults): void
    {
        if (preg_match('/@property\s+(--[\w-]+)/i', $selector, $nameMatch) !== 1) {
            return;
        }

        foreach ($this->structureParser->splitDeclarations($body) as $segment) {
            $colonPosition = strpos($segment, ':');
            if ($colonPosition === false) {
                continue;
            }

            if (strtolower(trim(substr($segment, 0, $colonPosition))) !== 'initial-value') {
                continue;
            }

            $value = trim(substr($segment, $colonPosition + 1));
            if ($value !== '') {
                $propertyDefaults[$nameMatch[1]] = $value;
            }
        }
    }

    /**
     * Emit the harvested `@property` defaults as a leading `:root { … }` block
     *
     * `:root` is the resolver's outermost scope, so the defaults are visible to every rule and
     * are overridden by any declaration a rule makes for itself - which is precisely the
     * relationship a registered initial value has with a real declaration. The block itself
     * never reaches the inliner: the resolver strips custom-property declarations and then
     * removes the rule block left empty behind them.
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
