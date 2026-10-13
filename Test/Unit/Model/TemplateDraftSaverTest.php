<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface as Override;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterfaceFactory;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Model\TemplateDraftSaver;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\TemplateOverride as TemplateOverrideResource;
use Hryvinskyi\EmailTemplateEditor\Model\TemplateOverride;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

/**
 * What a draft save stores, and where.
 *
 * The overrides are the module's real model; only the table behind the repository is a list.
 */
class TemplateDraftSaverTest extends TestCase
{
    private const TEMPLATE = 'design_email_footer_template';
    private const STORE = 4;

    /**
     * Stored overrides by entity id
     *
     * @var array<int, Override>
     */
    private array $rows = [];

    /**
     * Saver under test
     *
     * @var TemplateDraftSaver
     */
    private TemplateDraftSaver $saver;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $repository = $this->createMock(TemplateOverrideRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(fn (int $id): Override => $this->rows[$id]);
        $repository->method('getDraft')->willReturnCallback(
            function (string $identifier, int $storeId): ?Override {
                foreach ($this->rows as $row) {
                    if ($row->getTemplateIdentifier() === $identifier
                        && $row->getStoreId() === $storeId
                        && $row->getStatus() === Override::STATUS_DRAFT
                    ) {
                        return $row;
                    }
                }

                return null;
            }
        );
        $repository->method('save')->willReturnCallback(
            function (Override $override): Override {
                self::assertInstanceOf(TemplateOverride::class, $override);
                $id = $override->getEntityId() ?? (max([0, ...array_keys($this->rows)]) + 1);
                $override->setData(Override::ENTITY_ID, $id);
                $this->rows[$id] = $override;

                return $override;
            }
        );

        $factory = $this->createMock(TemplateOverrideInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Override => $this->newRow());

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-13 10:00:00');

        $this->saver = new TemplateDraftSaver($repository, $factory, $dateTime);
    }

    /**
     * A field the save leaves out keeps what is stored; one sent empty clears it
     *
     * @return void
     */
    public function testAnAbsentFieldKeepsItsValueAndAnEmptyOneClearsIt(): void
    {
        $draft = $this->stored(7, Override::STATUS_DRAFT, ['tailwind_css' => '.border-t-3{}', 'theme_id' => 3]);
        $draft->setCustomCss('.old{}');

        $this->saver->save(
            self::TEMPLATE,
            self::STORE,
            '<p>new</p>',
            [Override::CUSTOM_CSS => ''],
            7,
            false,
            false,
            null
        );

        self::assertSame('.border-t-3{}', $this->rows[7]->getTailwindCss());
        self::assertSame(3, $this->rows[7]->getThemeId());
        self::assertNull($this->rows[7]->getCustomCss());
        self::assertSame('<p>new</p>', $this->rows[7]->getTemplateContent());
    }

    /**
     * An edit aimed at the published override goes to the working draft; the live row is untouched
     *
     * @return void
     */
    public function testAnEditAimedAtThePublishedOverrideIsForkedIntoTheWorkingDraft(): void
    {
        $this->stored(5, Override::STATUS_PUBLISHED, ['template_content' => '<p>live</p>']);
        $this->stored(9, Override::STATUS_DRAFT, ['template_content' => '<p>draft</p>']);

        $result = $this->saver->save(self::TEMPLATE, self::STORE, '<p>edit</p>', [], 5, false, false, null);

        self::assertSame(['entity_id' => 9, 'forked' => true], $result);
        self::assertSame('<p>live</p>', $this->rows[5]->getTemplateContent());
        self::assertSame('<p>edit</p>', $this->rows[9]->getTemplateContent());
    }

    /**
     * A new draft forked from the published override starts from its styles and schedule
     *
     * @return void
     */
    public function testANewDraftForkedFromThePublishedOverrideStartsWithItsFields(): void
    {
        $this->stored(5, Override::STATUS_PUBLISHED, [
            'template_content' => '<p>live</p>',
            'tailwind_css' => '.live{}',
            'theme_id' => 2,
            'active_from' => '2026-11-01 00:00:00',
        ]);

        $result = $this->saver->save(
            self::TEMPLATE,
            self::STORE,
            '<p>edit</p>',
            [Override::CUSTOM_CSS => '.mine{}'],
            5,
            false,
            false,
            null
        );

        $draft = $this->rows[$result['entity_id']];
        self::assertTrue($result['forked']);
        self::assertNotSame(5, $result['entity_id']);
        self::assertSame(Override::STATUS_DRAFT, $draft->getStatus());
        self::assertSame('.live{}', $draft->getTailwindCss());
        self::assertSame(2, $draft->getThemeId());
        self::assertSame('2026-11-01 00:00:00', $draft->getActiveFrom());
        self::assertSame('.mine{}', $draft->getCustomCss());
    }

    /**
     * Publishing the open override writes it in place, as the step before its publish
     *
     * @return void
     */
    public function testAPublishWritesThePublishedOverrideInPlace(): void
    {
        $this->stored(5, Override::STATUS_PUBLISHED, ['template_content' => '<p>live</p>']);

        $result = $this->saver->save(self::TEMPLATE, self::STORE, '<p>edit</p>', [], 5, false, true, null);

        self::assertSame(['entity_id' => 5, 'forked' => false], $result);
        self::assertSame('<p>edit</p>', $this->rows[5]->getTemplateContent());
    }

    /**
     * A scheduled override is not live, so it is edited where it is
     *
     * @return void
     */
    public function testAScheduledOverrideIsEditedInPlace(): void
    {
        $this->stored(6, Override::STATUS_SCHEDULED, ['template_content' => '<p>later</p>']);

        $result = $this->saver->save(self::TEMPLATE, self::STORE, '<p>edit</p>', [], 6, false, false, null);

        self::assertSame(['entity_id' => 6, 'forked' => false], $result);
    }

    /**
     * Without an override open, the save goes to the working draft, made if there is none
     *
     * @return void
     */
    public function testWithoutAnOpenOverrideTheWorkingDraftIsCreated(): void
    {
        $result = $this->saver->save(self::TEMPLATE, self::STORE, '<p>a</p>', [], null, false, false, null);

        $draft = $this->rows[$result['entity_id']];
        self::assertSame(self::TEMPLATE, $draft->getTemplateIdentifier());
        self::assertSame(self::STORE, $draft->getStoreId());
        self::assertSame(Override::STATUS_DRAFT, $draft->getStatus());
    }

    /**
     * Asking for a new draft never reuses the working one
     *
     * @return void
     */
    public function testForceNewStartsAnotherDraft(): void
    {
        $this->stored(9, Override::STATUS_DRAFT, ['template_content' => '<p>draft</p>']);

        $result = $this->saver->save(self::TEMPLATE, self::STORE, '<p>b</p>', [], 9, true, false, null);

        self::assertNotSame(9, $result['entity_id']);
        self::assertSame('<p>draft</p>', $this->rows[9]->getTemplateContent());
    }

    /**
     * A draft name is only ever given, never taken away by a save that sent none
     *
     * @return void
     */
    public function testADraftNameIsKeptWhenTheSaveSendsNoneOrAnEmptyOne(): void
    {
        $this->stored(9, Override::STATUS_DRAFT, ['draft_name' => 'Black Friday']);

        $this->saver->save(
            self::TEMPLATE,
            self::STORE,
            '<p>a</p>',
            [Override::DRAFT_NAME => ''],
            9,
            false,
            false,
            null
        );

        self::assertSame('Black Friday', $this->rows[9]->getDraftName());
    }

    /**
     * Choosing a provider other than custom drops the custom sample data
     *
     * @return void
     */
    public function testChoosingAnotherProviderDropsCustomSampleData(): void
    {
        $this->stored(9, Override::STATUS_DRAFT, ['sample_provider_code' => 'custom', 'custom_variables' => '{}']);

        $this->saver->save(
            self::TEMPLATE,
            self::STORE,
            '<p>a</p>',
            [Override::SAMPLE_PROVIDER_CODE => 'mock'],
            9,
            false,
            false,
            null
        );

        self::assertSame('mock', $this->rows[9]->getSampleProviderCode());
        self::assertNull($this->rows[9]->getCustomVariables());
    }

    /**
     * The creator is recorded once, the last editor every time
     *
     * @return void
     */
    public function testTheCreatorIsRecordedOnceAndTheEditorEveryTime(): void
    {
        $result = $this->saver->save(
            self::TEMPLATE,
            self::STORE,
            '<p>a</p>',
            [],
            null,
            false,
            false,
            ['id' => 1, 'name' => 'anna']
        );
        $this->saver->save(
            self::TEMPLATE,
            self::STORE,
            '<p>b</p>',
            [],
            $result['entity_id'],
            false,
            false,
            ['id' => 2, 'name' => 'ben']
        );

        $draft = $this->rows[$result['entity_id']];
        self::assertSame('anna', $draft->getCreatedByUsername());
        self::assertSame('ben', $draft->getLastEditedByUsername());
        self::assertSame('2026-10-13 10:00:00', $draft->getLastEditedAt());
    }

    /**
     * Store a row with the given status and fields
     *
     * @param int $id
     * @param string $status
     * @param array<string, mixed> $data
     * @return Override
     */
    private function stored(int $id, string $status, array $data): Override
    {
        $row = $this->newRow();
        $row->addData($data + [
            Override::ENTITY_ID => $id,
            Override::TEMPLATE_IDENTIFIER => self::TEMPLATE,
            Override::STORE_ID => self::STORE,
            Override::STATUS => $status,
        ]);
        $this->rows[$id] = $row;

        return $row;
    }

    /**
     * A new instance of the module's override model
     *
     * @return TemplateOverride
     */
    private function newRow(): TemplateOverride
    {
        $resource = $this->createMock(TemplateOverrideResource::class);
        $resource->method('getIdFieldName')->willReturn(Override::ENTITY_ID);

        return new TemplateOverride($this->createMock(Context::class), $this->createMock(Registry::class), $resource);
    }
}
