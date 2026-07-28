<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Value;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathReadabilityInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Value\ConfigValueStrategy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Store\Model\Information;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigValueStrategyTest extends TestCase
{
    private const STORE_ID = 3;
    private const TEMPLATE_ID = 'sales_email_order_template';
    private const COUNTRY_PATH = 'general/store_information/country_id';
    private const REGION_PATH = 'general/store_information/region_id';
    private const NAME_PATH = 'general/store_information/name';

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Information&MockObject
     */
    private Information $storeInformation;

    /**
     * @var Store&MockObject
     */
    private Store $store;

    /**
     * @var ConfigPathReadabilityInterface&MockObject
     */
    private ConfigPathReadabilityInterface $readability;

    /**
     * The paths a {{config}} directive renders, as far as these tests are concerned
     *
     * @var string[]
     */
    private array $readablePaths = [self::NAME_PATH, self::COUNTRY_PATH, self::REGION_PATH];

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeInformation = $this->createMock(Information::class);
        $this->readability = $this->createMock(ConfigPathReadabilityInterface::class);
        $this->readability->method('isReadable')
            ->willReturnCallback(fn (string $path): bool => in_array($path, $this->readablePaths, true));

        $this->store = $this->createMock(Store::class);
        $this->store->method('getId')->willReturn(self::STORE_ID);
        $this->store->method('getName')->willReturn('Theitbay Store View');

        $this->storeManager->method('getStore')->willReturn($this->store);
    }

    public function testItClaimsConfigurationOriginsOnly(): void
    {
        $strategy = $this->strategy();

        self::assertTrue($strategy->supports(new Origin(OriginInterface::KIND_CONFIG, self::NAME_PATH, '')));
        self::assertFalse($strategy->supports(new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, 'my_code', '')));
        self::assertFalse($strategy->supports(new Origin(OriginInterface::KIND_TEMPLATE_VAR, 'order', '')));
    }

    public function testAPlainPathIsReadInTheStoreScopeAndReportedExactly(): void
    {
        $this->scopeConfig->expects(self::once())
            ->method('getValue')
            ->with(self::NAME_PATH, ScopeInterface::SCOPE_STORE, self::STORE_ID)
            ->willReturn('Acme Ltd');

        $value = $this->strategy()->resolve($this->entry(self::NAME_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue($value->isAvailable());
        self::assertTrue($value->isExact());
        self::assertSame('Acme Ltd', $value->getPreview());
        self::assertSame(ResolvedValueInterface::SCOPE_STORE, $value->getScope());
        self::assertSame(self::STORE_ID, $value->getScopeId());
        self::assertSame('Theitbay Store View', $value->getScopeLabel());
    }

    /**
     * A path that is set nowhere renders as nothing, which is an answer rather than the absence of
     * one - so it is available and empty, not unavailable.
     *
     * @return void
     */
    public function testAPathThatIsSetNowhereIsReportedAsRenderingEmpty(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $value = $this->strategy()->resolve($this->entry(self::NAME_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue($value->isAvailable());
        self::assertSame('', $value->getPreview());
    }

    /**
     * "All Store Views" is the default configuration, and naming it after a store would tell the
     * administrator a change lands somewhere narrower than it does.
     *
     * @return void
     */
    public function testStoreViewZeroIsNamedAsTheDefaultConfigurationRatherThanAsAStore(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('Acme Ltd');

        $value = $this->strategy()->resolve($this->entry(self::NAME_PATH), 0, self::TEMPLATE_ID);

        self::assertSame(ResolvedValueInterface::SCOPE_DEFAULT, $value->getScope());
        self::assertSame(0, $value->getScopeId());
        self::assertSame('Default Config', $value->getScopeLabel());
    }

    /**
     * The stored country code never reaches a message: the filter replaces it unconditionally with
     * the country's name, so showing the code would show something no message carries.
     *
     * @return void
     */
    public function testTheCountryResolvesToItsNameEvenThoughTheStoredCodeIsThere(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('US');
        $this->storeInformation->method('getStoreInformationObject')
            ->willReturn(new DataObject(['country_id' => 'US', 'country' => 'United States']));

        $value = $this->strategy()->resolve($this->entry(self::COUNTRY_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertSame('United States', $value->getPreview());
        self::assertTrue($value->isExact());
    }

    /**
     * The replacement is unconditional, so a store with no country set renders as nothing at all -
     * it does not fall back to the stored code the way the region does.
     *
     * @return void
     */
    public function testTheCountryResolvesToNothingWhenNoCountryIsSet(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');
        $this->storeInformation->method('getStoreInformationObject')->willReturn(new DataObject([]));

        $value = $this->strategy()->resolve($this->entry(self::COUNTRY_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue($value->isAvailable());
        self::assertSame('', $value->getPreview());
    }

    /**
     * The region's replacement is conditional, which is where it parts company with the country.
     *
     * @return void
     */
    public function testTheRegionResolvesToItsNameWhenARegionRecordResolves(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('43');
        $this->storeInformation->method('getStoreInformationObject')
            ->willReturn(new DataObject(['region_id' => '43', 'region' => 'Illinois']));

        $value = $this->strategy()->resolve($this->entry(self::REGION_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertSame('Illinois', $value->getPreview());
    }

    /**
     * And when no region record resolves the stored identifier is what renders, unlike the country
     * which renders as nothing. The two paths look alike and behave differently.
     *
     * @return void
     */
    public function testTheRegionKeepsTheStoredIdentifierWhenNoRegionRecordResolves(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('43');
        $this->storeInformation->method('getStoreInformationObject')
            ->willReturn(new DataObject(['region_id' => '43']));

        $value = $this->strategy()->resolve($this->entry(self::REGION_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertSame('43', $value->getPreview());
    }

    /**
     * Naming a region and a country costs two record loads, and one panel asks about many
     * references, so the answer is worked out once and reused.
     *
     * @return void
     */
    public function testTheStoreInformationIsWorkedOutOnceForAWholeBatch(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('43');
        $this->storeInformation->expects(self::once())
            ->method('getStoreInformationObject')
            ->willReturn(new DataObject(['country' => 'United States', 'region' => 'Illinois']));

        $strategy = $this->strategy();
        $strategy->resolve($this->entry(self::COUNTRY_PATH), self::STORE_ID, self::TEMPLATE_ID);
        $strategy->resolve($this->entry(self::REGION_PATH), self::STORE_ID, self::TEMPLATE_ID);
        $strategy->resolve($this->entry(self::COUNTRY_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue(true, 'The expectation on the store information service is the assertion.');
    }

    /**
     * The other nineteen paths a message may read need no record loads at all, so none are made.
     *
     * @return void
     */
    public function testAPathThatNeedsNoNamingNeverAsksForStoreInformation(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('Acme Ltd');
        $this->storeInformation->expects(self::never())->method('getStoreInformationObject');

        $this->strategy()->resolve($this->entry(self::NAME_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue(true, 'The expectation on the store information service is the assertion.');
    }

    /**
     * A locator naming a whole group has no single value behind it, and the first field of the group
     * is not the answer.
     *
     * @return void
     */
    public function testALocatorNamingAGroupOfFieldsHasNoValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(['name' => 'Acme Ltd', 'city' => 'Springfield']);

        $value = $this->strategy()->resolve(
            // A group is named by an entry describing something assembled from it, never by a
            // {{config}} directive, which reads single fields from a fixed list.
            $this->entry('general/store_information', 'var'),
            self::STORE_ID,
            self::TEMPLATE_ID
        );

        self::assertFalse($value->isAvailable());
        self::assertSame('', $value->getScope());
    }

    public function testAnOriginWithNoLocatorHasNoValue(): void
    {
        $this->scopeConfig->expects(self::never())->method('getValue');

        $value = $this->strategy()->resolve($this->entry(''), self::STORE_ID, self::TEMPLATE_ID);

        self::assertFalse($value->isAvailable());
    }

    /**
     * A {{config}} directive naming a path outside the filter's list renders an empty string
     * whatever is stored there, so the stored value must not be shown beside it: the whole purpose
     * of the panel is that what it shows is what the message will carry.
     *
     * @return void
     */
    public function testAPathTheDirectiveCannotReadHasNoValueEvenThoughOneIsStored(): void
    {
        $this->readablePaths = [];
        $this->scopeConfig->expects(self::never())->method('getValue');

        $value = $this->strategy()->resolve($this->entry(self::NAME_PATH), self::STORE_ID, self::TEMPLATE_ID);

        self::assertFalse($value->isAvailable());
        self::assertSame('', $value->getPreview());
        self::assertSame('', $value->getScope());
    }

    /**
     * The fixed list belongs to the {{config}} directive and to nothing else. A value that lives in
     * the configuration but reaches a message through some other directive is read normally, or the
     * fix for one silent wrong answer would introduce another.
     *
     * @return void
     */
    public function testAValueReachedThroughAnotherDirectiveIsNotHeldToThatDirectivesList(): void
    {
        $this->readablePaths = [];
        $this->scopeConfig->method('getValue')->willReturn('Acme Ltd');

        $value = $this->strategy()->resolve(
            $this->entry(self::NAME_PATH, 'var'),
            self::STORE_ID,
            self::TEMPLATE_ID
        );

        self::assertTrue($value->isAvailable());
        self::assertSame('Acme Ltd', $value->getPreview());
    }

    /**
     * The strategy under test
     *
     * @return ConfigValueStrategy
     */
    private function strategy(): ConfigValueStrategy
    {
        return new ConfigValueStrategy(
            $this->scopeConfig,
            $this->storeManager,
            $this->storeInformation,
            $this->readability
        );
    }

    /**
     * An entry whose origin is the given configuration path
     *
     * @param string $path Configuration path the origin points at
     * @param string $referenceKind Directive kind the entry describes
     * @return VariableKnowledgeInterface
     */
    private function entry(string $path, string $referenceKind = 'config'): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference($referenceKind, $path),
            true,
            'Configuration value',
            'Read from the store configuration.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, $path, 'Read from the store configuration.')
        );
    }
}
