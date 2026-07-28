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
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Value\CustomVariableValueStrategy;
use Magento\Framework\Escaper;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Variable\Model\Variable;
use Magento\Variable\Model\VariableFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class CustomVariableValueStrategyTest extends TestCase
{
    private const STORE_ID = 3;
    private const TEMPLATE_ID = 'sales_email_order_template';
    private const CODE = 'my_code';

    /**
     * @var VariableFactory&MockObject
     */
    private VariableFactory $variableFactory;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface $storeManager;

    protected function setUp(): void
    {
        $this->variableFactory = $this->createMock(VariableFactory::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $store = $this->createMock(Store::class);
        $store->method('getName')->willReturn('Theitbay Store View');
        $this->storeManager->method('getStore')->willReturn($store);
    }

    public function testItClaimsCustomVariableOriginsOnly(): void
    {
        $this->variableFactory->method('create')->willReturn($this->variableHolding([]));
        $strategy = $this->strategy();

        self::assertTrue($strategy->supports(new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, self::CODE, '')));
        self::assertFalse($strategy->supports(new Origin(OriginInterface::KIND_CONFIG, 'general/x/y', '')));
        self::assertFalse($strategy->supports(new Origin(OriginInterface::KIND_COMPUTED, '', '')));
    }

    public function testTheHtmlValueIsWhatAnHtmlMessageCarries(): void
    {
        $this->variableFactory->method('create')->willReturn(
            $this->variableHolding(['plain_value' => 'plain text', 'html_value' => '<b>markup</b>'])
        );

        $value = $this->strategy()->resolve($this->entry(self::CODE), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue($value->isAvailable());
        self::assertTrue($value->isExact());
        self::assertSame('<b>markup</b>', $value->getPreview());
    }

    /**
     * A variable with no HTML value does not fall back to its plain value untouched: the plain value
     * is escaped and its newlines become line breaks first. Working the fallback out here instead of
     * asking the variable would show an administrator something no message produces.
     *
     * @return void
     */
    public function testAnEmptyHtmlValueYieldsThePlainValueEscapedAndBrokenIntoLines(): void
    {
        $this->variableFactory->method('create')->willReturn(
            $this->variableHolding(['plain_value' => "Ben & Jerry\nSecond line", 'html_value' => ''])
        );

        $value = $this->strategy()->resolve($this->entry(self::CODE), self::STORE_ID, self::TEMPLATE_ID);

        self::assertSame("Ben &amp; Jerry<br />\nSecond line", $value->getPreview());
        self::assertNotSame("Ben & Jerry\nSecond line", $value->getPreview());
    }

    public function testTheVariableIsLoadedByCodeInTheScopeTheValueIsAskedFor(): void
    {
        $variable = $this->createPartialMock(Variable::class, ['setStoreId', 'loadByCode']);
        $variable->expects(self::once())->method('setStoreId')->with(self::STORE_ID)->willReturnSelf();
        $variable->expects(self::once())->method('loadByCode')->with(self::CODE)->willReturnSelf();
        $this->giveEscaper($variable);

        $this->variableFactory->method('create')->willReturn($variable);

        $value = $this->strategy()->resolve($this->entry(self::CODE), self::STORE_ID, self::TEMPLATE_ID);

        self::assertSame(ResolvedValueInterface::SCOPE_STORE, $value->getScope());
        self::assertSame(self::STORE_ID, $value->getScopeId());
        self::assertSame('Theitbay Store View', $value->getScopeLabel());
    }

    public function testStoreViewZeroIsNamedAsTheDefaultConfigurationRatherThanAsAStore(): void
    {
        $this->variableFactory->method('create')->willReturn(
            $this->variableHolding(['html_value' => 'anything'])
        );

        $value = $this->strategy()->resolve($this->entry(self::CODE), 0, self::TEMPLATE_ID);

        self::assertSame(ResolvedValueInterface::SCOPE_DEFAULT, $value->getScope());
        self::assertSame(0, $value->getScopeId());
        self::assertSame('Default Config', $value->getScopeLabel());
    }

    /**
     * A code no variable carries renders as nothing, and that is an answer about the message rather
     * than the absence of one.
     *
     * @return void
     */
    public function testACodeNoVariableCarriesIsReportedAsRenderingEmpty(): void
    {
        $this->variableFactory->method('create')->willReturn($this->variableHolding([]));

        $value = $this->strategy()->resolve($this->entry(self::CODE), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue($value->isAvailable());
        self::assertSame('', $value->getPreview());
    }

    public function testAnOriginWithNoCodeHasNoValue(): void
    {
        $this->variableFactory->expects(self::never())->method('create');

        $value = $this->strategy()->resolve($this->entry(''), self::STORE_ID, self::TEMPLATE_ID);

        self::assertFalse($value->isAvailable());
        self::assertSame('', $value->getScope());
    }

    /**
     * The strategy under test
     *
     * @return CustomVariableValueStrategy
     */
    private function strategy(): CustomVariableValueStrategy
    {
        return new CustomVariableValueStrategy($this->variableFactory, $this->storeManager);
    }

    /**
     * A variable model whose own value logic is real, holding the given row
     *
     * Only loading is stood in for; how a stored row becomes the value a message carries is exactly
     * what these tests are about, so that part is the model's own.
     *
     * @param array<string, mixed> $data Row the variable holds once it is loaded
     * @return Variable&MockObject
     */
    private function variableHolding(array $data): Variable
    {
        $variable = $this->createPartialMock(Variable::class, ['loadByCode']);
        $variable->method('loadByCode')->willReturnCallback(
            static function () use ($variable, $data): Variable {
                $variable->setData($data);

                return $variable;
            }
        );
        $this->giveEscaper($variable);

        return $variable;
    }

    /**
     * Hand the variable the escaper its own value logic needs
     *
     * The model normally receives it through its constructor, which a partial double does not run.
     *
     * @param Variable $variable Variable double to equip
     * @return void
     */
    private function giveEscaper(Variable $variable): void
    {
        (new ReflectionProperty(Variable::class, '_escaper'))->setValue($variable, new Escaper());
    }

    /**
     * An entry whose origin is the given custom-variable code
     *
     * @param string $code Code the origin points at
     * @return VariableKnowledgeInterface
     */
    private function entry(string $code): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('customVar', $code),
            true,
            'Custom variable',
            'A value a merchant maintains under System > Other Settings > Custom Variables.',
            VariableKnowledgeInterface::OUTPUT_HTML,
            new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, $code, 'Loaded by its code.')
        );
    }
}
