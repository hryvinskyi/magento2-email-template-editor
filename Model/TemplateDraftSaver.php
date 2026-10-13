<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface as Override;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterfaceFactory;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateDraftSaverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

class TemplateDraftSaver implements TemplateDraftSaverInterface
{
    /**
     * Fields a draft takes over from the published override it is forked from
     */
    private const FORKED_FIELDS = [
        Override::TEMPLATE_SUBJECT,
        Override::CUSTOM_CSS,
        Override::TAILWIND_CSS,
        Override::THEME_ID,
        Override::ACTIVE_FROM,
        Override::ACTIVE_TO,
        Override::SAMPLE_PROVIDER_CODE,
        Override::CUSTOM_VARIABLES,
    ];

    /**
     * @param TemplateOverrideRepositoryInterface $overrideRepository
     * @param TemplateOverrideInterfaceFactory $overrideFactory
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly TemplateOverrideRepositoryInterface $overrideRepository,
        private readonly TemplateOverrideInterfaceFactory $overrideFactory,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(
        string $templateIdentifier,
        int $storeId,
        string $templateContent,
        array $fields,
        ?int $entityId,
        bool $forceNew,
        bool $publishIntent,
        ?array $author
    ): array {
        $forked = false;

        if ($forceNew) {
            $draft = $this->newDraft($templateIdentifier, $storeId);
        } elseif ($entityId !== null) {
            $draft = $this->overrideRepository->getById($entityId);

            if ($draft->getStatus() === Override::STATUS_PUBLISHED && !$publishIntent) {
                $published = $draft;
                $draft = $this->workingDraft(
                    $published->getTemplateIdentifier() ?? $templateIdentifier,
                    $published->getStoreId()
                );
                $forked = true;

                if ($draft->getEntityId() === null) {
                    $fields += $this->snapshot($published);
                }
            }
        } else {
            $draft = $this->workingDraft($templateIdentifier, $storeId);
        }

        $isNew = $draft->getEntityId() === null;

        $draft->setTemplateContent($templateContent);
        $this->applyFields($draft, $fields);
        $this->stampAuthor($draft, $author, $isNew);

        $saved = $this->overrideRepository->save($draft);

        return ['entity_id' => (int)$saved->getEntityId(), 'forked' => $forked];
    }

    /**
     * The template's working draft, or a new one when it has none
     *
     * @param string $templateIdentifier
     * @param int $storeId
     * @return Override
     */
    private function workingDraft(string $templateIdentifier, int $storeId): Override
    {
        return $this->overrideRepository->getDraft($templateIdentifier, $storeId)
            ?? $this->newDraft($templateIdentifier, $storeId);
    }

    /**
     * A new, unsaved draft of a template
     *
     * @param string $templateIdentifier
     * @param int $storeId
     * @return Override
     */
    private function newDraft(string $templateIdentifier, int $storeId): Override
    {
        $draft = $this->overrideFactory->create();
        $draft->setTemplateIdentifier($templateIdentifier);
        $draft->setStoreId($storeId);
        $draft->setStatus(Override::STATUS_DRAFT);

        return $draft;
    }

    /**
     * The fields of a published override a new draft forked from it starts with
     *
     * @param Override $published
     * @return array<string,string>
     */
    private function snapshot(Override $published): array
    {
        $values = [
            Override::TEMPLATE_SUBJECT => $published->getTemplateSubject(),
            Override::CUSTOM_CSS => $published->getCustomCss(),
            Override::TAILWIND_CSS => $published->getTailwindCss(),
            Override::THEME_ID => $published->getThemeId(),
            Override::ACTIVE_FROM => $published->getActiveFrom(),
            Override::ACTIVE_TO => $published->getActiveTo(),
            Override::SAMPLE_PROVIDER_CODE => $published->getSampleProviderCode(),
            Override::CUSTOM_VARIABLES => $published->getCustomVariables(),
        ];

        $snapshot = [];

        foreach (self::FORKED_FIELDS as $field) {
            $snapshot[$field] = (string)$values[$field];
        }

        return $snapshot;
    }

    /**
     * Write every field the request carried; leave the others as they are
     *
     * @param Override $draft
     * @param array<string,string> $fields
     * @return void
     */
    private function applyFields(Override $draft, array $fields): void
    {
        $writers = [
            Override::TEMPLATE_SUBJECT => static fn (?string $v) => $draft->setTemplateSubject($v),
            Override::CUSTOM_CSS => static fn (?string $v) => $draft->setCustomCss($v),
            Override::TAILWIND_CSS => static fn (?string $v) => $draft->setTailwindCss($v),
            Override::THEME_ID => static fn (?string $v) => $draft->setThemeId($v === null ? null : (int)$v),
            Override::ACTIVE_FROM => static fn (?string $v) => $draft->setActiveFrom($v),
            Override::ACTIVE_TO => static fn (?string $v) => $draft->setActiveTo($v),
            Override::SAMPLE_PROVIDER_CODE => static fn (?string $v) => $draft->setSampleProviderCode($v),
            Override::CUSTOM_VARIABLES => static fn (?string $v) => $draft->setCustomVariables($v),
        ];

        foreach ($writers as $field => $write) {
            if (array_key_exists($field, $fields)) {
                $write($fields[$field] !== '' ? $fields[$field] : null);
            }
        }

        // A name is only ever given, never taken away by a save that did not mention it: autosave
        // and publish send none, and an empty one would leave a named draft shown as "Untitled".
        if (($fields[Override::DRAFT_NAME] ?? '') !== '') {
            $draft->setDraftName($fields[Override::DRAFT_NAME]);
        }

        // Custom sample data only means something to the custom provider, so choosing another
        // provider drops it rather than leaving stale JSON behind.
        if (array_key_exists(Override::SAMPLE_PROVIDER_CODE, $fields)
            && $fields[Override::SAMPLE_PROVIDER_CODE] !== 'custom'
        ) {
            $draft->setCustomVariables(null);
        }
    }

    /**
     * Record who created and who last edited the draft, and when
     *
     * @param Override $draft
     * @param array{id:int,name:string}|null $author
     * @param bool $isNew
     * @return void
     */
    private function stampAuthor(Override $draft, ?array $author, bool $isNew): void
    {
        if ($author !== null) {
            if ($isNew) {
                $draft->setCreatedByUserId($author['id']);
                $draft->setCreatedByUsername($author['name']);
            }

            $draft->setLastEditedByUserId($author['id']);
            $draft->setLastEditedByUsername($author['name']);
        }

        $draft->setLastEditedAt($this->dateTime->gmtDate('Y-m-d H:i:s'));
    }
}
