<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Css\CssStructureParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Css\CssSyntaxScannerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssColorConverterInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssVariableResolverInterface;

/**
 * Substitute CSS custom properties, honouring the scope each one was declared in.
 *
 * Email clients do not implement custom properties, so every `var()` has to be replaced with a
 * literal before the stylesheet is inlined. The replacement is only correct if it answers the
 * question a browser would answer - *which* declaration of that property applies *here* - which
 * means the resolver has to know where each declaration sits in the stylesheet:
 *
 * - A declaration in a rule's own block applies to that rule and to rules nested inside it.
 *   `.border-dashed { --tw-border-style: dashed }` changes `.border-dashed`, and nothing else.
 * - A declaration on a document-wide selector (`:root`, `:host`, `html`, `body`, `*`) or in a
 *   Tailwind `@theme` block applies to every rule in the same rule list; `:root` at the top
 *   level is therefore the outermost scope.
 * - A declaration inside `@media`, `@supports`, `@container`, … applies only to the rules
 *   inside that at-rule. The inlined email is the unconditional rendering, so an override
 *   under `@media (prefers-color-scheme: dark)` must not reach the rules outside it.
 *
 * Lookup walks that chain from the innermost scope outwards and takes the first hit; within one
 * scope the last declaration wins, matching the cascade for equally specific rules.
 */
class CssVariableResolver implements CssVariableResolverInterface
{
    /**
     * Upper bound on substitution rounds for one value
     *
     * Each round replaces the `var()` references currently present; a value that resolves to
     * another `var()` needs one more. The bound is what stops `--a: var(--b); --b: var(--a)`
     * from looping forever - the reference is then left in place rather than guessed at.
     */
    private const MAX_SUBSTITUTION_ROUNDS = 10;

    /**
     * Selectors whose declarations reach every element, and so define a whole scope
     *
     * `html` and `body` are included because custom properties inherit: a property set there
     * is visible to every descendant, which for an email body is everything.
     */
    private const DOCUMENT_WIDE_SELECTORS = [
        '*',
        ':root',
        ':host',
        'html',
        'body',
        'html body',
    ];

    /**
     * At-rules that open a new rule list and confine whatever is declared inside them
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
     * At-rules whose block declares custom properties for the whole enclosing rule list
     *
     * Tailwind v4's `@theme { --color-*: … }` is compiled into `:root` by the real compiler,
     * and the editor passes the authored theme through verbatim, so it has to be read the
     * same way here.
     */
    private const SCOPE_DEFINING_AT_RULES = [
        'theme',
    ];

    /**
     * @param CssColorConverterInterface $colorConverter
     * @param CssStructureParserInterface $structureParser
     * @param CssSyntaxScannerInterface $syntaxScanner
     */
    public function __construct(
        private readonly CssColorConverterInterface $colorConverter,
        private readonly CssStructureParserInterface $structureParser,
        private readonly CssSyntaxScannerInterface $syntaxScanner
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(string $css): string
    {
        $css = $this->rewriteRuleList($css, []);
        // Colour conversion has to run *after* substitution: Tailwind v4 keeps its whole
        // palette behind `--color-*` variables whose values are `oklch(…)`, so before this
        // point there is nothing to convert.
        $css = $this->colorConverter->toLegacy($css);

        return trim($css);
    }

    /**
     * Rewrite a rule list: substitute inside it, drop its custom-property declarations
     *
     * The rule list is its own scope. Its document-wide rules are collected first, because a
     * `:root` block may follow the rules that reference it and still apply to them, and the
     * resulting map is pushed in front of the enclosing chain.
     *
     * @param string $css A stylesheet or the body of a group at-rule
     * @param array<int, array<string, string>> $scopeChain Enclosing scopes, innermost first
     * @return string
     */
    private function rewriteRuleList(string $css, array $scopeChain): string
    {
        $nodes = $this->structureParser->splitRuleList($css);
        $scopeVariables = $this->collectScopeVariables($nodes);
        $chain = $scopeVariables === [] ? $scopeChain : array_merge([$scopeVariables], $scopeChain);

        $result = '';

        foreach ($nodes as $node) {
            if ($node['body'] === null) {
                $result .= $node['prelude'];
                continue;
            }

            ['statements' => $statements, 'selector' => $selector] =
                $this->structureParser->splitPrelude($node['prelude']);

            $body = $this->rewriteBlock($selector, $node['body'], $chain);

            if (trim($body) === '') {
                // Nothing but custom properties lived here. Drop the block, but never the
                // statement at-rules that happened to precede it in the same prelude.
                $result .= $statements;
                continue;
            }

            $result .= $statements . $selector . '{' . $body . '}';
        }

        return $result;
    }

    /**
     * Rewrite one block according to what its prelude opens
     *
     * @param string $selector Block selector or at-rule, statement at-rules already removed
     * @param string $body Block content without the surrounding braces
     * @param array<int, array<string, string>> $chain Scopes in force here, innermost first
     * @return string
     */
    private function rewriteBlock(string $selector, string $body, array $chain): string
    {
        $atRule = $this->structureParser->resolveAtRuleName($selector);

        if ($atRule !== null
            && (in_array($atRule, self::GROUP_AT_RULES, true) || str_contains($atRule, 'keyframes'))
        ) {
            return $this->rewriteRuleList($body, $chain);
        }

        // Style rules, `@theme`, and descriptor at-rules (`@font-face`, `@property`, `@page`)
        // all hold a flat declaration list. Descriptors cannot legally declare a custom
        // property, so treating them the same way only ever substitutes their `var()` uses.
        return $this->rewriteDeclarationBlock($body, $chain);
    }

    /**
     * Substitute inside a declaration list and strip the custom properties it declared
     *
     * @param string $body Declaration list without the surrounding braces
     * @param array<int, array<string, string>> $chain Scopes in force here, innermost first
     * @return string
     */
    private function rewriteDeclarationBlock(string $body, array $chain): string
    {
        $localVariables = $this->collectDeclaredVariables($body);
        $localChain = $localVariables === [] ? $chain : array_merge([$localVariables], $chain);

        $kept = [];

        foreach ($this->structureParser->splitDeclarations($body) as $segment) {
            if (str_contains($segment, '{')) {
                // A nested rule. It sees this block's declarations, so it resolves against
                // the local chain - which is exactly what the browser does for a nested rule.
                $kept[] = $this->rewriteRuleList($segment, $localChain);
                continue;
            }

            $property = $this->readPropertyName($segment);
            if ($property !== null && str_starts_with($property, '--')) {
                continue;
            }

            $kept[] = $this->substituteVariables($segment, $localChain);
        }

        return implode(';', $kept);
    }

    /**
     * Collect the custom properties a rule list publishes to everything inside it
     *
     * @param array<int, array{prelude: string, body: string|null}> $nodes
     * @return array<string, string> Property name to value, later declarations winning
     */
    private function collectScopeVariables(array $nodes): array
    {
        $variables = [];

        foreach ($nodes as $node) {
            if ($node['body'] === null) {
                continue;
            }

            $selector = $this->structureParser->splitPrelude($node['prelude'])['selector'];
            $atRule = $this->structureParser->resolveAtRuleName($selector);

            if ($atRule === null) {
                if (!$this->isDocumentWideSelector($selector)) {
                    continue;
                }
            } elseif (!in_array($atRule, self::SCOPE_DEFINING_AT_RULES, true)) {
                continue;
            }

            $variables = array_merge($variables, $this->collectDeclaredVariables($node['body']));
        }

        return $variables;
    }

    /**
     * Read the custom properties declared directly in one declaration list
     *
     * @param string $body Declaration list without the surrounding braces
     * @return array<string, string> Property name to value, later declarations winning
     */
    private function collectDeclaredVariables(string $body): array
    {
        $variables = [];

        foreach ($this->structureParser->splitDeclarations($body) as $segment) {
            if (str_contains($segment, '{')) {
                continue;
            }

            $name = $this->readPropertyName($segment);
            if ($name === null || !str_starts_with($name, '--') || $name === '--') {
                continue;
            }

            $colonPosition = strpos($segment, ':');
            if ($colonPosition === false) {
                continue;
            }

            $value = $this->stripImportantFlag(substr($segment, $colonPosition + 1));
            if ($value === '') {
                continue;
            }

            $variables[$name] = $value;
        }

        return $variables;
    }

    /**
     * Drop a trailing `!important` from a custom property's value
     *
     * Per spec the flag applies to the declaration that sets the property, never to the value
     * substituted in its place - carrying it along would produce invalid output such as
     * `rgb(255 255 255 / 1 !important)`.
     *
     * @param string $value Raw text following the colon
     * @return string Trimmed value without the flag
     */
    private function stripImportantFlag(string $value): string
    {
        $stripped = preg_replace('/\s*!\s*important\s*$/i', '', trim($value));

        return trim($stripped ?? $value);
    }

    /**
     * Read the property name a declaration segment sets
     *
     * @param string $segment Raw declaration text
     * @return string|null Null when the segment holds no `property: value` pair
     */
    private function readPropertyName(string $segment): ?string
    {
        $colonPosition = strpos($segment, ':');
        if ($colonPosition === false) {
            return null;
        }

        $name = trim(substr($segment, 0, $colonPosition));

        return $name === '' ? null : $name;
    }

    /**
     * Decide whether a selector declares for the whole rule list rather than for its own rule
     *
     * @param string $selector
     * @return bool
     */
    private function isDocumentWideSelector(string $selector): bool
    {
        $withoutComments = preg_replace('#/\*.*?\*/#s', '', $selector);
        $collapsed = preg_replace('/\s+/', ' ', $withoutComments ?? $selector);

        foreach (explode(',', strtolower($collapsed ?? $selector)) as $part) {
            $normalized = trim($part);

            if (in_array($normalized, self::DOCUMENT_WIDE_SELECTORS, true)
                || str_starts_with($normalized, ':host(')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace every `var()` reference in a fragment until nothing more resolves
     *
     * @param string $text Declaration text
     * @param array<int, array<string, string>> $chain Scopes in force here, innermost first
     * @return string
     */
    private function substituteVariables(string $text, array $chain): string
    {
        for ($round = 0; $round < self::MAX_SUBSTITUTION_ROUNDS; $round++) {
            if (stripos($text, 'var(') === false) {
                break;
            }

            $substituted = $this->substituteVariablesOnce($text, $chain);
            if ($substituted === $text) {
                break;
            }

            $text = $substituted;
        }

        return $text;
    }

    /**
     * Run a single left-to-right substitution pass
     *
     * Whatever a reference expands to is not re-scanned in the same pass; the caller's loop
     * picks it up, which keeps a self-referential value from expanding without end.
     *
     * @param string $text Declaration text
     * @param array<int, array<string, string>> $chain Scopes in force here, innermost first
     * @return string
     */
    private function substituteVariablesOnce(string $text, array $chain): string
    {
        $result = '';
        $length = strlen($text);
        $position = 0;
        $copiedTo = 0;

        while ($position < $length) {
            $character = $text[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->syntaxScanner->skipString($text, $position);
                continue;
            }

            if ($character === '/' && ($text[$position + 1] ?? '') === '*') {
                $position = $this->syntaxScanner->skipComment($text, $position);
                continue;
            }

            if (!$this->startsFunctionCall($text, $position, 'var')) {
                $position++;
                continue;
            }

            $referenceEnd = $this->syntaxScanner->findParenthesisEnd($text, $position + 3);
            if ($referenceEnd === -1) {
                break;
            }

            $arguments = substr($text, $position + 4, $referenceEnd - $position - 4);
            $replacement = $this->resolveReference($arguments, $chain);

            if ($replacement === null) {
                $position = $referenceEnd + 1;
                continue;
            }

            $result .= substr($text, $copiedTo, $position - $copiedTo) . $replacement;
            $position = $referenceEnd + 1;
            $copiedTo = $position;
        }

        return $result . substr($text, $copiedTo);
    }

    /**
     * Decide whether a function call by that name starts at the given offset
     *
     * The preceding byte is checked so that an identifier merely ending in the name -
     * `--my-var(`, `nonvar(` - is not mistaken for the call itself.
     *
     * @param string $text
     * @param int $position
     * @param string $name Lower-case function name
     * @return bool
     */
    private function startsFunctionCall(string $text, int $position, string $name): bool
    {
        if (strcasecmp(substr($text, $position, strlen($name) + 1), $name . '(') !== 0) {
            return false;
        }

        $previous = $position > 0 ? $text[$position - 1] : '';

        return $previous === '' || preg_match('/[-\w\\\\]/', $previous) !== 1;
    }

    /**
     * Resolve the argument list of one `var()` reference
     *
     * @param string $arguments Text between the parentheses
     * @param array<int, array<string, string>> $chain Scopes in force here, innermost first
     * @return string|null The replacement, or null to leave the reference in the output
     */
    private function resolveReference(string $arguments, array $chain): ?string
    {
        $commaPosition = $this->findArgumentSeparator($arguments);
        $name = trim($commaPosition === -1 ? $arguments : substr($arguments, 0, $commaPosition));

        if (!str_starts_with($name, '--') || preg_match('/^--[^\s(),;{}]+$/', $name) !== 1) {
            return null;
        }

        foreach ($chain as $scope) {
            if (isset($scope[$name])) {
                return $scope[$name];
            }
        }

        if ($commaPosition === -1) {
            // No fallback was written, and nothing in scope declares the property. Leaving the
            // reference intact keeps the mistake visible in the rendered email instead of
            // turning the declaration into a plausible-looking wrong value.
            return null;
        }

        // An empty fallback is a fallback: Tailwind v4 writes `var(--tw-blur,)` for composition
        // slots that must contribute nothing when unset.
        return trim(substr($arguments, $commaPosition + 1));
    }

    /**
     * Offset of the comma separating a `var()` reference's name from its fallback
     *
     * @param string $arguments Text between the parentheses
     * @return int Offset of the comma, or -1 when the reference carries no fallback
     */
    private function findArgumentSeparator(string $arguments): int
    {
        $length = strlen($arguments);
        $position = 0;

        while ($position < $length) {
            $character = $arguments[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->syntaxScanner->skipString($arguments, $position);
                continue;
            }

            if ($character === '/' && ($arguments[$position + 1] ?? '') === '*') {
                $position = $this->syntaxScanner->skipComment($arguments, $position);
                continue;
            }

            if ($character === '(') {
                $groupEnd = $this->syntaxScanner->findParenthesisEnd($arguments, $position);
                if ($groupEnd === -1) {
                    break;
                }
                $position = $groupEnd + 1;
                continue;
            }

            if ($character === ',') {
                return $position;
            }

            $position++;
        }

        return -1;
    }
}
