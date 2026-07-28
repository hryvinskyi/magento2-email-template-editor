<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ModifierDescriptor;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\KnowledgeSerializer;
use PHPUnit\Framework\TestCase;

/**
 * The shape of every answer the knowledge endpoints give.
 *
 * The field names are asserted exhaustively rather than one at a time. The browser reads these names
 * and nothing on the server does, so a renamed or dropped field breaks nothing here and everything
 * there - silently, as a panel that stops showing one line.
 */
class KnowledgeSerializerTest extends TestCase
{
    private KnowledgeSerializer $serializer;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->serializer = new KnowledgeSerializer();
    }

    /**
     * @return void
     */
    public function testAnEntryIsDescribedFieldForField(): void
    {
        $entry = new VariableKnowledge(
            new DirectiveReference('config', 'general/store_information/name'),
            true,
            'Store name',
            'The name of the store the message is sent from.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, 'general/store_information/name', 'Read from configuration.'),
            ['Falls back to the website value when the store view has none.'],
            'escape',
            true,
            EditAffordance::inline('Store name', EditAffordanceInterface::EDITOR_TEXT, [], 'https://admin/config')
        );

        $value = new ResolvedValue(
            true,
            true,
            'Acme Ltd',
            false,
            ResolvedValueInterface::SCOPE_STORE,
            3,
            'Theitbay Store View'
        );

        self::assertSame(
            [
                'reference' => 'config:general/store_information/name',
                'known' => true,
                'title' => 'Store name',
                'summary' => 'The name of the store the message is sent from.',
                'outputKind' => 'text',
                'defaultModifier' => 'escape',
                'origin' => [
                    'kind' => 'config',
                    'locator' => 'general/store_information/name',
                    'explanation' => 'Read from configuration.',
                ],
                'caveats' => ['Falls back to the website value when the store view has none.'],
                'affordance' => [
                    'kind' => 'inline',
                    'label' => 'Store name',
                    'url' => 'https://admin/config',
                    'steps' => [],
                    'editorType' => 'text',
                    'editorOptions' => [],
                ],
                'value' => [
                    'available' => true,
                    'exact' => true,
                    'truncated' => false,
                    'scope' => 'stores',
                    'scopeId' => 3,
                    'scopeLabel' => 'Theitbay Store View',
                    'preview' => 'Acme Ltd',
                ],
            ],
            $this->serializer->serializeEntry($entry, $value)
        );
    }

    /**
     * @return void
     */
    public function testTheReportedReferenceIsTheBaseOwnSpellingOfTheKey(): void
    {
        $entry = new VariableKnowledge(
            new DirectiveReference('customVar', 'my_code'),
            true,
            'A variable',
            'Something.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, 'my_code', 'A merchant variable.'),
            [],
            null,
            false,
            EditAffordance::none('Nothing to do.')
        );

        $serialized = $this->serializer->serializeEntry($entry, new ResolvedValue());

        self::assertSame('customVar:my_code', $serialized['reference']);
    }

    /**
     * @return void
     */
    public function testAnEntryWithoutAnEditingRouteSaysSoRatherThanLeavingAGap(): void
    {
        $entry = new VariableKnowledge(
            new DirectiveReference('var', 'order.increment_id'),
            true,
            'Order number',
            'The order number.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_TEMPLATE_VAR, 'order.increment_id', 'Assigned by the sender.')
        );

        $affordance = $this->serializer->serializeEntry($entry, new ResolvedValue())['affordance'];

        self::assertSame(EditAffordanceInterface::KIND_NONE, $affordance['kind']);
        self::assertNotSame('', $affordance['label']);
        self::assertSame([], $affordance['steps']);
        self::assertNull($affordance['editorType']);
    }

    /**
     * @return void
     */
    public function testAKeyThatCouldNotBeReadIsAnsweredInTheSameShapeAndEchoedExactly(): void
    {
        $serialized = $this->serializer->serializeUnreadableReference('banana:{{x}}');

        self::assertSame(
            array_keys($this->serializer->serializeEntry(
                new VariableKnowledge(
                    new DirectiveReference('var', 'x'),
                    true,
                    'x',
                    'x',
                    VariableKnowledgeInterface::OUTPUT_TEXT,
                    new Origin(OriginInterface::KIND_COMPUTED, '', 'x'),
                    [],
                    null,
                    false,
                    EditAffordance::none('Nothing to do.')
                ),
                new ResolvedValue()
            )),
            array_keys($serialized)
        );

        self::assertSame('banana:{{x}}', $serialized['reference']);
        self::assertFalse($serialized['known']);
        self::assertSame(EditAffordanceInterface::KIND_INSTRUCTION, $serialized['affordance']['kind']);
        self::assertNotSame([], $serialized['affordance']['steps']);
        self::assertFalse($serialized['value']['available']);
        self::assertSame('', $serialized['value']['scope']);
    }

    /**
     * @return void
     */
    public function testTheModifierVocabularyKeepsItsOrderAndItsImplementedFlag(): void
    {
        self::assertSame(
            [
                [
                    'name' => 'escape',
                    'label' => 'Escape',
                    'description' => 'Escapes the value.',
                    'implemented' => true,
                    'arguments' => [
                        ['name' => 'type', 'options' => ['html', 'htmlentities', 'url'], 'default' => 'html'],
                    ],
                ],
                [
                    'name' => 'raw',
                    'label' => 'Raw',
                    'description' => 'Disables escaping by not being a modifier the filter holds.',
                    'implemented' => false,
                    'arguments' => [],
                ],
            ],
            $this->serializer->serializeModifiers([
                new ModifierDescriptor(
                    'escape',
                    'Escape',
                    'Escapes the value.',
                    true,
                    [['name' => 'type', 'options' => ['html', 'htmlentities', 'url'], 'default' => 'html']]
                ),
                new ModifierDescriptor(
                    'raw',
                    'Raw',
                    'Disables escaping by not being a modifier the filter holds.',
                    false
                ),
            ])
        );
    }

    /**
     * @return void
     */
    public function testAValueThatCameFromNoScopeReportsNoScopeRatherThanTheDefaultOne(): void
    {
        self::assertSame(
            [
                'available' => true,
                'exact' => false,
                'truncated' => true,
                'scope' => '',
                'scopeId' => 0,
                'scopeLabel' => '',
                'preview' => 'ORD-100000',
            ],
            $this->serializer->serializeValue(new ResolvedValue(true, false, 'ORD-100000', true))
        );
    }
}
