<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Preview;

use Hryvinskyi\EmailTemplateEditor\Api\PreviewPlaceholderInterface;
use Magento\Framework\Escaper;

class PreviewPlaceholder implements PreviewPlaceholderInterface
{
    /**
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritDoc
     *
     * Inline styles only: the block has to look the same whatever theme and CSS the email it
     * stands in carries, and an email client sees nothing else anyway.
     */
    public function render(string $label): string
    {
        return '<table style="width:100%;border:1px dashed #e3e3e3;border-radius:4px;margin:12px 0;">'
            . '<tr><td style="padding:20px;text-align:center;color:#999;font-size:13px;font-style:italic;">'
            // Without single-quote escaping this is plain htmlspecialchars, the same as escapeHtml()
            // does for a string, but declared to return one.
            . $this->escaper->escapeHtmlAttr($label, false)
            . '</td></tr></table>';
    }
}
