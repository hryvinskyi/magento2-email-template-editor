<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Marks a spot in a previewed email that real content would fill
 *
 * @api
 */
interface PreviewPlaceholderInterface
{
    /**
     * Render a visibly-not-real block carrying a label
     *
     * @param string $label Already translated text saying what belongs there
     * @return string HTML
     */
    public function render(string $label): string;
}
