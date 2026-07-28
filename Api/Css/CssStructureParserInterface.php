<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Css;

/**
 * Split CSS source into its structural parts without interpreting them.
 *
 * This is the one shared reader for every stage of the inlining pipeline - layer flattening,
 * variable resolution and `!important` promotion all need the same three questions answered
 * (where does this block end, where does this declaration end, is this prelude an at-rule),
 * and all three used to answer them with their own regular expressions.
 *
 * Every method is lossless: the substrings handed back can be concatenated to rebuild the
 * exact input, byte for byte. That property is what lets a caller rewrite one declaration and
 * leave the author's formatting, comments and whitespace everywhere else untouched.
 */
interface CssStructureParserInterface
{
    /**
     * Split a rule list into its `prelude { body }` nodes
     *
     * The last node may carry `body === null`: that is the trailing text after the final
     * block, and also how unbalanced input is surfaced - everything from the unmatched `{`
     * onwards is handed back verbatim rather than guessed at.
     *
     * The input is reproduced exactly by concatenating, for each node, its prelude followed by
     * `{`, its body and `}` whenever the body is not null.
     *
     * @param string $css A rule list: a stylesheet, or the body of a group at-rule
     * @return array<int, array{prelude: string, body: string|null}>
     */
    public function splitRuleList(string $css): array;

    /**
     * Split a declaration block body on the semicolons that actually separate declarations
     *
     * Semicolons inside strings, comments, parentheses (`url(data:…;base64,…)`) and nested
     * blocks are part of a value, not separators, and do not split.
     *
     * The input is reproduced exactly by `implode(';', …)`, so the returned list always holds
     * at least one element - the text following the last separator, which is empty when the
     * body ends in a semicolon.
     *
     * @param string $body Block body without the surrounding braces
     * @return array<int, string> Raw segments, no trimming applied
     */
    public function splitDeclarations(string $body): array;

    /**
     * Separate a block prelude into the statements preceding it and the selector itself
     *
     * A prelude starts right after the previous block ended, so it can still carry statement
     * at-rules that own no block (`@charset`, `@import`, `@layer a, b;`). Those belong to the
     * stylesheet, not to the block that follows, and a caller that drops or rewrites the block
     * must keep them. Concatenating both parts reproduces the prelude.
     *
     * @param string $prelude Text between the end of the previous block and the opening brace
     * @return array{statements: string, selector: string}
     */
    public function splitPrelude(string $prelude): array;

    /**
     * Extract the lower-cased at-rule name a prelude introduces
     *
     * Leading statement at-rules and comments are ignored, so the answer describes the block
     * about to open rather than anything that preceded it.
     *
     * @param string $prelude Block prelude, or a bare selector
     * @return string|null Null when the prelude is a plain selector
     */
    public function resolveAtRuleName(string $prelude): ?string;
}
