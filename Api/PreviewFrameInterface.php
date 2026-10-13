<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Completes one kind of partial template into a whole email for preview
 *
 * A header opens the document the footer closes, so neither renders as anything an admin
 * recognises on its own. A frame puts back what the template expects around it.
 *
 * @api
 */
interface PreviewFrameInterface
{
    /**
     * Surround the template content with what it needs to read as a whole email
     *
     * @param string $content Template content (HTML with Magento directives)
     * @return string Content that renders as a complete email
     */
    public function wrap(string $content): string;
}
