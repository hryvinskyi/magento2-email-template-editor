<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\CssImportantPromoterInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssInlinerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssLayerFlattenerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssVariableResolverInterface;
use Pelago\Emogrifier\CssInliner as EmogrifierCssInliner;
use Psr\Log\LoggerInterface;

class CssInliner implements CssInlinerInterface
{
    /**
     * @param CssVariableResolverInterface $cssVariableResolver
     * @param LoggerInterface $logger
     * @param CssLayerFlattenerInterface $layerFlattener
     * @param CssImportantPromoterInterface $importantPromoter
     */
    public function __construct(
        private readonly CssVariableResolverInterface $cssVariableResolver,
        private readonly LoggerInterface $logger,
        private readonly CssLayerFlattenerInterface $layerFlattener,
        private readonly CssImportantPromoterInterface $importantPromoter
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function inline(
        string $html,
        ?string $customCss = null,
        ?string $tailwindCss = null,
        ?string $themeCss = null
    ): string {
        // Theme → tailwind → custom, matching EmailTemplatePlugin::buildCombinedCss so the
        // preview resolves conflicts exactly like the sent email does. Order is load-bearing:
        // the parts are promoted to !important below, so between two equally specific rules
        // the later one wins - and hand-written custom CSS should always have the last say.
        $cssParts = array_filter([$themeCss, $tailwindCss, $customCss], static function (?string $css): bool {
            return $css !== null && trim($css) !== '';
        });

        // Even when no external CSS parts are supplied, the HTML itself may carry embedded
        // <style> blocks (e.g. from an included header override's stored tailwind_css that
        // got embedded as a <style> by the plugin's afterGetProcessedTemplate). Run through
        // Emogrifier so those embedded styles are flattened and inlined.
        $hasEmbeddedStyles = stripos($html, '<style') !== false;
        if (empty($cssParts) && !$hasEmbeddedStyles) {
            return $html;
        }

        $combinedCss = implode("\n", $cssParts);
        // Flatten BEFORE resolving. The resolver reads `@layer` as a scope like any other
        // grouping at-rule, so a `:root` still sitting inside one would only be visible to the
        // rules in that same layer - while Tailwind emits its palette in `@layer theme` and the
        // utilities that reference it in `@layer utilities`. Unwrapping first puts both in the
        // same scope, which is what the browser sees once layer order is applied.
        $combinedCss = $this->layerFlattener->flatten($combinedCss);
        $combinedCss = $this->cssVariableResolver->resolve($combinedCss);
        // Magento's own {{inlinecss}} directive has already turned css/email-inline.css into
        // `style="…"` attributes by the time this runs, and Emogrifier re-applies pre-existing
        // inline styles last - so an editor utility only wins if it is flagged !important.
        // Promote here, after resolution, so no `var()` or custom property carries the flag.
        $combinedCss = $this->importantPromoter->promote($combinedCss);
        $combinedCss = $this->rewriteEscapedClassSelectors($combinedCss);

        // Inline <style> blocks embedded in the HTML may themselves be wrapped in @layer
        // (this happens when an override's stored tailwind_css is embedded as a <style>
        // tag during template processing of an included header/footer). Flatten the page's
        // own style blocks too so Emogrifier can see their rules.
        $html = $this->flattenStyleBlocksInHtml($html);

        try {
            return EmogrifierCssInliner::fromHtml($html)
                ->inlineCss($combinedCss)
                ->render();
        } catch (\Exception $e) {
            $this->logger->error('CSS inlining failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $html;
        }
    }

    /**
     * Run layer flattening and variable resolution over every `<style>` block in the HTML
     *
     * Embedded `<style>` blocks may originate from an included override that the plugin
     * embedded during template processing - in which case the block carries the override's
     * stored tailwind_css, complete with `@layer` wrappers and `var()` references. Neither
     * is something Emogrifier handles, so we run the same flatten + resolve pipeline that
     * external CSS parameters get.
     *
     * Deliberately *not* promoted to `!important` here: the plugin already promotes the
     * blocks it embeds, while the surrounding markup's other `<style>` blocks belong to the
     * base template - flagging those would invert the stock cascade for templates that carry
     * no editor CSS at all.
     *
     * @param string $html
     * @return string
     */
    private function flattenStyleBlocksInHtml(string $html): string
    {
        $rewritten = preg_replace_callback(
            '/(<style[^>]*>)(.*?)(<\/style>)/is',
            function (array $matches): string {
                $css = $this->layerFlattener->flatten($matches[2]);
                $css = $this->cssVariableResolver->resolve($css);
                $css = $this->rewriteEscapedClassSelectors($css);

                return $matches[1] . $css . $matches[3];
            },
            $html
        );

        return $rewritten ?? $this->reportPcreFailure('flattening embedded <style> blocks', $html);
    }

    /**
     * Restate escaped class selectors as `[class~="…"]` so Emogrifier ranks them correctly
     *
     * Emogrifier weighs selector precedence with a regex that expects a word character right
     * after the `.` (`CssInliner::$selectorPrecedenceMatchers`). Every Tailwind class that
     * needs escaping - `.\!text-black`, `.p-\[10px\]`, `.w-1\/2`, `.p-1\.5` - therefore scores
     * 1 instead of 100, i.e. it sorts as if it were a bare element selector and gets applied
     * *before* any plain class rule. The rules still match; they just lose every tie they
     * should win. The equivalent attribute selector matches the same elements and is weighed
     * as a class, so the intended cascade is restored.
     *
     * @param string $css
     * @return string
     */
    private function rewriteEscapedClassSelectors(string $css): string
    {
        if (!str_contains($css, '\\')) {
            return $css;
        }

        // `[^{}]*` before a `{` is exactly one prelude - selectors never contain braces, and
        // declaration bodies never contain a `{`, so values are never touched.
        $rewritten = preg_replace_callback(
            '/([^{}]*)\{/',
            function (array $matches): string {
                if (!str_contains($matches[1], '\\')) {
                    return $matches[0];
                }

                return $this->rewriteEscapedClassesInSelector($matches[1]) . '{';
            },
            $css
        );

        return $rewritten ?? $this->reportPcreFailure('rewriting escaped class selectors', $css);
    }

    /**
     * Replace every escape-bearing class token of a single selector with an attribute selector
     *
     * @param string $selector
     * @return string
     */
    private function rewriteEscapedClassesInSelector(string $selector): string
    {
        $rewritten = preg_replace_callback(
            '/\.((?:[-\w]|\\\\.)*\\\\.(?:[-\w]|\\\\.)*)/',
            static function (array $matches): string {
                $className = preg_replace('/\\\\(.)/', '$1', $matches[1]) ?? $matches[1];

                return '[class~="' . addcslashes($className, '"\\') . '"]';
            },
            $selector
        );

        return $rewritten ?? $this->reportPcreFailure('rewriting escaped classes in a selector', $selector);
    }

    /**
     * Log a PCRE bail-out and hand back the untouched input
     *
     * `preg_replace*` answers `null` for a failed run - a backtrack or recursion limit, or a
     * subject that is not valid UTF-8 under a `/u` pattern. Casting that to a string yields
     * `''`, which for a stylesheet means every rule silently disappears and for the markup
     * means the whole email does. The input is always the safer answer: the transformation is
     * lost, nothing else is, and the log says which one.
     *
     * @param string $stage What was being rewritten, for the log entry
     * @param string $input The subject to fall back to
     * @return string
     */
    private function reportPcreFailure(string $stage, string $input): string
    {
        $this->logger->warning(
            'CSS inlining skipped a step: pattern matching failed while ' . $stage
            . ' (' . preg_last_error_msg() . '). The input was left unchanged.'
        );

        return $input;
    }
}
