<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\TemplateOverride\Collection;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\TemplateOverride\CollectionFactory;
use Hryvinskyi\EmailTemplateEditor\Model\ScheduleConflictDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ScheduleConflictDetectorTest extends TestCase
{
    private const IDENTIFIER = 'sales_email_order_template';
    private const STORE_ID = 3;

    private MockObject $collectionFactory;
    private ScheduleConflictDetector $detector;

    /**
     * Field name to condition, recorded from the collection as the detector filters it
     *
     * @var array<string, mixed>
     */
    private array $appliedFilters = [];

    protected function setUp(): void
    {
        $this->collectionFactory = $this->mockFactory(CollectionFactory::class);
        $this->detector = new ScheduleConflictDetector($this->collectionFactory);
    }

    /**
     * A candidate with neither bound claims no period, so nothing can be in its way
     *
     * @return void
     */
    public function testACandidateWithoutAWindowIsAnsweredWithoutLooking(): void
    {
        $this->collectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->detector->detect(self::IDENTIFIER, self::STORE_ID, null, null));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string|null, 3: string|null, 4: bool}>
     */
    public function windowPairProvider(): array
    {
        return [
            'closed windows that overlap' =>
                ['2026-06-10 00:00:00', '2026-06-25 00:00:00', '2026-06-20 00:00:00', '2026-07-05 00:00:00', true],
            'closed windows that do not' =>
                ['2026-06-10 00:00:00', '2026-06-15 00:00:00', '2026-06-20 00:00:00', '2026-07-05 00:00:00', false],
            'closed windows that touch at one instant' =>
                ['2026-06-10 00:00:00', '2026-06-20 00:00:00', '2026-06-20 00:00:00', '2026-07-05 00:00:00', false],
            'candidate open at its start, reaching into the existing window' =>
                [null, '2026-06-25 00:00:00', '2026-06-20 00:00:00', '2026-07-05 00:00:00', true],
            'candidate open at its start, ending before the existing window' =>
                [null, '2026-06-10 00:00:00', '2026-06-20 00:00:00', '2026-07-05 00:00:00', false],
            'candidate open at its end, starting inside the existing window' =>
                ['2026-06-25 00:00:00', null, '2026-06-20 00:00:00', '2026-07-05 00:00:00', true],
            'candidate open at its end, starting after the existing window' =>
                ['2026-07-25 00:00:00', null, '2026-06-20 00:00:00', '2026-07-05 00:00:00', false],
            'existing open at its end, candidate starting after it' =>
                ['2026-09-01 00:00:00', '2026-09-05 00:00:00', '2026-08-01 00:00:00', null, true],
            'existing open at its end, candidate finishing before it' =>
                ['2026-06-01 00:00:00', '2026-07-01 00:00:00', '2026-08-01 00:00:00', null, false],
            'existing open at its start, candidate beginning after it' =>
                ['2026-09-01 00:00:00', '2026-09-05 00:00:00', null, '2026-08-01 00:00:00', false],
            'existing open at its start, candidate beginning before it' =>
                ['2026-06-01 00:00:00', '2026-09-05 00:00:00', null, '2026-08-01 00:00:00', true],
            'both open at their end, so both run for ever' =>
                ['2026-06-01 00:00:00', null, '2027-01-01 00:00:00', null, true],
            'both open at their start, so both run from for ever' =>
                [null, '2026-06-01 00:00:00', null, '2027-01-01 00:00:00', true],
            'opposite open ends that meet' =>
                [null, '2026-08-01 00:00:00', '2026-07-01 00:00:00', null, true],
            'opposite open ends that miss' =>
                [null, '2026-06-01 00:00:00', '2026-07-01 00:00:00', null, false],
        ];
    }

    /**
     * A bound that is not set is an open end on that side, for the candidate and the existing row
     *
     * @dataProvider windowPairProvider
     * @param string|null $candidateFrom
     * @param string|null $candidateTo
     * @param string|null $existingFrom
     * @param string|null $existingTo
     * @param bool $expected
     * @return void
     */
    public function testAnOpenEndReachesAsFarAsItSays(
        ?string $candidateFrom,
        ?string $candidateTo,
        ?string $existingFrom,
        ?string $existingTo,
        bool $expected
    ): void {
        $this->stubCollection([$this->row(12, $existingFrom, $existingTo)]);

        $conflicts = $this->detector->detect(
            self::IDENTIFIER,
            self::STORE_ID,
            $candidateFrom,
            $candidateTo
        );

        self::assertSame($expected, $conflicts !== []);
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public function undatedExistingRowProvider(): array
    {
        return [
            'both columns unset' => [null, null],
            'both columns blank' => ['', ''],
        ];
    }

    /**
     * @dataProvider undatedExistingRowProvider
     * @param string|null $existingFrom
     * @param string|null $existingTo
     * @return void
     */
    public function testAnExistingRowWithoutAWindowIsNotCompetingForThePeriod(
        ?string $existingFrom,
        ?string $existingTo
    ): void {
        $this->stubCollection([$this->row(12, $existingFrom, $existingTo)]);

        self::assertSame(
            [],
            $this->detector->detect(self::IDENTIFIER, self::STORE_ID, '2026-06-01 00:00:00', null),
            'That row is the standing override the candidate window displaces, not a rival window.'
        );
    }

    public function testAConflictCarriesWhatNamesTheRowItCollidesWith(): void
    {
        $this->stubCollection([
            $this->row(12, '2026-06-01 00:00:00', '2026-07-01 00:00:00', 'Summer sale'),
            $this->row(13, '2029-06-01 00:00:00', '2029-07-01 00:00:00', 'Far off'),
        ]);

        self::assertSame(
            [
                [
                    'entity_id' => 12,
                    'draft_name' => 'Summer sale',
                    'active_from' => '2026-06-01 00:00:00',
                    'active_to' => '2026-07-01 00:00:00',
                ],
            ],
            $this->detector->detect(
                self::IDENTIFIER,
                self::STORE_ID,
                '2026-06-15 00:00:00',
                '2026-06-20 00:00:00'
            )
        );
    }

    public function testOnlyTheTemplateAndStoreBeingScheduledAreExamined(): void
    {
        $this->stubCollection([]);

        $this->detector->detect(self::IDENTIFIER, self::STORE_ID, '2026-06-01 00:00:00', null);

        self::assertSame(self::IDENTIFIER, $this->appliedFilters[TemplateOverrideInterface::TEMPLATE_IDENTIFIER]);
        self::assertSame(self::STORE_ID, $this->appliedFilters[TemplateOverrideInterface::STORE_ID]);
        self::assertSame(
            ['in' => [TemplateOverrideInterface::STATUS_PUBLISHED, TemplateOverrideInterface::STATUS_SCHEDULED]],
            $this->appliedFilters[TemplateOverrideInterface::STATUS],
            'A draft claims no period until it is published or scheduled.'
        );
        self::assertArrayNotHasKey(
            TemplateOverrideInterface::ENTITY_ID,
            $this->appliedFilters,
            'Nothing is excluded unless the caller names a row to exclude.'
        );
    }

    public function testTheRowBeingRescheduledIsNotCountedAgainstItself(): void
    {
        $this->stubCollection([]);

        $this->detector->detect(self::IDENTIFIER, self::STORE_ID, '2026-06-01 00:00:00', null, 12);

        self::assertSame(['neq' => 12], $this->appliedFilters[TemplateOverrideInterface::ENTITY_ID]);
    }

    /**
     * Wire the collection factory to a collection iterating the given rows
     *
     * Every filter the detector applies is recorded in $appliedFilters as it goes.
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
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        $this->collectionFactory->method('create')->willReturn($collection);
    }

    /**
     * Build an existing override row carrying a window
     *
     * @param int $entityId
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @param string|null $draftName
     * @return TemplateOverrideInterface&MockObject
     */
    private function row(
        int $entityId,
        ?string $activeFrom,
        ?string $activeTo,
        ?string $draftName = null
    ): TemplateOverrideInterface&MockObject {
        $row = $this->createMock(TemplateOverrideInterface::class);
        $row->method('getEntityId')->willReturn($entityId);
        $row->method('getActiveFrom')->willReturn($activeFrom);
        $row->method('getActiveTo')->willReturn($activeTo);
        $row->method('getDraftName')->willReturn($draftName);

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
