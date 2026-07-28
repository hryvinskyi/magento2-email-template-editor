<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DescribeContext;
use PHPUnit\Framework\TestCase;

class DescribeContextTest extends TestCase
{
    private DescribeContext $context;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->context = new DescribeContext();
    }

    public function testNothingIsBeingDescribedToBeginWith(): void
    {
        self::assertSame('', $this->context->getTemplateId());
        self::assertSame(0, $this->context->getStoreId());
    }

    public function testWhatIsBeingDescribedIsReadBackAsItWasSet(): void
    {
        $this->context->set('sales_email_order_template', 3);

        self::assertSame('sales_email_order_template', $this->context->getTemplateId());
        self::assertSame(3, $this->context->getStoreId());
    }

    /**
     * A template identifier surviving one description into the next would not fail; it would answer
     * confidently about the wrong template. Clearing has to put the context back exactly as it
     * started, so that "nothing is being described" is one state rather than two.
     *
     * @return void
     */
    public function testClearingPutsTheContextBackAsItStarted(): void
    {
        $this->context->set('sales_email_order_template', 3);
        $this->context->clear();

        self::assertSame('', $this->context->getTemplateId());
        self::assertSame(0, $this->context->getStoreId());
    }

    public function testTheContextIsNeverHalfFilledFromTwoDescriptions(): void
    {
        $this->context->set('sales_email_order_template', 3);
        $this->context->set('customer_create_account_email_template', 5);

        self::assertSame('customer_create_account_email_template', $this->context->getTemplateId());
        self::assertSame(5, $this->context->getStoreId());
    }
}
