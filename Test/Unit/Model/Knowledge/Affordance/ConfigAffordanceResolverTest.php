<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Affordance;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Affordance\ConfigAffordanceResolver;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathReadability;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathWritability;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Magento\Backend\Model\UrlInterface;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Config\Model\Config\Structure\ElementInterface;
use Magento\Variable\Model\Source\Variables as ConfigVariables;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigAffordanceResolverTest extends TestCase
{
    /**
     * The paths a {{config}} directive may read, as the email filter's own source lists them
     */
    private const AVAILABLE_VARS = [
        'general/store_information/name',
        'general/store_information/city',
        'general/store_information/country_id',
    ];

    /**
     * What the URL model is stubbed to answer with, so the parameters it was given can be read back
     */
    private const URL_PREFIX = 'https://shop.test/admin/system_config/edit/';

    private Structure&MockObject $configStructure;

    private ConfigVariables&MockObject $configVariables;

    private ConfigAffordanceResolver $resolver;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->configStructure = $this->createMock(Structure::class);
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('getAvailableVars')->willReturn(self::AVAILABLE_VARS);

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static function (string $route, array $params): string {
                $fragment = $params['_fragment'] ?? '';
                unset($params['_fragment']);
                $query = [];

                foreach ($params as $name => $value) {
                    $query[] = $name . '/' . $value;
                }

                return self::URL_PREFIX . implode('/', $query) . ($fragment === '' ? '' : '#' . $fragment);
            }
        );

        $this->resolver = new ConfigAffordanceResolver(
            $this->configStructure,
            $url,
            new ConfigPathWritability(
                $this->configStructure,
                new ConfigPathReadability($this->configVariables)
            )
        );
    }

    /**
     * @return void
     */
    public function testItClaimsAConfigurationOriginTheStructureDeclares(): void
    {
        $this->structureAnswers([
            'general/store_information/name' => $this->field(['label' => 'Store Name', 'store' => true]),
        ]);

        self::assertTrue(
            $this->resolver->supports(new Origin(OriginInterface::KIND_CONFIG, 'general/store_information/name'))
        );
    }

    /**
     * Everything this resolver offers is built out of what the structure declares, and a link to a
     * section that does not exist redirects to the dashboard. Standing aside leaves the reference
     * to the resolver the pool ends in.
     *
     * @dataProvider unclaimedOriginProvider
     *
     * @param string $kind Origin kind to offer the resolver
     * @param string $locator Origin locator to offer the resolver
     * @return void
     */
    public function testItStandsAsideForAnythingItCannotBuildALinkFor(string $kind, string $locator): void
    {
        $this->structureAnswers([]);

        self::assertFalse($this->resolver->supports(new Origin($kind, $locator)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function unclaimedOriginProvider(): array
    {
        return [
            'another origin kind' => [OriginInterface::KIND_CUSTOM_VARIABLE, 'my_code'],
            'a path the structure does not declare' => [OriginInterface::KIND_CONFIG, 'made/up/path'],
            'no locator at all' => [OriginInterface::KIND_CONFIG, ''],
            'a path with no group' => [OriginInterface::KIND_CONFIG, 'general'],
        ];
    }

    /**
     * @return void
     */
    public function testTheLinkCarriesTheSectionTheStoreScopeAndTheGroupAnchor(): void
    {
        $this->declareStoreInformation(['label' => 'City', 'store' => false]);

        $affordance = $this->resolver->resolve($this->entryFor('general/store_information/city', false), 3);

        self::assertSame(EditAffordanceInterface::KIND_LINK, $affordance->getKind());
        self::assertSame('Open Store Information in Configuration', $affordance->getLabel());
        self::assertSame(
            self::URL_PREFIX . 'section/general/store/3#general_store_information-link',
            $affordance->getUrl()
        );
    }

    /**
     * The default scope is the configuration page's own default, so naming it would add a parameter
     * that changes nothing.
     *
     * @return void
     */
    public function testTheLinkCarriesNoScopeParameterAtTheDefaultScope(): void
    {
        $this->declareStoreInformation(['label' => 'City', 'store' => false]);

        $affordance = $this->resolver->resolve($this->entryFor('general/store_information/city', false), 0);

        self::assertSame(
            self::URL_PREFIX . 'section/general#general_store_information-link',
            $affordance->getUrl()
        );
    }

    /**
     * The editor is the fast path and the link is the way to the full form, so a writable path gets
     * both on one affordance.
     *
     * @return void
     */
    public function testAWritablePathIsOfferedAnInlineEditorThatStillCarriesTheLink(): void
    {
        $this->declareStoreInformation(['label' => 'Store Name', 'store' => true], 'name');

        $affordance = $this->resolver->resolve($this->entryFor('general/store_information/name', true), 3);

        self::assertSame(EditAffordanceInterface::KIND_INLINE, $affordance->getKind());
        self::assertSame('Store Name', $affordance->getLabel());
        self::assertSame(EditAffordanceInterface::EDITOR_TEXT, $affordance->getEditorType());
        self::assertSame([], $affordance->getEditorOptions());
        self::assertSame(
            self::URL_PREFIX . 'section/general/store/3#general_store_information-link',
            $affordance->getUrl()
        );
    }

    /**
     * An entry may have been written by hand and be wrong about its own value; offering an editor
     * the write path would refuse is a control that does nothing.
     *
     * @return void
     */
    public function testAnEntryClaimingWritabilityTheSharedDecisionRefusesGetsOnlyTheLink(): void
    {
        $this->declareStoreInformation(['label' => 'Country', 'store' => true], 'country_id');

        $affordance = $this->resolver->resolve($this->entryFor('general/store_information/country_id', true), 3);

        self::assertSame(EditAffordanceInterface::KIND_LINK, $affordance->getKind());
    }

    /**
     * @return void
     */
    public function testAnEntryThatDoesNotOfferItsValueForWritingGetsOnlyTheLink(): void
    {
        $this->declareStoreInformation(['label' => 'Store Name', 'store' => true], 'name');

        $affordance = $this->resolver->resolve($this->entryFor('general/store_information/name', false), 3);

        self::assertSame(EditAffordanceInterface::KIND_LINK, $affordance->getKind());
    }

    /**
     * @return void
     */
    public function testAWritableChoiceFieldIsOfferedItsOwnOptions(): void
    {
        $this->declareStoreInformation(
            [
                'label' => 'Store Name',
                'store' => true,
                'type' => 'select',
                'options' => [
                    ['value' => 'a', 'label' => 'Alpha'],
                    ['value' => 'b', 'label' => 'Beta'],
                    ['value' => ['nested'], 'label' => 'Group'],
                    ['label' => 'No value'],
                ],
            ],
            'name'
        );

        $affordance = $this->resolver->resolve($this->entryFor('general/store_information/name', true), 3);

        self::assertSame(EditAffordanceInterface::KIND_INLINE, $affordance->getKind());
        self::assertSame(EditAffordanceInterface::EDITOR_SELECT, $affordance->getEditorType());
        self::assertSame(
            [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']],
            $affordance->getEditorOptions()
        );
    }

    /**
     * A choice with nothing to choose from is not an editor.
     *
     * @return void
     */
    public function testAWritableChoiceFieldWithNoOptionsFallsBackToTheLink(): void
    {
        $this->declareStoreInformation(
            ['label' => 'Store Name', 'store' => true, 'type' => 'select', 'options' => []],
            'name'
        );

        $affordance = $this->resolver->resolve($this->entryFor('general/store_information/name', true), 3);

        self::assertSame(EditAffordanceInterface::KIND_LINK, $affordance->getKind());
    }

    /**
     * @return void
     */
    public function testAWritableMultiLineFieldAsksForAMultiLineInput(): void
    {
        $this->declareStoreInformation(
            ['label' => 'Store Name', 'store' => true, 'type' => 'textarea'],
            'name'
        );

        $affordance = $this->resolver->resolve($this->entryFor('general/store_information/name', true), 3);

        self::assertSame(EditAffordanceInterface::EDITOR_TEXTAREA, $affordance->getEditorType());
    }

    /**
     * Declare the store information group and one of its fields, leaving everything else undeclared.
     *
     * @param array<string, mixed> $spec What the field declares
     * @param string $fieldId Identifier of the field within the group
     * @return void
     */
    private function declareStoreInformation(array $spec, string $fieldId = 'city'): void
    {
        $group = $this->createMock(Group::class);
        $group->method('getLabel')->willReturn('Store Information');

        $this->structureAnswers([
            'general/store_information' => $group,
            'general/store_information/' . $fieldId => $this->field($spec),
        ]);
    }

    /**
     * An entry with a configuration origin at the given path
     *
     * @param string $path Configuration path the entry's origin names
     * @param bool $writable Whether the entry offers its value for writing
     * @return VariableKnowledgeInterface
     */
    private function entryFor(string $path, bool $writable): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('config', $path),
            true,
            'Title',
            'Summary',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, $path),
            [],
            null,
            $writable
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
     * A configuration field mock
     *
     * @param array{label?: string, default?: bool, website?: bool, store?: bool, type?: string,
     *              options?: array<int, mixed>} $spec What the field declares; anything absent is
     *              declared as absent
     * @return Field&MockObject
     */
    private function field(array $spec): Field
    {
        $field = $this->createMock(Field::class);
        $field->method('getLabel')->willReturn($spec['label'] ?? '');
        $field->method('getComment')->willReturn('');
        $field->method('getType')->willReturn($spec['type'] ?? 'text');
        $field->method('getOptions')->willReturn($spec['options'] ?? []);
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
