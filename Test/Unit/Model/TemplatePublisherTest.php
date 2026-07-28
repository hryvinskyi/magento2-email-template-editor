<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterfaceFactory;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateVersionInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateVersionInterfaceFactory;
use Hryvinskyi\EmailTemplateEditor\Api\ScheduleConflictDetectorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVersionRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Model\TemplatePublisher;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Publishing, seen through the one rule it is responsible for: a template and store hold at most
 * one published override that carries no availability window.
 *
 * Nothing else enforces that. The lookups that resolve what an email uses only ever read, so if
 * two undated rows are ever written there is nothing to say which of them the admin meant.
 */
class TemplatePublisherTest extends TestCase
{
    private const IDENTIFIER = 'sales_email_order_template';
    private const STORE_ID = 3;

    private TemplateOverrideRepositoryInterface&MockObject $overrideRepository;
    private LoggerInterface&MockObject $logger;
    private TemplatePublisher $publisher;

    /**
     * Entity id to that row's stored columns — the table this test publishes into
     *
     * @var array<int, array<string, mixed>>
     */
    private array $rows = [];

    /**
     * Entity id to the entity handed to the code under test for that row
     *
     * @var array<int, TemplateOverrideInterface&MockObject>
     */
    private array $entities = [];

    protected function setUp(): void
    {
        $this->overrideRepository = $this->createMock(TemplateOverrideRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->overrideRepository->method('getById')->willReturnCallback(
            fn (int $entityId): TemplateOverrideInterface => $this->entities[$entityId]
        );
        $this->overrideRepository->method('getUndatedPublishedRegardlessOfState')->willReturnCallback(
            fn (string $identifier, int $storeId): ?TemplateOverrideInterface
                => $this->slotOccupant($identifier, $storeId)
        );
        $this->overrideRepository->method('delete')->willReturnCallback(
            function (TemplateOverrideInterface $override): bool {
                unset($this->rows[(int)$override->getEntityId()]);

                return true;
            }
        );
        $this->overrideRepository->method('save')->willReturnArgument(0);

        $versionRepository = $this->createMock(TemplateVersionRepositoryInterface::class);
        $versionRepository->method('getNextVersionNumber')->willReturn(1);

        $versionFactory = $this->mockFactory(TemplateVersionInterfaceFactory::class);
        $versionFactory->method('create')->willReturnCallback(
            fn (): TemplateVersionInterface => $this->createMock(TemplateVersionInterface::class)
        );

        $authSession = $this->getMockBuilder(AuthSession::class)
            ->disableOriginalConstructor()
            ->addMethods(['getUser'])
            ->getMock();
        $authSession->method('getUser')->willReturn(null);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-07-28 12:00:00');

        $this->publisher = new TemplatePublisher(
            $this->overrideRepository,
            $versionRepository,
            $versionFactory,
            $this->mockFactory(TemplateOverrideInterfaceFactory::class),
            $this->createMock(ScheduleConflictDetectorInterface::class),
            $authSession,
            $dateTime,
            $this->logger
        );
    }

    /**
     * Publishing over an override that was switched off leaves one row, not two
     *
     * The switched-off row is the whole point: it does not apply, so a lookup for what applies
     * cannot see it, and a uniqueness check reading that lookup would walk straight past it and
     * leave it in the table. Two undated rows would then sit on one template and store, and
     * switching the older one back on would put it in front of the one just published.
     *
     * @return void
     */
    public function testPublishingOverASwitchedOffUndatedOverrideLeavesExactlyOneRow(): void
    {
        $this->givenRow(7, TemplateOverrideInterface::STATUS_PUBLISHED, false);
        $this->givenRow(5, TemplateOverrideInterface::STATUS_DRAFT);

        $this->overrideRepository->expects(self::never())->method('getImmediatePublished');
        $this->logger->expects(self::never())->method('error');

        self::assertSame(5, $this->publisher->publish(5));
        self::assertSame([5], array_keys($this->undatedPublishedRows()));
    }

    public function testPublishingOverALiveUndatedOverrideStillReplacesIt(): void
    {
        $this->givenRow(7, TemplateOverrideInterface::STATUS_PUBLISHED);
        $this->givenRow(5, TemplateOverrideInterface::STATUS_DRAFT);

        $this->publisher->publish(5);

        self::assertSame([5], array_keys($this->undatedPublishedRows()));
    }

    public function testPublishingIntoAFreeSlotRemovesNothing(): void
    {
        $this->givenRow(5, TemplateOverrideInterface::STATUS_DRAFT);

        $this->overrideRepository->expects(self::never())->method('delete');

        $this->publisher->publish(5);

        self::assertSame([5], array_keys($this->undatedPublishedRows()));
    }

    public function testAnotherTemplatesSlotHolderIsLeftAlone(): void
    {
        $this->givenRow(7, TemplateOverrideInterface::STATUS_PUBLISHED, false, 'sales_email_invoice_template');
        $this->givenRow(5, TemplateOverrideInterface::STATUS_DRAFT);

        $this->publisher->publish(5);

        self::assertSame([7, 5], array_keys($this->rows), 'The slot is per template and store.');
    }

    /**
     * Removing a schedule is refused while a switched-off override holds the undated slot
     *
     * The mirror of the publish path: this is the other way a second undated row could appear.
     *
     * @return void
     */
    public function testUnschedulingIsRefusedWhileASwitchedOffOverrideHoldsTheSlot(): void
    {
        $this->givenRow(7, TemplateOverrideInterface::STATUS_PUBLISHED, false);
        $this->givenRow(9, TemplateOverrideInterface::STATUS_PUBLISHED, true, self::IDENTIFIER, '2026-06-01 00:00:00');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Another immediate published override already exists');

        $this->publisher->updateSchedule(9, null, null);
    }

    public function testUnschedulingIsAllowedIntoAFreeSlot(): void
    {
        $this->givenRow(9, TemplateOverrideInterface::STATUS_PUBLISHED, true, self::IDENTIFIER, '2026-06-01 00:00:00');

        $this->publisher->updateSchedule(9, null, null);

        self::assertSame([9], array_keys($this->undatedPublishedRows()));
    }

    /**
     * Add a row to the table and build the entity the code under test sees for it
     *
     * @param int $entityId
     * @param string $status
     * @param bool $isActive
     * @param string $identifier
     * @param string|null $activeFrom
     * @return void
     */
    private function givenRow(
        int $entityId,
        string $status,
        bool $isActive = true,
        string $identifier = self::IDENTIFIER,
        ?string $activeFrom = null
    ): void {
        $this->rows[$entityId] = [
            TemplateOverrideInterface::STATUS => $status,
            TemplateOverrideInterface::IS_ACTIVE => $isActive,
            TemplateOverrideInterface::TEMPLATE_IDENTIFIER => $identifier,
            TemplateOverrideInterface::STORE_ID => self::STORE_ID,
            TemplateOverrideInterface::ACTIVE_FROM => $activeFrom,
            TemplateOverrideInterface::ACTIVE_TO => null,
        ];

        $this->entities[$entityId] = $this->entityFor($entityId);
    }

    /**
     * Build the entity for one row, writing through to the stored columns
     *
     * @param int $entityId
     * @return TemplateOverrideInterface&MockObject
     */
    private function entityFor(int $entityId): TemplateOverrideInterface&MockObject
    {
        $entity = $this->createMock(TemplateOverrideInterface::class);
        $entity->method('getEntityId')->willReturn($entityId);

        foreach (
            [
                'getStatus' => TemplateOverrideInterface::STATUS,
                'getIsActive' => TemplateOverrideInterface::IS_ACTIVE,
                'getTemplateIdentifier' => TemplateOverrideInterface::TEMPLATE_IDENTIFIER,
                'getStoreId' => TemplateOverrideInterface::STORE_ID,
                'getActiveFrom' => TemplateOverrideInterface::ACTIVE_FROM,
                'getActiveTo' => TemplateOverrideInterface::ACTIVE_TO,
            ] as $getter => $column
        ) {
            $entity->method($getter)->willReturnCallback(
                fn () => $this->rows[$entityId][$column] ?? null
            );
        }

        foreach (
            [
                'setStatus' => TemplateOverrideInterface::STATUS,
                'setActiveFrom' => TemplateOverrideInterface::ACTIVE_FROM,
                'setActiveTo' => TemplateOverrideInterface::ACTIVE_TO,
            ] as $setter => $column
        ) {
            $entity->method($setter)->willReturnCallback(
                function ($value) use ($entityId, $column, $entity): TemplateOverrideInterface {
                    $this->rows[$entityId][$column] = $value;

                    return $entity;
                }
            );
        }

        return $entity;
    }

    /**
     * Answer the occupancy question against the table, as the repository does against the database
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    private function slotOccupant(string $identifier, int $storeId): ?TemplateOverrideInterface
    {
        foreach ($this->undatedPublishedRows() as $entityId => $row) {
            if ($row[TemplateOverrideInterface::TEMPLATE_IDENTIFIER] === $identifier
                && $row[TemplateOverrideInterface::STORE_ID] === $storeId
            ) {
                return $this->entities[$entityId];
            }
        }

        return null;
    }

    /**
     * The rows currently holding an undated slot, switched on or not
     *
     * @return array<int, array<string, mixed>>
     */
    private function undatedPublishedRows(): array
    {
        return array_filter(
            $this->rows,
            static fn (array $row): bool
                => $row[TemplateOverrideInterface::STATUS] === TemplateOverrideInterface::STATUS_PUBLISHED
                && $row[TemplateOverrideInterface::ACTIVE_FROM] === null
                && $row[TemplateOverrideInterface::ACTIVE_TO] === null
        );
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
