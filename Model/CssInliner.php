<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

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
     */
    public function __construct(
        private readonly CssVariableResolverInterface $cssVariableResolver,
        private readonly LoggerInterface $logger,
        private readonly CssLayerFlattenerInterface $layerFlattener
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
        $cssParts = array_filter([$customCss, $tailwindCss, $themeCss], static function (?string $css): bool {
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
        // Flatten BEFORE resolving so Tailwind's @layer properties scope-reset declarations
        // (--tw-invert: initial; …) are dropped before the resolver builds its variable map.
        // Otherwise their `initial` values would shadow per-rule defaults like
        // `.invert { --tw-invert: invert(100%); }`.
        $combinedCss = $this->layerFlattener->flatten($combinedCss);
        $combinedCss = $this->cssVariableResolver->resolve($combinedCss);

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
     * @param string $html
     * @return string
     */
    private function flattenStyleBlocksInHtml(string $html): string
    {
        return (string)preg_replace_callback(
            '/(<style[^>]*>)(.*?)(<\/style>)/is',
            function (array $matches): string {
                $css = $this->layerFlattener->flatten($matches[2]);
                $css = $this->cssVariableResolver->resolve($css);

                return $matches[1] . $css . $matches[3];
            },
            $html
        );
    }
}
