<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use PHPUnit\Framework\TestCase;

class DirectiveReferenceTest extends TestCase
{
    public function testTheCanonicalStringIsTheKindAndTheExpressionJoinedByAColon(): void
    {
        $reference = new DirectiveReference('var', 'store.getFormattedAddress()');

        self::assertSame('var', $reference->getKind());
        self::assertSame('store.getFormattedAddress()', $reference->getExpression());
        self::assertSame('var:store.getFormattedAddress()', $reference->toCanonicalString());
    }

    public function testAnExpressionIsNotOverlongUnlessItWasTruncated(): void
    {
        self::assertFalse((new DirectiveReference('customVar', 'my_code'))->isOverlong());
        self::assertTrue((new DirectiveReference('trans', 'a message', true))->isOverlong());
    }

    public function testTwoReferencesWithTheSameKindAndExpressionAreEqual(): void
    {
        $one = new DirectiveReference('config', 'general/store_information/name');
        $other = new DirectiveReference('config', 'general/store_information/name');

        self::assertTrue($one->equals($other));
        self::assertTrue($other->equals($one));
    }

    public function testTheSameExpressionUnderTwoKindsIsTwoDifferentReferences(): void
    {
        $config = new DirectiveReference('config', 'my_code');
        $customVariable = new DirectiveReference('customVar', 'my_code');

        self::assertFalse($config->equals($customVariable));
        self::assertFalse($customVariable->equals($config));
    }

    /**
     * The flag records what the parser did to the input, not what the reference points at; a
     * canonical string is always within the length limit, so a re-parsed reference reports false and
     * still has to compare equal to the one it came from.
     *
     * @return void
     */
    public function testEqualityIgnoresTheOverlongFlag(): void
    {
        $truncated = new DirectiveReference('trans', 'a message', true);
        $reparsed = new DirectiveReference('trans', 'a message');

        self::assertTrue($truncated->equals($reparsed));
        self::assertTrue($reparsed->equals($truncated));
    }

    public function testAnEmptyExpressionStillProducesASeparator(): void
    {
        self::assertSame('depend:', (new DirectiveReference('depend', ''))->toCanonicalString());
    }
}
