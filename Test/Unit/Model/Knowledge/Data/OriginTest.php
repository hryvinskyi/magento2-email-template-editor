<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use PHPUnit\Framework\TestCase;

class OriginTest extends TestCase
{
    public function testItCarriesTheKindLocatorAndExplanationItWasGiven(): void
    {
        $origin = new Origin(
            OriginInterface::KIND_CONFIG,
            'general/store_information/name',
            'Read from the store configuration when the message renders.'
        );

        self::assertSame(OriginInterface::KIND_CONFIG, $origin->getKind());
        self::assertSame('general/store_information/name', $origin->getLocator());
        self::assertSame('Read from the store configuration when the message renders.', $origin->getExplanation());
    }

    /**
     * A kind that names no stored field has nothing to point at, and an empty locator is the honest
     * way to say so.
     *
     * @return void
     */
    public function testAnOriginNeedNotPointAtAnything(): void
    {
        $origin = new Origin(OriginInterface::KIND_COMPUTED);

        self::assertSame(OriginInterface::KIND_COMPUTED, $origin->getKind());
        self::assertSame('', $origin->getLocator());
        self::assertSame('', $origin->getExplanation());
    }

    /**
     * Nothing here matches a kind against a list: the strategies that claim origins decide what they
     * understand, so a kind another module invents has to survive being carried around.
     *
     * @return void
     */
    public function testAKindTheModuleDoesNotPublishIsCarriedUnchanged(): void
    {
        self::assertSame('third_party_thing', (new Origin('third_party_thing'))->getKind());
    }
}
