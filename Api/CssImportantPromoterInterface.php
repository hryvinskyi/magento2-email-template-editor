<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Promote every declaration of the editor's own CSS to `!important`.
 *
 * Magento inlines `css/email-inline.css` into `style="…"` attributes through the
 * `{{inlinecss}}` directive, which runs as an after-filter callback inside
 * `AbstractTemplate::getProcessedTemplate()` - i.e. strictly before this module gets a
 * chance to inline the override's Tailwind/custom CSS. Emogrifier deliberately re-applies
 * pre-existing `style` attributes *after* every stylesheet rule
 * (`CssInliner::fillStyleAttributesWithMergedStyles`), so by the time the editor's CSS is
 * inlined the base template's declarations already sit inline and win regardless of
 * selector specificity - `.text-black` loses to a `color` that `a { … }` put inline.
 *
 * The single exception Emogrifier honours is `!important`: a declaration flagged important
 * beats the pre-existing inline value. Promoting the editor's CSS therefore restores the
 * intent "Tailwind always sits on top of the stock template styles" without the author
 * having to write `!text-black` everywhere. Emogrifier strips the annotation from the final
 * inline styles again (`removeImportantAnnotationFromAllInlineStyles`), so the sent email
 * carries no `!important` in its `style` attributes.
 */
interface CssImportantPromoterInterface
{
    /**
     * Append `!important` to every declaration that does not already carry it
     *
     * Left untouched:
     * - declarations already flagged `!important`;
     * - custom properties (`--x: …`), where the flag is meaningless after resolution;
     * - descriptor at-rules where `!important` is invalid and would void the whole
     *   declaration (`@font-face`, `@keyframes`, `@property`, `@page`, `@counter-style`, …).
     *
     * Conditional group at-rules (`@media`, `@supports`, `@container`, …) are recursed into.
     *
     * @param string $css CSS whose declarations should outrank pre-inlined style attributes
     * @return string The same CSS with `!important` added to every eligible declaration
     */
    public function promote(string $css): string;
}
