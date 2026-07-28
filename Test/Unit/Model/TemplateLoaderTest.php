<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Api\LegacyTemplateRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateAreaResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Model\TemplateLoader;
use Magento\Email\Model\BackendTemplate;
use Magento\Email\Model\Template;
use Magento\Email\Model\Template\Config as EmailConfig;
use Magento\Email\Model\TemplateFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TemplateLoaderTest extends TestCase
{
    private const TEMPLATE_ID = 'sales_email_order_template';
    private const OTHER_TEMPLATE_ID = 'customer_password_reset_password_template';

    private EmailConfig&MockObject $emailConfig;
    private TemplateOverrideRepositoryInterface&MockObject $overrideRepository;
    private TemplateAreaResolverInterface&MockObject $areaResolver;
    private MockObject $templateFactory;
    private LoggerInterface&MockObject $logger;
    private PluginBypassFlagInterface&MockObject $pluginBypassFlag;
    private LegacyTemplateRepositoryInterface&MockObject $legacyRepository;
    private TemplateLoader $loader;

    protected function setUp(): void
    {
        $this->emailConfig = $this->createMock(EmailConfig::class);
        $this->overrideRepository = $this->createMock(TemplateOverrideRepositoryInterface::class);
        $this->areaResolver = $this->createMock(TemplateAreaResolverInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->pluginBypassFlag = $this->createMock(PluginBypassFlagInterface::class);
        $this->legacyRepository = $this->createMock(LegacyTemplateRepositoryInterface::class);

        $this->templateFactory = $this->mockFactory(TemplateFactory::class);

        $this->areaResolver->method('resolve')->willReturn('frontend');
        $this->emailConfig->method('parseTemplateIdParts')
            ->willReturnCallback(static fn (string $id): array => ['templateId' => $id, 'theme' => null]);
        $this->emailConfig->method('getTemplateFilename')->willReturn('');

        $this->loader = new TemplateLoader(
            $this->emailConfig,
            $this->overrideRepository,
            $this->areaResolver,
            $this->templateFactory,
            $this->createMock(Filesystem::class),
            $this->createMock(ReadFactory::class),
            $this->logger,
            $this->pluginBypassFlag,
            $this->createMock(Emulation::class),
            $this->legacyRepository
        );
    }

    public function testOverridesAreOrderedByStatusThenScopeThenRowRegardlessOfFetchOrder(): void
    {
        $this->emailConfig->method('getAvailableTemplates')->willReturn($this->availableTemplates());

        // Deliberately shuffled: the batched fetch makes no ordering promise at all.
        $rows = [
            $this->override(10, 0, TemplateOverrideInterface::STATUS_DRAFT),
            $this->override(3, 5, TemplateOverrideInterface::STATUS_SCHEDULED, '2026-08-02 00:00:00'),
            $this->override(99, 0, TemplateOverrideInterface::STATUS_PUBLISHED),
            $this->override(8, 5, TemplateOverrideInterface::STATUS_SCHEDULED, null),
            $this->override(50, 5, TemplateOverrideInterface::STATUS_PUBLISHED),
            $this->override(7, 0, TemplateOverrideInterface::STATUS_SCHEDULED, '2026-01-01 00:00:00'),
            $this->override(1, 5, TemplateOverrideInterface::STATUS_DRAFT),
            $this->override(2, 0, TemplateOverrideInterface::STATUS_SCHEDULED, '2026-01-01 00:00:00'),
            $this->override(4, 5, TemplateOverrideInterface::STATUS_PUBLISHED),
        ];

        $this->overrideRepository->method('getOverridesForIdentifiers')
            ->willReturn([self::TEMPLATE_ID => $rows]);
        $this->legacyRepository->method('getByOrigCodes')->willReturn([]);

        $overrides = $this->overridesOf($this->loader->loadTemplateList(5), self::TEMPLATE_ID);

        self::assertSame(
            [4, 50, 99, 8, 3, 2, 7, 1, 10],
            array_column($overrides, 'entity_id'),
            'Sidebar order must stay: published, then scheduled, then draft; the selected store '
            . 'before "All Store Views" inside each; ascending entity id inside a scope, except '
            . 'scheduled rows which go by schedule date with undated ones first.'
        );
    }

    public function testUndatedScheduledOverrideSortsBeforeADatedOne(): void
    {
        $this->emailConfig->method('getAvailableTemplates')->willReturn($this->availableTemplates());
        $this->overrideRepository->method('getOverridesForIdentifiers')->willReturn([
            self::TEMPLATE_ID => [
                $this->override(1, 0, TemplateOverrideInterface::STATUS_SCHEDULED, '2026-01-01 00:00:00'),
                $this->override(2, 0, TemplateOverrideInterface::STATUS_SCHEDULED, null),
            ],
        ]);
        $this->legacyRepository->method('getByOrigCodes')->willReturn([]);

        $overrides = $this->overridesOf($this->loader->loadTemplateList(0), self::TEMPLATE_ID);

        self::assertSame([2, 1], array_column($overrides, 'entity_id'));
    }

    public function testStoreZeroIsTheOnlyScopeWhenNoStoreViewIsSelected(): void
    {
        $this->emailConfig->method('getAvailableTemplates')->willReturn($this->availableTemplates());
        $this->overrideRepository->expects(self::once())
            ->method('getOverridesForIdentifiers')
            ->with(self::anything(), [0], self::anything(), self::anything())
            ->willReturn([]);
        $this->legacyRepository->method('getByOrigCodes')->willReturn([]);

        $this->loader->loadTemplateList(0);
    }

    public function testTheWholeTemplateListIsLookedUpInOneCall(): void
    {
        $this->emailConfig->method('getAvailableTemplates')->willReturn($this->availableTemplates());

        $this->overrideRepository->expects(self::once())
            ->method('getOverridesForIdentifiers')
            ->with([self::TEMPLATE_ID, self::OTHER_TEMPLATE_ID], [5, 0])
            ->willReturn([]);
        $this->overrideRepository->expects(self::never())->method('getPublishedList');
        $this->overrideRepository->expects(self::never())->method('getScheduledOverrides');
        $this->overrideRepository->expects(self::never())->method('getDrafts');

        $this->legacyRepository->expects(self::once())
            ->method('getByOrigCodes')
            ->with([self::TEMPLATE_ID, self::OTHER_TEMPLATE_ID])
            ->willReturn([]);
        $this->legacyRepository->expects(self::never())->method('getByOrigCode');

        $this->loader->loadTemplateList(5);
    }

    public function testTheLookupSelectsTheSummaryFieldsAndNoneOfTheLargeColumns(): void
    {
        $this->emailConfig->method('getAvailableTemplates')->willReturn($this->availableTemplates());
        $this->legacyRepository->method('getByOrigCodes')->willReturn([]);

        $requestedFields = null;
        $this->overrideRepository->method('getOverridesForIdentifiers')
            ->willReturnCallback(
                static function (array $ids, array $stores, array $statuses, array $fields) use (&$requestedFields) {
                    $requestedFields = $fields;

                    return [];
                }
            );

        $this->loader->loadTemplateList(0);

        self::assertIsArray($requestedFields);
        self::assertNotEmpty($requestedFields, 'An unrestricted select drags three MEDIUMTEXT columns.');

        foreach (
            [
                TemplateOverrideInterface::ENTITY_ID,
                TemplateOverrideInterface::STORE_ID,
                TemplateOverrideInterface::STATUS,
                TemplateOverrideInterface::DRAFT_NAME,
                TemplateOverrideInterface::VERSION_COMMENT,
                TemplateOverrideInterface::SCHEDULED_AT,
                TemplateOverrideInterface::ACTIVE_FROM,
                TemplateOverrideInterface::ACTIVE_TO,
                TemplateOverrideInterface::CREATED_BY_USERNAME,
                TemplateOverrideInterface::LAST_EDITED_BY_USERNAME,
                TemplateOverrideInterface::CREATED_AT,
                TemplateOverrideInterface::UPDATED_AT,
                TemplateOverrideInterface::IS_ACTIVE,
            ] as $field
        ) {
            self::assertContains($field, $requestedFields, 'The sidebar summary reads ' . $field . '.');
        }

        foreach (
            [
                TemplateOverrideInterface::TEMPLATE_CONTENT,
                TemplateOverrideInterface::CUSTOM_CSS,
                TemplateOverrideInterface::TAILWIND_CSS,
            ] as $field
        ) {
            self::assertNotContains($field, $requestedFields, 'The sidebar summary never reads ' . $field . '.');
        }
    }

    public function testAFailedOverrideLookupIsLoggedAndStillYieldsEveryTemplate(): void
    {
        $this->emailConfig->method('getAvailableTemplates')->willReturn($this->availableTemplates());
        $this->overrideRepository->method('getOverridesForIdentifiers')
            ->willThrowException(new \RuntimeException('connection lost'));
        $this->legacyRepository->method('getByOrigCodes')->willReturn([]);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('connection lost'));
        $this->logger->expects(self::never())->method('error');

        $grouped = $this->loader->loadTemplateList(5);

        // Groups are sorted by name, so Customer precedes Sales.
        self::assertSame(
            [self::OTHER_TEMPLATE_ID, self::TEMPLATE_ID],
            array_column(array_merge(...array_values($grouped)), 'id'),
            'An override lookup failure must cost the overrides, not the whole sidebar.'
        );
        self::assertSame([], $this->overridesOf($grouped, self::TEMPLATE_ID));
        self::assertSame([], $this->overridesOf($grouped, self::OTHER_TEMPLATE_ID));
    }

    public function testLegacyRowsComeFromTheBatchedLookupAndAreNotReloadedPerRow(): void
    {
        $this->emailConfig->method('getAvailableTemplates')->willReturn($this->availableTemplates());
        $this->overrideRepository->method('getOverridesForIdentifiers')->willReturn([]);

        // template_code and the timestamps are column reads served by __call, not declared
        // methods, so they have to be declared onto the mock rather than merely stubbed.
        $legacyRow = $this->getMockBuilder(BackendTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->addMethods(['getTemplateCode', 'getAddedAt', 'getModifiedAt'])
            ->getMock();
        $legacyRow->method('getId')->willReturn(42);
        $legacyRow->method('getTemplateCode')->willReturn('Order Confirmation (legacy)');
        $legacyRow->method('getAddedAt')->willReturn('2026-02-01 09:00:00');
        $legacyRow->method('getModifiedAt')->willReturn('2026-03-01 09:00:00');

        $this->legacyRepository->method('getByOrigCodes')
            ->willReturn([self::TEMPLATE_ID => [$legacyRow]]);
        $this->legacyRepository->expects(self::once())
            ->method('getScopeBindingsForTemplate')
            ->with($legacyRow)
            ->willReturn([3]);
        $this->legacyRepository->expects(self::never())->method('getScopeBindings');

        $overrides = $this->overridesOf($this->loader->loadTemplateList(0), self::TEMPLATE_ID);

        self::assertCount(1, $overrides);
        self::assertSame('legacy', $overrides[0]['source']);
        self::assertSame(42, $overrides[0]['legacy_id']);
        self::assertSame([3], $overrides[0]['store_ids']);
        self::assertSame('Order Confirmation (legacy)', $overrides[0]['label']);
        self::assertSame('2026-03-01 09:00:00', $overrides[0]['updated_at']);
    }

    public function testTheTemplateListIsReadOnceAndItsLabelsAreReusedForSingleTemplateLoads(): void
    {
        $this->emailConfig->expects(self::once())
            ->method('getAvailableTemplates')
            ->willReturn($this->availableTemplates());
        $this->overrideRepository->method('getOverridesForIdentifiers')->willReturn([]);
        $this->legacyRepository->method('getByOrigCodes')->willReturn([]);
        $this->templateFactory->method('create')->willReturn($this->createMock(Template::class));

        $this->loader->loadTemplateList(0);

        self::assertSame(
            'New Order',
            $this->loader->loadTemplate(self::TEMPLATE_ID, 0, null, true)['label']
        );
        self::assertSame(
            'Reset Password',
            $this->loader->loadTemplate(self::OTHER_TEMPLATE_ID, 0, null, true)['label']
        );
    }

    public function testAnUnknownIdentifierLabelsAsItself(): void
    {
        $this->emailConfig->method('getAvailableTemplates')->willReturn($this->availableTemplates());
        $this->templateFactory->method('create')->willReturn($this->createMock(Template::class));

        self::assertSame(
            'not_a_registered_template',
            $this->loader->loadTemplate('not_a_registered_template', 0, null, true)['label']
        );
    }

    public function testResetStateDropsTheMemoisedTemplateList(): void
    {
        $this->emailConfig->expects(self::exactly(2))
            ->method('getAvailableTemplates')
            ->willReturn($this->availableTemplates());
        $this->overrideRepository->method('getOverridesForIdentifiers')->willReturn([]);
        $this->legacyRepository->method('getByOrigCodes')->willReturn([]);

        $this->loader->loadTemplateList(0);
        $this->loader->_resetState();
        $this->loader->loadTemplateList(0);
    }

    /**
     * Mock a DI-compiler-generated factory whether or not it has been generated yet
     *
     * The root package autoloads generated/code, so a factory that has already been compiled is a
     * real class here and its create() must be stubbed with onlyMethods(); one that has not been
     * compiled does not exist at all and only addMethods() can declare it. Both states occur on a
     * working checkout, and a clean recompile flips them, so the branch is picked at runtime.
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

    /**
     * The two-entry template list every test runs against
     *
     * @return array<int, array{value: string, label: string, group: string}>
     */
    private function availableTemplates(): array
    {
        return [
            ['value' => self::TEMPLATE_ID, 'label' => 'New Order', 'group' => 'Sales'],
            ['value' => self::OTHER_TEMPLATE_ID, 'label' => 'Reset Password', 'group' => 'Customer'],
        ];
    }

    /**
     * Build an override row carrying only what the sidebar order depends on
     *
     * @param int $entityId
     * @param int $storeId
     * @param string $status
     * @param string|null $scheduledAt
     * @return TemplateOverrideInterface&MockObject
     */
    private function override(
        int $entityId,
        int $storeId,
        string $status,
        ?string $scheduledAt = null
    ): TemplateOverrideInterface&MockObject {
        $override = $this->createMock(TemplateOverrideInterface::class);
        $override->method('getEntityId')->willReturn($entityId);
        $override->method('getStoreId')->willReturn($storeId);
        $override->method('getStatus')->willReturn($status);
        $override->method('getScheduledAt')->willReturn($scheduledAt);

        return $override;
    }

    /**
     * Pull one template's override children out of the grouped sidebar payload
     *
     * @param array<string, array<int, array<string, mixed>>> $grouped
     * @param string $identifier
     * @return array<int, array<string, mixed>>
     */
    private function overridesOf(array $grouped, string $identifier): array
    {
        foreach ($grouped as $entries) {
            foreach ($entries as $entry) {
                if ($entry['id'] === $identifier) {
                    return $entry['overrides'];
                }
            }
        }

        self::fail('Template "' . $identifier . '" is missing from the sidebar payload.');
    }
}
