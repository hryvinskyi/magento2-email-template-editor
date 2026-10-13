<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Chooses the preview frame for a template and applies it
 *
 * @api
 */
interface TemplatePreviewFramerInterface
{
    /**
     * Complete a template's content into the email an admin should see when previewing it
     *
     * Content of a template that needs no frame comes back unchanged.
     *
     * @param string $templateIdentifier Identifier the content was loaded under, theme suffix included
     * @param string $content Template content (HTML with Magento directives)
     * @param int $storeId Store view whose design configuration decides which template is its header
     *                     or footer
     * @return string
     */
    public function frame(string $templateIdentifier, string $content, int $storeId): string;
}
