<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\SampleData;

use Hryvinskyi\EmailTemplateEditor\Api\MockVariableBuilderPoolInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateSampleDataMapperInterface;
use Hryvinskyi\EmailTemplateEditor\Model\SampleData\SpecificOrderProvider;
use Magento\Framework\DataObject;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Address\Renderer as AddressRenderer;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SpecificOrderProviderTest extends TestCase
{
    private OrderCollectionFactory&MockObject $orderCollectionFactory;
    private OrderCollection&MockObject $collection;
    private LoggerInterface&MockObject $logger;
    private SpecificOrderProvider $provider;

    /**
     * Every field/condition pair handed to the collection, in call order
     *
     * @var array<int, array{0: string|string[], 1: mixed}>
     */
    private array $filters = [];

    protected function setUp(): void
    {
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->collection = $this->createMock(OrderCollection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->filters = [];
        $this->collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition = null) {
                $this->filters[] = [$field, $condition];

                return $this->collection;
            }
        );

        $this->provider = new SpecificOrderProvider(
            $this->orderCollectionFactory,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(AddressRenderer::class),
            $this->createMock(PaymentHelper::class),
            $this->createMock(TemplateSampleDataMapperInterface::class),
            $this->createMock(MockVariableBuilderPoolInterface::class),
            $this->logger
        );
    }

    /**
     * Queries below the minimum length are refused before anything is asked of the database
     *
     * @param string $query
     * @return void
     * @dataProvider tooShortQueryDataProvider
     */
    public function testTooShortQueryReturnsEmptyWithoutCreatingACollection(string $query): void
    {
        $this->orderCollectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->provider->searchEntities($query, 1));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function tooShortQueryDataProvider(): array
    {
        return [
            'empty' => [''],
            'single character' => ['5'],
            'whitespace only' => ['   '],
            'single character with padding' => ['  a  '],
        ];
    }

    public function testWildcardsTypedByTheUserAreEscapedRatherThanMatchingEverything(): void
    {
        $this->expectCollection();

        $this->provider->searchEntities('50%_x', 0);

        self::assertSame(
            [
                [
                    ['customer_email', 'customer_firstname', 'customer_lastname'],
                    [
                        ['like' => '%50\%\_x%'],
                        ['like' => '%50\%\_x%'],
                        ['like' => '%50\%\_x%'],
                    ],
                ],
            ],
            $this->filters
        );
    }

    public function testBackslashInTheQueryIsEscaped(): void
    {
        $this->expectCollection();

        $this->provider->searchEntities('a\\b', 0);

        self::assertSame([['like' => '%a\\\\b%'], ['like' => '%a\\\\b%'], ['like' => '%a\\\\b%']], $this->filters[0][1]);
    }

    /**
     * An order-number query is a single trailing-wildcard condition, so it can use the index
     *
     * @param string $query
     * @param string $expectedPattern
     * @return void
     * @dataProvider orderNumberQueryDataProvider
     */
    public function testOrderNumberQueryFiltersIncrementIdAlone(string $query, string $expectedPattern): void
    {
        $this->expectCollection();

        $this->provider->searchEntities($query, 0);

        self::assertSame([['increment_id', ['like' => $expectedPattern]]], $this->filters);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function orderNumberQueryDataProvider(): array
    {
        return [
            'digits' => ['100000', '100000%'],
            'digits with padding' => ['  100000  ', '100000%'],
            'hyphen separated' => ['1-100', '1-100%'],
            'slash separated' => ['1/100', '1/100%'],
        ];
    }

    public function testStoreFilterIsAppliedAlongsideTheOrderNumberCondition(): void
    {
        $this->expectCollection();

        $this->provider->searchEntities('100000', 3);

        self::assertSame(
            [
                ['store_id', 3],
                ['increment_id', ['like' => '100000%']],
            ],
            $this->filters
        );
    }

    /**
     * A free-text query never reaches increment_id, so no leading wildcard is OR-ed onto it
     *
     * @param string $query
     * @return void
     * @dataProvider freeTextQueryDataProvider
     */
    public function testFreeTextQuerySearchesTheCustomerColumnsOnly(string $query): void
    {
        $this->expectCollection();

        $this->provider->searchEntities($query, 0);

        self::assertCount(1, $this->filters);
        self::assertSame(['customer_email', 'customer_firstname', 'customer_lastname'], $this->filters[0][0]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function freeTextQueryDataProvider(): array
    {
        return [
            'surname' => ['smith'],
            'e-mail' => ['john@example.com'],
            'digits after letters' => ['smith2'],
            'letters after digits' => ['2smith'],
        ];
    }

    public function testResultShapeAndLabelAreUnchanged(): void
    {
        $this->expectCollection([
            new DataObject([
                'entity_id' => 12,
                'increment_id' => '100000001',
                'customer_firstname' => 'John',
                'customer_lastname' => 'Doe',
                'grand_total' => '12.3400',
                'order_currency_code' => 'USD',
            ]),
            new DataObject([
                'entity_id' => 13,
                'increment_id' => '100000002',
                'grand_total' => '9',
                'order_currency_code' => 'EUR',
            ]),
        ]);

        self::assertSame(
            [
                ['id' => '12', 'label' => '#100000001 - John Doe - USD 12.34'],
                ['id' => '13', 'label' => '#100000002 - Guest  - EUR 9.00'],
            ],
            $this->provider->searchEntities('100000', 0)
        );
    }

    public function testTheSearchIsBoundedByTheNewestOrdersAndThePageSize(): void
    {
        $this->expectCollection();

        $this->collection->expects(self::once())->method('setOrder')->with('entity_id', 'DESC');
        $this->collection->expects(self::once())->method('setPageSize')->with(7);

        $this->provider->searchEntities('smith', 0, 7);
    }

    public function testACollectionFailureIsLoggedAndYieldsAnEmptyResult(): void
    {
        $this->orderCollectionFactory->method('create')->willThrowException(new \RuntimeException('gone'));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('gone'));

        self::assertSame([], $this->provider->searchEntities('smith', 0));
    }

    /**
     * Wire the factory to the collection mock and stub the rows it yields
     *
     * @param array<int, DataObject> $items
     * @return void
     */
    private function expectCollection(array $items = []): void
    {
        $this->orderCollectionFactory->method('create')->willReturn($this->collection);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($items));
    }
}
