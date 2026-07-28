<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Value;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\MockVariableBuilderInterface;
use Hryvinskyi\EmailTemplateEditor\Api\MockVariableBuilderPoolInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateSampleDataMapperInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Value\MockValueStrategy;
use Magento\Framework\DataObject;
use Magento\Framework\Filter\Template;
use Magento\Framework\Filter\Template\Tokenizer\Variable as VariableTokenizer;
use Magento\Framework\Filter\Template\Tokenizer\VariableFactory as VariableTokenizerFactory;
use Magento\Framework\Filter\VariableResolver\StrictResolver;
use Magento\Framework\Filter\VariableResolverInterface;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MockValueStrategyTest extends TestCase
{
    private const STORE_ID = 3;
    private const TEMPLATE_ID = 'sales_email_order_template';

    /**
     * @var TemplateSampleDataMapperInterface&MockObject
     */
    private TemplateSampleDataMapperInterface $templateMapper;

    /**
     * @var MockVariableBuilderPoolInterface&MockObject
     */
    private MockVariableBuilderPoolInterface $builderPool;

    /**
     * @var MockVariableBuilderInterface&MockObject
     */
    private MockVariableBuilderInterface $builder;

    /**
     * @var VariableResolverInterface&MockObject
     */
    private VariableResolverInterface $variableResolver;

    protected function setUp(): void
    {
        $this->templateMapper = $this->createMock(TemplateSampleDataMapperInterface::class);
        $this->templateMapper->method('getCategory')->willReturn('order');

        $this->builder = $this->createMock(MockVariableBuilderInterface::class);
        $this->builder->method('build')->willReturn(['order' => new DataObject(['increment_id' => '000000123'])]);

        $this->builderPool = $this->createMock(MockVariableBuilderPoolInterface::class);
        $this->builderPool->method('getBuilder')->willReturn($this->builder);

        $this->variableResolver = $this->createMock(VariableResolverInterface::class);
    }

    public function testItClaimsTheOriginsWhoseValuesOnlyExistWhileAMessageIsBeingSent(): void
    {
        $strategy = $this->strategy();

        self::assertTrue($strategy->supports(new Origin(OriginInterface::KIND_TEMPLATE_VAR, 'order', '')));
        self::assertTrue($strategy->supports(new Origin(OriginInterface::KIND_COMPUTED, '', '')));
        self::assertFalse($strategy->supports(new Origin(OriginInterface::KIND_CONFIG, 'general/x/y', '')));
        self::assertFalse($strategy->supports(new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, 'my_code', '')));
    }

    /**
     * The answer stands in for a record nobody has sent about yet, so it never claims to be exact
     * and never names a scope it was not read from.
     *
     * @return void
     */
    public function testAResolvedPathIsAnsweredAsASampleRatherThanAsTheRealThing(): void
    {
        $this->variableResolver->method('resolve')->willReturn('000000123');

        $value = $this->strategy()->resolve(
            $this->entry('order.increment_id'),
            self::STORE_ID,
            self::TEMPLATE_ID
        );

        self::assertTrue($value->isAvailable());
        self::assertFalse($value->isExact());
        self::assertSame('000000123', $value->getPreview());
        self::assertSame('', $value->getScope());
        self::assertSame(0, $value->getScopeId());
        self::assertSame('', $value->getScopeLabel());
    }

    public function testThePathIsWalkedOverTheSampleRecordsForTheTemplateAtHand(): void
    {
        $this->variableResolver->expects(self::once())
            ->method('resolve')
            ->with(
                'order.increment_id',
                self::isInstanceOf(Template::class),
                ['order' => new DataObject(['increment_id' => '000000123'])]
            )
            ->willReturn('000000123');

        $this->strategy()->resolve($this->entry('order.increment_id'), self::STORE_ID, self::TEMPLATE_ID);
    }

    /**
     * "We have no sample for this" and "this renders as empty" are different answers, and an
     * administrator has to be able to tell them apart.
     *
     * @return void
     */
    public function testAPathThatLeadsNowhereIsUnavailableRatherThanEmpty(): void
    {
        $this->variableResolver->method('resolve')->willReturn(null);

        $value = $this->strategy()->resolve($this->entry('order.no_such_field'), self::STORE_ID, self::TEMPLATE_ID);

        self::assertFalse($value->isAvailable());
        self::assertSame('', $value->getPreview());
    }

    public function testAPathThatLeadsToAnEmptyValueIsAvailableAndEmpty(): void
    {
        $this->variableResolver->method('resolve')->willReturn('');

        $value = $this->strategy()->resolve($this->entry('order.customer_note'), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue($value->isAvailable());
        self::assertSame('', $value->getPreview());
    }

    /**
     * A path leading to a whole record rather than to one of its fields is not a value either.
     *
     * @return void
     */
    public function testAPathThatLeadsToSomethingThatIsNotASingleValueHasNoAnswer(): void
    {
        $this->variableResolver->method('resolve')->willReturn(['first', 'second']);

        $value = $this->strategy()->resolve($this->entry('order.items'), self::STORE_ID, self::TEMPLATE_ID);

        self::assertFalse($value->isAvailable());
    }

    public function testAReferenceWithNoPathHasNoAnswer(): void
    {
        $this->variableResolver->expects(self::never())->method('resolve');

        $value = $this->strategy()->resolve($this->entry(''), self::STORE_ID, self::TEMPLATE_ID);

        self::assertFalse($value->isAvailable());
    }

    /**
     * One panel asks about many references at once, and building the sample records once per
     * reference would build the same records over and over.
     *
     * @return void
     */
    public function testTheSampleRecordsAreBuiltOnceForAWholeBatch(): void
    {
        $this->variableResolver->method('resolve')->willReturn('000000123');
        $this->builder->expects(self::once())->method('build')->with(self::TEMPLATE_ID, self::STORE_ID);

        $strategy = $this->strategy();
        $strategy->resolve($this->entry('order.increment_id'), self::STORE_ID, self::TEMPLATE_ID);
        $strategy->resolve($this->entry('order.customer_name'), self::STORE_ID, self::TEMPLATE_ID);
        $strategy->resolve($this->entry('order.created_at'), self::STORE_ID, self::TEMPLATE_ID);

        self::assertTrue(true, 'The expectation on the sample record builder is the assertion.');
    }

    public function testADifferentStoreViewGetsItsOwnSampleRecords(): void
    {
        $this->variableResolver->method('resolve')->willReturn('000000123');
        $this->builder->expects(self::exactly(2))->method('build');

        $strategy = $this->strategy();
        $strategy->resolve($this->entry('order.increment_id'), self::STORE_ID, self::TEMPLATE_ID);
        $strategy->resolve($this->entry('order.increment_id'), self::STORE_ID + 1, self::TEMPLATE_ID);

        self::assertTrue(true, 'The expectation on the sample record builder is the assertion.');
    }

    /**
     * The whole point of the distinction is that it matches what happens when a message renders, so
     * it is worth drawing once against the walker that really runs rather than against a stand-in.
     *
     * @dataProvider realWalkerProvider
     * @param string $path Variable path to walk over the sample record
     * @param bool $expectedAvailable Whether an answer is expected at all
     * @param string $expectedPreview The answer expected when there is one
     * @return void
     */
    public function testTheDistinctionHoldsAgainstTheWalkerTheTemplateFilterUses(
        string $path,
        bool $expectedAvailable,
        string $expectedPreview
    ): void {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            static fn (): VariableTokenizer => new VariableTokenizer()
        );

        $builder = $this->createMock(MockVariableBuilderInterface::class);
        $builder->method('build')->willReturn(
            ['order' => new DataObject(['increment_id' => '000000123', 'customer_note' => ''])]
        );

        $builderPool = $this->createMock(MockVariableBuilderPoolInterface::class);
        $builderPool->method('getBuilder')->willReturn($builder);

        $strategy = new MockValueStrategy(
            $this->templateMapper,
            $builderPool,
            new StrictResolver(new VariableTokenizerFactory($objectManager)),
            $this->createMock(Template::class)
        );

        $value = $strategy->resolve($this->entry($path), self::STORE_ID, self::TEMPLATE_ID);

        self::assertSame($expectedAvailable, $value->isAvailable());
        self::assertSame($expectedPreview, $value->getPreview());
        self::assertFalse($value->isExact());
    }

    /**
     * A path that leads to a value, one that leads to an empty value, and one that leads nowhere
     *
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public function realWalkerProvider(): array
    {
        return [
            'a field with a value' => ['order.increment_id', true, '000000123'],
            'a field that is empty' => ['order.customer_note', true, ''],
            'a field the record does not have' => ['order.no_such_field', false, ''],
            'a record nothing put there' => ['invoice.increment_id', false, ''],
        ];
    }

    /**
     * The strategy under test
     *
     * @return MockValueStrategy
     */
    private function strategy(): MockValueStrategy
    {
        return new MockValueStrategy(
            $this->templateMapper,
            $this->builderPool,
            $this->variableResolver,
            $this->createMock(Template::class)
        );
    }

    /**
     * An entry for a variable path the sending code fills in
     *
     * @param string $path Variable path the reference points at
     * @return VariableKnowledgeInterface
     */
    private function entry(string $path): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('var', $path),
            true,
            'Order field',
            'A field of the order the message is about.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_TEMPLATE_VAR, $path, 'Assigned by the code that sends the message.')
        );
    }
}
