<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\TemplateOverride as TemplateOverrideResource;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\TemplateOverride\Collection;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\TemplateOverride\CollectionFactory;
use Hryvinskyi\EmailTemplateEditor\Model\TemplateOverrideFactory;
use Hryvinskyi\EmailTemplateEditor\Model\TemplateOverrideRepository;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TemplateOverrideRepositoryTest extends TestCase
{
    private MockObject $collectionFactory;
    private TemplateOverrideRepository $repository;

    /**
     * Field name to condition, recorded from the collection as the repository filters it
     *
     * @var array<string, mixed>
     */
    private array $appliedFilters = [];

    protected function setUp(): void
    {
        $this->collectionFactory = $this->mockFactory(CollectionFactory::class);

        $this->repository = new TemplateOverrideRepository(
            $this->mockFactory(TemplateOverrideFactory::class),
            $this->createMock(TemplateOverrideResource::class),
            $this->collectionFactory,
            $this->createMock(TimezoneInterface::class)
        );
    }

    /**
     * @return array<string, array{0: string[], 1: int[]}>
     */
    public function emptyInputProvider(): array
    {
        return [
            'no identifiers' => [[], [0]],
            'no store scopes' => [['sales_email_order_template'], []],
            'neither' => [[], []],
        ];
    }

    /**
     * An empty IN list is legal SQL that matches nothing, so the guard is about the wasted round
     * trip rather than about avoiding an error. Nothing here should expect an exception.
     *
     * @dataProvider emptyInputProvider
     * @param string[] $identifiers
     * @param int[] $storeIds
     * @return void
     */
    public function testEmptyInputAnswersWithoutQuerying(array $identifiers, array $storeIds): void
    {
        $this->collectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->repository->getOverridesForIdentifiers($identifiers, $storeIds));
    }

    public function testRowsComeBackGroupedByIdentifierAndAbsentWhenThereAreNone(): void
    {
        $orderRowOne = $this->row('sales_email_order_template', TemplateOverrideInterface::STATUS_DRAFT);
        $orderRowTwo = $this->row('sales_email_order_template', TemplateOverrideInterface::STATUS_PUBLISHED);
        $invoiceRow = $this->row('sales_email_invoice_template', TemplateOverrideInterface::STATUS_DRAFT);

        $this->stubCollection([$orderRowOne, $invoiceRow, $orderRowTwo]);

        $grouped = $this->repository->getOverridesForIdentifiers(
            ['sales_email_order_template', 'sales_email_invoice_template', 'contact_email_email_template'],
            [0]
        );

        self::assertSame(
            ['sales_email_order_template', 'sales_email_invoice_template'],
            array_keys($grouped)
        );
        self::assertSame([$orderRowOne, $orderRowTwo], $grouped['sales_email_order_template']);
        self::assertArrayNotHasKey(
            'contact_email_email_template',
            $grouped,
            'An identifier with no rows is absent, not mapped to an empty list.'
        );
    }

    public function testStatusesAreFilteredInPhpRatherThanPushedIntoTheQuery(): void
    {
        $draft = $this->row('sales_email_order_template', TemplateOverrideInterface::STATUS_DRAFT);
        $published = $this->row('sales_email_order_template', TemplateOverrideInterface::STATUS_PUBLISHED);

        $this->stubCollection([$draft, $published]);

        $grouped = $this->repository->getOverridesForIdentifiers(
            ['sales_email_order_template'],
            [0],
            [TemplateOverrideInterface::STATUS_PUBLISHED]
        );

        self::assertSame([$published], $grouped['sales_email_order_template']);
        self::assertArrayNotHasKey(
            TemplateOverrideInterface::STATUS,
            $this->appliedFilters,
            'draft, published and scheduled are the only values the column holds, so a status '
            . 'condition selects nothing out while tripling the index ranges to consider.'
        );
        self::assertSame(
            ['in' => ['sales_email_order_template']],
            $this->appliedFilters[TemplateOverrideInterface::TEMPLATE_IDENTIFIER]
        );
        self::assertSame(['in' => [0]], $this->appliedFilters[TemplateOverrideInterface::STORE_ID]);
    }

    public function testARestrictedSelectAlwaysCarriesTheFieldsEveryCallerDependsOn(): void
    {
        $selected = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToSelect')->willReturnCallback(
            static function (array $fields) use (&$selected): void {
                $selected = $fields;
            }
        );
        $collection->method('getItems')->willReturn([]);
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->repository->getOverridesForIdentifiers(
            ['sales_email_order_template'],
            [0],
            [],
            [TemplateOverrideInterface::DRAFT_NAME]
        );

        self::assertContains(TemplateOverrideInterface::DRAFT_NAME, $selected);

        foreach (
            [
                TemplateOverrideInterface::ENTITY_ID,
                TemplateOverrideInterface::TEMPLATE_IDENTIFIER,
                TemplateOverrideInterface::STORE_ID,
                TemplateOverrideInterface::STATUS,
                TemplateOverrideInterface::IS_ACTIVE,
            ] as $field
        ) {
            self::assertContains(
                $field,
                $selected,
                'is_active reads as false and the grouping falls apart when ' . $field
                . ' is left out of the select.'
            );
        }

        self::assertSame(
            array_values(array_unique($selected)),
            $selected,
            'The union of the caller list and the mandatory list must not repeat a column.'
        );
    }

    public function testAnUnrestrictedSelectIsLeftAlone(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::never())->method('addFieldToSelect');
        $collection->method('getItems')->willReturn([]);
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->repository->getOverridesForIdentifiers(['sales_email_order_template'], [0]);
    }

    /**
     * Wire the collection factory to a collection returning the given rows
     *
     * Every filter the repository applies is recorded in $appliedFilters as it goes.
     *
     * @param TemplateOverrideInterface[] $rows
     * @return void
     */
    private function stubCollection(array $rows): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $condition = null): void {
                $this->appliedFilters[$field] = $condition;
            }
        );
        $collection->method('getItems')->willReturn($rows);

        $this->collectionFactory->method('create')->willReturn($collection);
    }

    /**
     * Build an override row carrying only its identifier and status
     *
     * @param string $identifier
     * @param string $status
     * @return TemplateOverrideInterface&MockObject
     */
    private function row(string $identifier, string $status): TemplateOverrideInterface&MockObject
    {
        $row = $this->createMock(TemplateOverrideInterface::class);
        $row->method('getTemplateIdentifier')->willReturn($identifier);
        $row->method('getStatus')->willReturn($status);

        return $row;
    }

    /**
     * Mock a DI-compiler-generated factory whether or not it has been generated yet
     *
     * The root package autoloads generated/code, so a factory that has already been compiled is a
     * real class here and its create() must be stubbed with onlyMethods(); one that has not been
     * compiled does not exist at all and only addMethods() can declare it. A clean recompile
     * flips which case applies, so the branch is picked at runtime.
     *
     * @param class-string $factoryClass
     * @return MockObject
     */
    private function mockFactory(string $factoryClass): MockObject
    {
        $builder = $this->getMockBuilder($factoryClass)->disableOriginalConstructor();

        return method_exists($factoryClass, 'create')
            ? $builder->onlyMethods(['create'])->getMock()
            : $builder->addMethods(['create'])->getMock();
    }
}
