<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Replace every `var()` with a literal, and drop the custom properties behind them.
 *
 * Email clients do not implement custom properties, so a stylesheet that still relies on them
 * is a stylesheet the recipient renders without. Substitution is only safe if it produces the
 * value the client would have computed for that element, which makes the scope a property was
 * declared in part of the answer rather than a detail: the same `--tw-border-style` means
 * `dashed` inside `.border-dashed` and `solid` everywhere else, and a `--color-*` set under
 * `@media (prefers-color-scheme: dark)` means nothing at all to an email that is inlined once
 * and delivered to every client alike.
 */
interface CssVariableResolverInterface
{
    /**
     * Resolve every `var()` reference against the scope the rule using it sits in
     *
     * A reference is answered by the innermost scope that declares the property - the rule's
     * own block first, then the document-wide rules (`:root`, `:host`, `html`, `body`, `*`,
     * `@theme`) of each enclosing rule list, outwards. Grouping at-rules (`@media`,
     * `@supports`, `@container`, …) open a scope of their own, so nothing declared inside one
     * reaches the rules outside it. A reference nothing in scope answers keeps its written
     * fallback, and failing that is left in the output rather than replaced with a guess.
     *
     * Custom property declarations are removed once they have been read, along with any rule
     * block left empty behind them.
     *
     * @param string $css CSS content containing custom property definitions and var() references
     * @return string CSS with var() references replaced by resolved values
     */
    public function resolve(string $css): string;
}
