<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use PHPUnit\Framework\TestCase;

class VariableKnowledgeTest extends TestCase
{
    public function testItCarriesEverythingItWasGiven(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');
        $origin = new Origin(OriginInterface::KIND_CONFIG, 'general/store_information/name', 'Read from config.');

        $entry = new VariableKnowledge(
            $reference,
            true,
            'Store name',
            'The name the store trades under.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            $origin,
            ['Empty until the field is filled in.'],
            'escape',
            true
        );

        self::assertSame($reference, $entry->getReference());
        self::assertTrue($entry->isKnown());
        self::assertSame('Store name', $entry->getTitle());
        self::assertSame('The name the store trades under.', $entry->getSummary());
        self::assertSame(VariableKnowledgeInterface::OUTPUT_TEXT, $entry->getOutputKind());
        self::assertSame($origin, $entry->getOrigin());
        self::assertSame(['Empty until the field is filled in.'], $entry->getCaveats());
        self::assertSame('escape', $entry->getDefaultModifier());
        self::assertTrue($entry->isValueWritable());
    }

    /**
     * A provider builds an entry without one, because choosing an affordance is a decision taken once
     * for every entry rather than repeated in each provider.
     *
     * @return void
     */
    public function testAnEntryStartsWithoutAnAffordance(): void
    {
        self::assertNull($this->entry()->getAffordance());
    }

    public function testTakingOnAnAffordanceLeavesTheOriginalUntouched(): void
    {
        $entry = $this->entry();
        $affordance = EditAffordance::none('Nothing to do here.');

        $withAffordance = $entry->withAffordance($affordance);

        self::assertNull($entry->getAffordance());
        self::assertSame($affordance, $withAffordance->getAffordance());
        self::assertNotSame($entry, $withAffordance);
    }

    public function testTakingOnAnAffordanceKeepsEveryOtherField(): void
    {
        $entry = $this->entry();

        $withAffordance = $entry->withAffordance(
            EditAffordance::link('Open Store Information', 'https://example.test/admin')
        );

        self::assertSame($entry->getReference(), $withAffordance->getReference());
        self::assertSame($entry->isKnown(), $withAffordance->isKnown());
        self::assertSame($entry->getTitle(), $withAffordance->getTitle());
        self::assertSame($entry->getSummary(), $withAffordance->getSummary());
        self::assertSame($entry->getOutputKind(), $withAffordance->getOutputKind());
        self::assertSame($entry->getOrigin(), $withAffordance->getOrigin());
        self::assertSame($entry->getCaveats(), $withAffordance->getCaveats());
        self::assertSame($entry->getDefaultModifier(), $withAffordance->getDefaultModifier());
        self::assertSame($entry->isValueWritable(), $withAffordance->isValueWritable());
        self::assertSame(EditAffordanceInterface::KIND_LINK, $withAffordance->getAffordance()?->getKind());
    }

    public function testAnEntryHasNoCaveatsNoDefaultModifierAndNoWriteUnlessItSaysSo(): void
    {
        $entry = $this->entry();

        self::assertSame([], $entry->getCaveats());
        self::assertNull($entry->getDefaultModifier());
        self::assertFalse($entry->isValueWritable());
    }

    /**
     * @return VariableKnowledge
     */
    private function entry(): VariableKnowledge
    {
        return new VariableKnowledge(
            new DirectiveReference('var', 'order.increment_id'),
            true,
            'Order number',
            'The increment id of the order the message is about.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_TEMPLATE_VAR, 'order', 'Assigned by the sending code.')
        );
    }
}
