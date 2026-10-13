<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Preview;

use Hryvinskyi\EmailTemplateEditor\Model\Preview\PreviewPlaceholder;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

class PreviewPlaceholderTest extends TestCase
{
    /**
     * The label is shown as text, never as markup
     *
     * @return void
     */
    public function testLabelIsEscaped(): void
    {
        $html = (new PreviewPlaceholder(new Escaper()))->render('<b>sales_order_items</b> & more');

        $this->assertStringContainsString('&lt;b&gt;sales_order_items&lt;/b&gt; &amp; more', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }
}
