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
    private const IDENTIFIER = 'sales_email_order_template';
    private const STORE_ID = 3;

    /**
     * The moment every window in this class is measured against
     */
    private const NOW = '2026-06-15 12:00:00';

    private const FAR_PAST = '2026-01-01 00:00:00';
    private const PAST = '2026-06-01 09:00:00';
    private const FUTURE = '2026-06-30 23:59:59';
    private const FAR_FUTURE = '2026-12-24 00:00:00';

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

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime(self::NOW));

        $this->repository = new TemplateOverrideRepository(
            $this->mockFactory(TemplateOverrideFactory::class),
            $this->createMock(TemplateOverrideResource::class),
            $this->collectionFactory,
            $timezone
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
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
     */
    public function windowShapeProvider(): array
    {
        return [
            'both bounds, now inside the window' => [self::PAST, self::FUTURE, true],
            'both bounds, window has not started' => [self::FUTURE, self::FAR_FUTURE, false],
            'both bounds, window is over' => [self::FAR_PAST, self::PAST, false],
            'start only, already reached' => [self::PAST, null, true],
            'start only, still ahead' => [self::FUTURE, null, false],
            'end only, still ahead' => [null, self::FUTURE, true],
            'end only, already passed' => [null, self::PAST, false],
            'neither bound' => [null, null, false],
            'both bounds, starting exactly now' => [self::NOW, self::FUTURE, true],
            'both bounds, ending exactly now' => [self::PAST, self::NOW, true],
            'start only, exactly now' => [self::NOW, null, true],
            'end only, exactly now' => [null, self::NOW, true],
        ];
    }

    /**
     * A bound that is not set is an open end, not a missing window
     *
     * The two half-open shapes are the ones a publish carrying a single date produces, and they
     * are what a comparison of both columns against the current time drops on the floor: no
     * comparison against NULL is ever true, so such a row matches neither the windowed question
     * nor the undated one and the override applies to nobody.
     *
     * A row with neither bound is not answered here at all — it is the undated row — so that no
     * row can be claimed by both questions.
     *
     * @dataProvider windowShapeProvider
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @param bool $expected
     * @return void
     */
    public function testAnOpenEndedWindowIsStillAWindow(?string $activeFrom, ?string $activeTo, bool $expected): void
    {
        $this->stubQueryOver([
            $this->overrideRow(7, [
                TemplateOverrideInterface::ACTIVE_FROM => $activeFrom,
                TemplateOverrideInterface::ACTIVE_TO => $activeTo,
            ]),
        ]);

        $found = $this->repository->getActiveScheduledPublished(self::IDENTIFIER, self::STORE_ID);

        self::assertSame($expected, $found !== null);
    }

    /**
     * @dataProvider windowShapeProvider
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @return void
     */
    public function testOnlyTheRowWithoutAnyBoundIsTheUndatedOne(?string $activeFrom, ?string $activeTo): void
    {
        $this->stubQueryOver([
            $this->overrideRow(7, [
                TemplateOverrideInterface::ACTIVE_FROM => $activeFrom,
                TemplateOverrideInterface::ACTIVE_TO => $activeTo,
            ]),
        ]);

        $found = $this->repository->getImmediatePublished(self::IDENTIFIER, self::STORE_ID);

        self::assertSame(
            $activeFrom === null && $activeTo === null,
            $found !== null,
            'A row carrying either bound belongs to the windowed question, not to this one.'
        );
    }

    public function testAWindowedOverrideThatWasSwitchedOffDoesNotApply(): void
    {
        $this->stubQueryOver([
            $this->overrideRow(7, [
                TemplateOverrideInterface::ACTIVE_FROM => self::PAST,
                TemplateOverrideInterface::ACTIVE_TO => self::FUTURE,
                TemplateOverrideInterface::IS_ACTIVE => 0,
            ]),
        ]);

        self::assertNull($this->repository->getActiveScheduledPublished(self::IDENTIFIER, self::STORE_ID));
    }

    /**
     * Switching an override off means it does not apply, but it still holds the undated slot
     *
     * Two different questions about one row, which is why they are two methods. What applies has
     * to skip it, or an admin switching a store-view override off would get stock templates
     * instead of the override standing behind it. What occupies the slot has to see it, or a
     * second undated row gets published behind it and the two compete for one place.
     *
     * @return void
     */
    public function testAnUndatedOverrideThatWasSwitchedOffHoldsItsSlotWithoutApplying(): void
    {
        $this->stubQueryOver([
            $this->overrideRow(7, [TemplateOverrideInterface::IS_ACTIVE => 0]),
        ]);

        self::assertNull(
            $this->repository->getImmediatePublished(self::IDENTIFIER, self::STORE_ID),
            'A switched-off override is not there as far as what applies is concerned.'
        );

        $occupant = $this->repository->getUndatedPublishedRegardlessOfState(self::IDENTIFIER, self::STORE_ID);

        self::assertNotNull($occupant);
        self::assertSame(7, $occupant->getEntityId());
    }

    public function testTheLiveUndatedRowIsFoundAheadOfASwitchedOffOne(): void
    {
        $this->stubQueryOver([
            $this->overrideRow(7, [TemplateOverrideInterface::IS_ACTIVE => 0]),
            $this->overrideRow(9),
        ]);

        $found = $this->repository->getImmediatePublished(self::IDENTIFIER, self::STORE_ID);

        self::assertNotNull($found);
        self::assertSame(
            9,
            $found->getEntityId(),
            'The lowest id wins only among the rows that are switched on.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function nonPublishedStatusProvider(): array
    {
        return [
            'draft' => [TemplateOverrideInterface::STATUS_DRAFT],
            'scheduled' => [TemplateOverrideInterface::STATUS_SCHEDULED],
        ];
    }

    /**
     * @dataProvider nonPublishedStatusProvider
     * @param string $status
     * @return void
     */
    public function testOnlyPublishedRowsAnswerEitherQuestion(string $status): void
    {
        $this->stubQueryOver([
            $this->overrideRow(7, [TemplateOverrideInterface::STATUS => $status]),
            $this->overrideRow(8, [
                TemplateOverrideInterface::STATUS => $status,
                TemplateOverrideInterface::ACTIVE_FROM => self::PAST,
            ]),
        ]);

        self::assertNull($this->repository->getImmediatePublished(self::IDENTIFIER, self::STORE_ID));
        self::assertNull($this->repository->getActiveScheduledPublished(self::IDENTIFIER, self::STORE_ID));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public function foreignRowProvider(): array
    {
        return [
            'another template' => [[TemplateOverrideInterface::TEMPLATE_IDENTIFIER => 'other_template']],
            'another store' => [[TemplateOverrideInterface::STORE_ID => self::STORE_ID + 1]],
        ];
    }

    /**
     * @dataProvider foreignRowProvider
     * @param array<string, mixed> $foreign
     * @return void
     */
    public function testRowsOfAnotherTemplateOrStoreAreNotConsidered(array $foreign): void
    {
        $this->stubQueryOver([
            $this->overrideRow(7, $foreign),
            $this->overrideRow(8, $foreign + [TemplateOverrideInterface::ACTIVE_TO => self::FUTURE]),
        ]);

        self::assertNull($this->repository->getImmediatePublished(self::IDENTIFIER, self::STORE_ID));
        self::assertNull($this->repository->getActiveScheduledPublished(self::IDENTIFIER, self::STORE_ID));
    }

    /**
     * Two windows can only be open at once where one ends exactly as the next begins
     *
     * The bounds are inclusive, so both rows are open at that single instant. Naming the lower
     * entity id makes the answer the same on every call instead of leaving it to the query plan.
     *
     * @return void
     */
    public function testTheOlderRowWinsWhenTwoWindowsShareTheirBoundary(): void
    {
        $this->stubQueryOver([
            $this->overrideRow(21, [
                TemplateOverrideInterface::ACTIVE_FROM => self::NOW,
                TemplateOverrideInterface::ACTIVE_TO => self::FUTURE,
            ]),
            $this->overrideRow(9, [
                TemplateOverrideInterface::ACTIVE_FROM => self::PAST,
                TemplateOverrideInterface::ACTIVE_TO => self::NOW,
            ]),
        ]);

        $found = $this->repository->getActiveScheduledPublished(self::IDENTIFIER, self::STORE_ID);

        self::assertNotNull($found);
        self::assertSame(9, $found->getEntityId());
    }

    public function testTheOlderRowWinsWhenTwoUndatedRowsHoldTheSlot(): void
    {
        $this->stubQueryOver([
            $this->overrideRow(21),
            $this->overrideRow(9),
        ]);

        $found = $this->repository->getImmediatePublished(self::IDENTIFIER, self::STORE_ID);

        self::assertNotNull($found);
        self::assertSame(9, $found->getEntityId());
    }

    /**
     * The window is asked for as three OR groups, which is what lets a bound be absent
     *
     * A plain condition on a column that is NULL never holds, so a half-open window can only be
     * matched by pairing the comparison with an explicit "no bound set" alternative. The first
     * group is what keeps the undated row out of this answer. The moment being compared against
     * is the store's, not the server's.
     *
     * @return void
     */
    public function testAnAbsentBoundIsAskedForAlongsideTheComparison(): void
    {
        $calls = [];
        $this->stubRecordingCollection($calls);

        $this->repository->getActiveScheduledPublished(self::IDENTIFIER, self::STORE_ID);

        self::assertSame(
            [
                [
                    [TemplateOverrideInterface::ACTIVE_FROM, TemplateOverrideInterface::ACTIVE_TO],
                    [['notnull' => true], ['notnull' => true]],
                ],
                [
                    [TemplateOverrideInterface::ACTIVE_FROM, TemplateOverrideInterface::ACTIVE_FROM],
                    [['null' => true], ['lteq' => self::NOW]],
                ],
                [
                    [TemplateOverrideInterface::ACTIVE_TO, TemplateOverrideInterface::ACTIVE_TO],
                    [['null' => true], ['gteq' => self::NOW]],
                ],
            ],
            $this->filterCallsOn('addFieldToFilter', $calls, true)
        );

        self::assertSame(
            [
                [TemplateOverrideInterface::TEMPLATE_IDENTIFIER, self::IDENTIFIER],
                [TemplateOverrideInterface::STORE_ID, self::STORE_ID],
                [TemplateOverrideInterface::STATUS, TemplateOverrideInterface::STATUS_PUBLISHED],
                [TemplateOverrideInterface::IS_ACTIVE, 1],
            ],
            $this->filterCallsOn('addFieldToFilter', $calls, false)
        );
    }

    public function testTheUndatedRowIsAskedForByBothColumnsBeingUnset(): void
    {
        $calls = [];
        $this->stubRecordingCollection($calls);

        $this->repository->getImmediatePublished(self::IDENTIFIER, self::STORE_ID);

        self::assertSame([], $this->filterCallsOn('addFieldToFilter', $calls, true));
        self::assertSame(
            [
                [TemplateOverrideInterface::TEMPLATE_IDENTIFIER, self::IDENTIFIER],
                [TemplateOverrideInterface::STORE_ID, self::STORE_ID],
                [TemplateOverrideInterface::STATUS, TemplateOverrideInterface::STATUS_PUBLISHED],
                [TemplateOverrideInterface::ACTIVE_FROM, ['null' => true]],
                [TemplateOverrideInterface::ACTIVE_TO, ['null' => true]],
                [TemplateOverrideInterface::IS_ACTIVE, 1],
            ],
            $this->filterCallsOn('addFieldToFilter', $calls, false)
        );
    }

    public function testTheSlotOccupantIsAskedForWithoutRegardToWhetherItIsSwitchedOn(): void
    {
        $calls = [];
        $this->stubRecordingCollection($calls);

        $this->repository->getUndatedPublishedRegardlessOfState(self::IDENTIFIER, self::STORE_ID);

        self::assertSame(
            [
                [TemplateOverrideInterface::TEMPLATE_IDENTIFIER, self::IDENTIFIER],
                [TemplateOverrideInterface::STORE_ID, self::STORE_ID],
                [TemplateOverrideInterface::STATUS, TemplateOverrideInterface::STATUS_PUBLISHED],
                [TemplateOverrideInterface::ACTIVE_FROM, ['null' => true]],
                [TemplateOverrideInterface::ACTIVE_TO, ['null' => true]],
            ],
            $this->filterCallsOn('addFieldToFilter', $calls, false),
            'The one condition that separates this from the liveness question is the one on is_active.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function singleRowLookupProvider(): array
    {
        return [
            'the live undated row' => ['getImmediatePublished'],
            'the undated slot occupant' => ['getUndatedPublishedRegardlessOfState'],
            'the open window' => ['getActiveScheduledPublished'],
        ];
    }

    /**
     * @dataProvider singleRowLookupProvider
     * @param string $method
     * @return void
     */
    public function testTheAnswerIsOneRowInAStatedOrder(string $method): void
    {
        $calls = [];
        $this->stubRecordingCollection($calls);

        $this->repository->{$method}(self::IDENTIFIER, self::STORE_ID);

        self::assertSame(
            [[TemplateOverrideInterface::ENTITY_ID, 'ASC']],
            $this->filterCallsOn('setOrder', $calls, null),
            'Without a stated order the row that answers this depends on the query plan.'
        );
        self::assertSame([[1]], $this->filterCallsOn('setPageSize', $calls, null));
    }

    public function testNeitherQuestionInventsARowWhenThereAreNone(): void
    {
        $this->stubQueryOver([]);

        self::assertNull($this->repository->getImmediatePublished(self::IDENTIFIER, self::STORE_ID));
        self::assertNull($this->repository->getActiveScheduledPublished(self::IDENTIFIER, self::STORE_ID));
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
     * Wire the collection factory to a collection that answers from the given rows
     *
     * The double evaluates what the repository asks for instead of recording it, so a test can
     * state a row shape as data and assert which row comes back. It implements the slice of the
     * collection contract these lookups use, under the rules the generated SQL runs under: each
     * addFieldToFilter() narrows the set and an array of fields paired with an array of conditions
     * is an OR across them; setOrder() sorts and setPageSize() truncates; a condition on a column
     * that is NULL only holds when it asks for NULL; and getFirstItem() on an empty result is an
     * entity without an id rather than nothing at all.
     *
     * Each create() hands back a fresh double over the untouched row set, as the factory does with
     * a real collection: a repository method that narrowed one collection must not have narrowed
     * the collection the next lookup is about to build.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return void
     */
    private function stubQueryOver(array $rows): void
    {
        $this->collectionFactory->method('create')->willReturnCallback(
            fn (): Collection => $this->collectionDoubleOver($rows)
        );
    }

    /**
     * Build one collection double answering from its own copy of the rows
     *
     * @param array<int, array<string, mixed>> $rows
     * @return Collection&MockObject
     */
    private function collectionDoubleOver(array $rows): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);

        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition = null) use (&$rows): void {
                $fields = is_array($field) ? array_values($field) : [$field];
                $conditions = is_array($field) ? array_values((array)$condition) : [$condition];

                $rows = array_values(
                    array_filter(
                        $rows,
                        function (array $row) use ($fields, $conditions): bool {
                            foreach ($fields as $index => $name) {
                                if ($this->satisfies($row[$name] ?? null, $conditions[$index] ?? null)) {
                                    return true;
                                }
                            }

                            return false;
                        }
                    )
                );
            }
        );

        $collection->method('setOrder')->willReturnCallback(
            static function (string $field, string $direction) use (&$rows): void {
                usort(
                    $rows,
                    static fn (array $left, array $right): int => $direction === 'ASC'
                        ? $left[$field] <=> $right[$field]
                        : $right[$field] <=> $left[$field]
                );
            }
        );

        $collection->method('setPageSize')->willReturnCallback(
            static function (int $size) use (&$rows): void {
                $rows = array_slice($rows, 0, $size);
            }
        );

        // A closure rather than an arrow function: the narrowing above rebinds $rows, and an arrow
        // function would have captured the unfiltered array by value when it was created.
        $collection->method('getFirstItem')->willReturnCallback(
            function () use (&$rows): TemplateOverrideInterface {
                return $this->rowEntity($rows[0] ?? []);
            }
        );

        return $collection;
    }

    /**
     * Wire the collection factory to a collection that records every call it is given, in order
     *
     * @param array<int, array<int, mixed>> $calls Filled with [method, ...arguments] per call
     * @return void
     */
    private function stubRecordingCollection(array &$calls): void
    {
        $collection = $this->createMock(Collection::class);

        foreach (['addFieldToFilter', 'setOrder', 'setPageSize'] as $method) {
            $collection->method($method)->willReturnCallback(
                static function (...$arguments) use (&$calls, $method): void {
                    $calls[] = array_merge([$method], $arguments);
                }
            );
        }

        $collection->method('getFirstItem')->willReturnCallback(
            function (): TemplateOverrideInterface {
                return $this->rowEntity([]);
            }
        );

        $this->collectionFactory->method('create')->willReturn($collection);
    }

    /**
     * Pick the arguments of the recorded calls to one method
     *
     * @param string $method
     * @param array<int, array<int, mixed>> $calls
     * @param bool|null $withArrayField Keep only calls whose first argument is (or is not) an array
     * @return array<int, array<int, mixed>>
     */
    private function filterCallsOn(string $method, array $calls, ?bool $withArrayField): array
    {
        $selected = [];

        foreach ($calls as $call) {
            $arguments = array_slice($call, 1);

            if ($call[0] !== $method) {
                continue;
            }

            if ($withArrayField !== null && is_array($arguments[0] ?? null) !== $withArrayField) {
                continue;
            }

            $selected[] = $arguments;
        }

        return $selected;
    }

    /**
     * Decide whether one column value satisfies one collection condition
     *
     * @param mixed $value
     * @param mixed $condition
     * @return bool
     */
    private function satisfies($value, $condition): bool
    {
        if (!is_array($condition)) {
            return $value !== null && (string)$value === (string)$condition;
        }

        if (array_key_exists('null', $condition)) {
            return $value === null;
        }

        if (array_key_exists('notnull', $condition)) {
            return $value !== null;
        }

        if (array_key_exists('lteq', $condition)) {
            return $value !== null && (string)$value <= (string)$condition['lteq'];
        }

        if (array_key_exists('gteq', $condition)) {
            return $value !== null && (string)$value >= (string)$condition['gteq'];
        }

        throw new \LogicException(
            'The collection double answers no such condition: ' . json_encode($condition)
        );
    }

    /**
     * Build a published, active, undated row of the template and store every test asks about
     *
     * @param int $entityId
     * @param array<string, mixed> $differences Columns that differ from that shape
     * @return array<string, mixed>
     */
    private function overrideRow(int $entityId, array $differences = []): array
    {
        return $differences + [
            TemplateOverrideInterface::ENTITY_ID => $entityId,
            TemplateOverrideInterface::TEMPLATE_IDENTIFIER => self::IDENTIFIER,
            TemplateOverrideInterface::STORE_ID => self::STORE_ID,
            TemplateOverrideInterface::STATUS => TemplateOverrideInterface::STATUS_PUBLISHED,
            TemplateOverrideInterface::IS_ACTIVE => 1,
            TemplateOverrideInterface::ACTIVE_FROM => null,
            TemplateOverrideInterface::ACTIVE_TO => null,
        ];
    }

    /**
     * Turn a row into the entity the collection hands back, or an entity without an id when empty
     *
     * @param array<string, mixed> $row
     * @return TemplateOverrideInterface&MockObject
     */
    private function rowEntity(array $row): TemplateOverrideInterface&MockObject
    {
        $entity = $this->createMock(TemplateOverrideInterface::class);
        $entity->method('getEntityId')->willReturn($row[TemplateOverrideInterface::ENTITY_ID] ?? null);
        $entity->method('getActiveFrom')->willReturn($row[TemplateOverrideInterface::ACTIVE_FROM] ?? null);
        $entity->method('getActiveTo')->willReturn($row[TemplateOverrideInterface::ACTIVE_TO] ?? null);
        $entity->method('getIsActive')->willReturn((bool)($row[TemplateOverrideInterface::IS_ACTIVE] ?? false));
        $entity->method('getStatus')->willReturn((string)($row[TemplateOverrideInterface::STATUS] ?? ''));
        $entity->method('getStoreId')->willReturn((int)($row[TemplateOverrideInterface::STORE_ID] ?? 0));

        return $entity;
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
