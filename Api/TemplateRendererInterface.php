<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

interface TemplateRendererInterface
{
    /**
     * Render an email template with variables and optional CSS
     *
     * @param string $content Template content (HTML with Magento directives)
     * @param array<string, mixed> $variables Template variables for rendering
     * @param int $storeId Store ID for store emulation
     * @param string|null $customCss User-written custom CSS
     * @param string|null $tailwindCss Auto-generated Tailwind CSS
     * @param string|null $templateIdentifier Template identifier for context-aware rendering
     * @param bool $isMockData When true, layout directives are replaced with placeholders
     * @param string|null $themeCssOverride Theme CSS to use as token source (e.g. the editor's
     *                                       current draft); when null, the store's default theme is loaded.
     * @return string Rendered HTML with inlined CSS
     */
    public function render(
        string $content,
        array $variables,
        int $storeId,
        ?string $customCss = null,
        ?string $tailwindCss = null,
        ?string $templateIdentifier = null,
        bool $isMockData = false,
        ?string $themeCssOverride = null
    ): string;

    /**
     * Render a plain text string (e.g. subject line) by processing Magento directives
     *
     * @param string $text Text containing Magento directives
     * @param array<string, mixed> $variables Template variables for rendering
     * @param int $storeId Store ID for store emulation
     * @param string|null $templateIdentifier Template identifier for area resolution
     * @return string Processed text with directives resolved
     */
    public function renderPlain(
        string $text,
        array $variables,
        int $storeId,
        ?string $templateIdentifier = null
    ): string;
}
