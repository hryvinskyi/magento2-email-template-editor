<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use PHPUnit\Framework\TestCase;

class ResolvedValueTest extends TestCase
{
    public function testAnExactValueReportsTheScopeItWasReadFrom(): void
    {
        $value = new ResolvedValue(
            true,
            true,
            'Acme Ltd',
            false,
            ResolvedValueInterface::SCOPE_STORE,
            3,
            'Theitbay Store View'
        );

        self::assertTrue($value->isAvailable());
        self::assertTrue($value->isExact());
        self::assertSame('Acme Ltd', $value->getPreview());
        self::assertFalse($value->isTruncated());
        self::assertSame(ResolvedValueInterface::SCOPE_STORE, $value->getScope());
        self::assertSame(3, $value->getScopeId());
        self::assertSame('Theitbay Store View', $value->getScopeLabel());
    }

    /**
     * A sample value was never read from a scope, and an empty scope is what says so. Reporting the
     * default scope instead would be indistinguishable from a real value that falls back to it.
     *
     * @return void
     */
    public function testASampleValueIsAvailableButNotExactAndNamesNoScope(): void
    {
        $value = new ResolvedValue(true, false, 'John Doe', false, '', 0, 'Theitbay Store View');

        self::assertTrue($value->isAvailable());
        self::assertFalse($value->isExact());
        self::assertSame('', $value->getScope());
        self::assertSame(0, $value->getScopeId());
    }

    /**
     * An empty value that really is what renders is available; unavailable means no value could be
     * produced at all, and the two must not collapse into one another.
     *
     * @return void
     */
    public function testAnUnavailableValueIsNotTheSameAsAnEmptyOne(): void
    {
        $empty = new ResolvedValue(true, true, '', false, ResolvedValueInterface::SCOPE_DEFAULT, 0, 'Default Config');
        $missing = new ResolvedValue();

        self::assertTrue($empty->isAvailable());
        self::assertFalse($missing->isAvailable());
        self::assertSame('', $empty->getPreview());
        self::assertSame('', $missing->getPreview());
    }

    public function testATruncatedPreviewSaysSo(): void
    {
        $value = new ResolvedValue(true, true, 'A long...', true, ResolvedValueInterface::SCOPE_DEFAULT, 0, 'Default Config');

        self::assertTrue($value->isTruncated());
    }
}
