<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathKnowledgeProvider;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathReadability;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathWritability;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\Config\Structure\ElementInterface;
use Magento\Variable\Model\Source\Variables as ConfigVariables;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The provider is paired with the real writability decision here rather than with a stand-in, so
 * that what an entry promises about editing and what the shared decision would allow are checked
 * together instead of separately.
 */
class ConfigPathKnowledgeProviderTest extends TestCase
{
    /**
     * The paths a {{config}} directive may read, as the email filter's own source lists them
     */
    private const AVAILABLE_VARS = [
        'general/store_information/name',
        'general/store_information/city',
        'general/store_information/country_id',
    ];

    private Structure&MockObject $configStructure;

    private ConfigVariables&MockObject $configVariables;

    private DirectiveReferenceParser $parser;

    private ConfigPathKnowledgeProvider $provider;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->configStructure = $this->createMock(Structure::class);
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('getAvailableVars')->willReturn(self::AVAILABLE_VARS);
        $this->parser = new DirectiveReferenceParser();

        $this->provider = new ConfigPathKnowledgeProvider(
            $this->configStructure,
            $this->configVariables,
            new ConfigPathWritability(
                $this->configStructure,
                new ConfigPathReadability($this->configVariables)
            ),
            $this->parser
        );
    }

    /**
     * @return void
     */
    public function testAReadableDeclaredFieldIsDescribedFromTheConfigurationStructure(): void
    {
        $this->structureAnswers([
            'general' => $this->labelled(Section::class, 'General'),
            'general/store_information' => $this->labelled(Group::class, 'Store Information'),
            'general/store_information/name' => $this->field([
                'label' => 'Store Name',
                'comment' => 'The name customers see on the store.',
                'store' => true,
            ]),
        ]);

        $entry = $this->provider->describe(
            $this->parser->create('config', 'general/store_information/name'),
            3
        );

        self::assertNotNull($entry);
        self::assertTrue($entry->isKnown());
        self::assertSame('Store Name', $entry->getTitle());
        self::assertSame('The name customers see on the store.', $entry->getSummary());
        self::assertSame(VariableKnowledgeInterface::OUTPUT_TEXT, $entry->getOutputKind());
        self::assertSame(OriginInterface::KIND_CONFIG, $entry->getOrigin()->getKind());
        self::assertSame('general/store_information/name', $entry->getOrigin()->getLocator());
        self::assertStringContainsString(
            'General > Store Information > Store Name',
            $entry->getOrigin()->getExplanation()
        );
        self::assertTrue($entry->isValueWritable());
        self::assertSame([], $entry->getCaveats());
        self::assertNull($entry->getDefaultModifier());
        self::assertNull($entry->getAffordance());
    }

    /**
     * A field with no comment still gets a summary, built from the label it is known by.
     *
     * @return void
     */
    public function testAFieldWithoutACommentIsSummarisedFromItsLabel(): void
    {
        $this->structureAnswers([
            'general/store_information/name' => $this->field(['label' => 'Store Name', 'store' => true]),
        ]);

        $entry = $this->provider->describe(
            $this->parser->create('config', 'general/store_information/name'),
            0
        );

        self::assertNotNull($entry);
        self::assertStringContainsString('Store Name', $entry->getSummary());
    }

    /**
     * The honest refusal, and the whole reason this provider exists.
     *
     * @return void
     */
    public function testAPathTheFilterCannotReadCarriesTheEmptyStringCaveatAndIsNotWritable(): void
    {
        $this->structureAnswers([
            'general/locale/timezone' => $this->field(['label' => 'Timezone', 'store' => true]),
        ]);

        $entry = $this->provider->describe($this->parser->create('config', 'general/locale/timezone'), 3);

        self::assertNotNull($entry);
        self::assertFalse($entry->isValueWritable());
        self::assertCount(1, $entry->getCaveats());
        self::assertStringContainsString('renders as an empty string', $entry->getCaveats()[0]);
        self::assertStringContainsString('general/locale/timezone', $entry->getCaveats()[0]);
    }

    /**
     * @return void
     */
    public function testAPathTheStructureDoesNotDeclareDegradesToThePathAsTitle(): void
    {
        $this->structureAnswers([]);

        $entry = $this->provider->describe($this->parser->create('config', 'made/up/path'), 0);

        self::assertNotNull($entry);
        self::assertSame('made/up/path', $entry->getTitle());
        self::assertStringContainsString('declares no field', $entry->getSummary());
        self::assertStringContainsString('made/up/path', $entry->getOrigin()->getExplanation());
        self::assertFalse($entry->isValueWritable());
    }

    /**
     * The scope reaches the entry, so the same path can be editable for one scope and not another.
     *
     * @return void
     */
    public function testAFieldHiddenAtStoreScopeIsWritableOnlyAtTheDefaultScope(): void
    {
        $this->structureAnswers([
            'general/store_information/city' => $this->field([
                'label' => 'City',
                'store' => false,
            ]),
        ]);

        $reference = $this->parser->create('config', 'general/store_information/city');

        $atStore = $this->provider->describe($reference, 3);
        $atDefault = $this->provider->describe($reference, 0);

        self::assertNotNull($atStore);
        self::assertNotNull($atDefault);
        self::assertFalse($atStore->isValueWritable());
        self::assertStringContainsString('single store view', $atStore->getCaveats()[0]);
        self::assertTrue($atDefault->isValueWritable());
        self::assertSame([], $atDefault->getCaveats());
    }

    /**
     * @dataProvider foreignReferenceProvider
     *
     * @param string $kind Directive kind to offer the provider
     * @param string $expression Expression to offer the provider
     * @return void
     */
    public function testAReferenceOfAnotherKindIsLeftToTheNextProvider(string $kind, string $expression): void
    {
        self::assertNull($this->provider->describe($this->parser->create($kind, $expression), 0));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function foreignReferenceProvider(): array
    {
        return [
            'variable' => ['var', 'store.getFormattedAddress()'],
            'custom variable' => ['customVar', 'my_code'],
            'translation' => ['trans', 'Thank you for your order'],
            'a config directive naming no path' => ['config', ''],
        ];
    }

    /**
     * Only the paths the filter can read are catalogued; the rest of the configuration structure
     * would be thousands of entries that render as nothing.
     *
     * @return void
     */
    public function testEveryReadablePathIsListedAndNothingElseIs(): void
    {
        $this->structureAnswers([
            'general/store_information/name' => $this->field(['label' => 'Store Name', 'store' => true]),
        ]);

        $references = array_map(
            static fn (VariableKnowledgeInterface $entry): string => $entry->getReference()->toCanonicalString(),
            $this->provider->listAll(3)
        );

        self::assertSame(
            [
                'config:general/store_information/name',
                'config:general/store_information/city',
                'config:general/store_information/country_id',
            ],
            $references
        );
    }

    /**
     * Teach the structure mock what it holds, answering anything else with the placeholder element
     * the real structure would return.
     *
     * @param array<string, ElementInterface&MockObject> $elements Configuration path to its element
     * @return void
     */
    private function structureAnswers(array $elements): void
    {
        $this->configStructure
            ->method('getElementByConfigPath')
            ->willReturnCallback(fn (string $path): ElementInterface => $elements[$path] ?? $this->field([]));
    }

    /**
     * A section or group mock carrying nothing but a label
     *
     * @param class-string<ElementInterface> $type Element class to mock
     * @param string $label Label the element carries
     * @return ElementInterface&MockObject
     */
    private function labelled(string $type, string $label): ElementInterface
    {
        $element = $this->createMock($type);
        $element->method('getLabel')->willReturn($label);

        return $element;
    }

    /**
     * A configuration field mock
     *
     * @param array{label?: string, comment?: string, default?: bool, website?: bool, store?: bool,
     *              type?: string} $spec What the field declares; anything absent is declared as absent
     * @return Field&MockObject
     */
    private function field(array $spec): Field
    {
        $field = $this->createMock(Field::class);
        $field->method('getLabel')->willReturn($spec['label'] ?? '');
        $field->method('getComment')->willReturn($spec['comment'] ?? '');
        $field->method('getType')->willReturn($spec['type'] ?? 'text');
        $field->method('hasBackendModel')->willReturn(false);

        // An empty specification is the placeholder the structure hands back for a path it has
        // never heard of: no label and none of the scope flags.
        $declared = $spec !== [];
        $field->method('showInDefault')->willReturn($spec['default'] ?? $declared);
        $field->method('showInWebsite')->willReturn($spec['website'] ?? $declared);
        $field->method('showInStore')->willReturn($spec['store'] ?? false);

        return $field;
    }
}
