<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Config;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Config\SchemaLocator;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;
use PHPUnit\Framework\TestCase;

class SchemaLocatorTest extends TestCase
{
    public function testTheSchemaIsFoundInThisModulesEtcDirectory(): void
    {
        $moduleReader = $this->createMock(Reader::class);
        $moduleReader->expects(self::once())
            ->method('getModuleDir')
            ->with(Dir::MODULE_ETC_DIR, 'Hryvinskyi_EmailTemplateEditor')
            ->willReturn('/modules/Hryvinskyi/EmailTemplateEditor/etc');

        $locator = new SchemaLocator($moduleReader);

        self::assertSame(
            '/modules/Hryvinskyi/EmailTemplateEditor/etc/email_variables.xsd',
            $locator->getSchema()
        );
    }

    /**
     * The same rules apply to one file and to the merged result. That is what tells a contribution
     * that is wrong on its own apart from one that collides with somebody else's entry.
     *
     * @return void
     */
    public function testOneFileIsCheckedAgainstTheSameSchemaAsTheMergedResult(): void
    {
        $moduleReader = $this->createMock(Reader::class);
        $moduleReader->method('getModuleDir')->willReturn('/modules/Hryvinskyi/EmailTemplateEditor/etc');

        $locator = new SchemaLocator($moduleReader);

        self::assertSame($locator->getSchema(), $locator->getPerFileSchema());
    }
}
