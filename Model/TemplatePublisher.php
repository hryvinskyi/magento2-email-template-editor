<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterfaceFactory;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateVersionInterfaceFactory;
use Hryvinskyi\EmailTemplateEditor\Api\ScheduleConflictDetectorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplatePublisherInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVersionRepositoryInterface;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class TemplatePublisher implements TemplatePublisherInterface
{
    /**
     * @param TemplateOverrideRepositoryInterface $overrideRepository
     * @param TemplateVersionRepositoryInterface $versionRepository
     * @param TemplateVersionInterfaceFactory $versionFactory
     * @param TemplateOverrideInterfaceFactory $overrideFactory
     * @param ScheduleConflictDetectorInterface $scheduleConflictDetector
     * @param AuthSession $authSession
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TemplateOverrideRepositoryInterface $overrideRepository,
        private readonly TemplateVersionRepositoryInterface $versionRepository,
        private readonly TemplateVersionInterfaceFactory $versionFactory,
        private readonly TemplateOverrideInterfaceFactory $overrideFactory,
        private readonly ScheduleConflictDetectorInterface $scheduleConflictDetector,
        private readonly AuthSession $authSession,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function publish(int $overrideId, ?string $versionComment = null): int
    {
        $draft = $this->overrideRepository->getById($overrideId);

        $this->validatePublishableStatus($draft);

        $activeFrom = $draft->getActiveFrom();
        $activeTo = $draft->getActiveTo();
        $isImmediate = empty($activeFrom) && empty($activeTo);

        if ($isImmediate) {
            $this->handleImmediatePublish($draft);
        } else {
            $this->handleScheduledPublish($draft, $activeFrom, $activeTo);
        }

        $this->createVersionRecord($draft, $versionComment);

        $draft->setStatus(TemplateOverrideInterface::STATUS_PUBLISHED);
        $draft->setVersionComment($versionComment);
        $this->setEditorInfo($draft);

        $published = $this->overrideRepository->save($draft);

        return (int)$published->getEntityId();
    }

    /**
     * {@inheritDoc}
     */
    public function schedulePublish(
        int $overrideId,
        string $scheduledAt,
        ?string $versionComment = null,
        ?string $activeFrom = null,
        ?string $activeTo = null
    ): void {
        $override = $this->overrideRepository->getById($overrideId);

        $identifier = $override->getTemplateIdentifier();
        $storeId = $override->getStoreId();

        $conflicts = $this->scheduleConflictDetector->detect(
            $identifier,
            $storeId,
            $activeFrom,
            $activeTo,
            $override->getEntityId()
        );

        if (!empty($conflicts)) {
            $conflictNames = array_map(
                static fn(array $c): string => $c['draft_name'] ?? ('Override #' . $c['entity_id']),
                $conflicts
            );

            throw new LocalizedException(
                __('Schedule conflicts detected with: %1', implode(', ', $conflictNames))
            );
        }

        $override->setStatus(TemplateOverrideInterface::STATUS_SCHEDULED);
        $override->setScheduledAt($scheduledAt);
        $override->setVersionComment($versionComment);
        $override->setActiveFrom($activeFrom);
        $override->setActiveTo($activeTo);
        $this->setEditorInfo($override);

        $this->overrideRepository->save($override);
    }

    /**
     * Validate that the override is in a publishable status
     *
     * Draft and scheduled overrides are promoted to published. An already published
     * override may be re-published as well: this re-saves its current content and
     * creates a new version snapshot, allowing edits made directly on a published
     * template to be published without first forking a draft.
     *
     * @param TemplateOverrideInterface $override
     * @return void
     * @throws LocalizedException
     */
    private function validatePublishableStatus(TemplateOverrideInterface $override): void
    {
        $allowedStatuses = [
            TemplateOverrideInterface::STATUS_DRAFT,
            TemplateOverrideInterface::STATUS_SCHEDULED,
            TemplateOverrideInterface::STATUS_PUBLISHED,
        ];

        if (!in_array($override->getStatus(), $allowedStatuses, true)) {
            throw new LocalizedException(
                __(
                    'Only draft, scheduled, or published overrides can be published. Current status: %1',
                    $override->getStatus()
                )
            );
        }
    }

    /**
     * Replace existing immediate published override for the same template and store
     *
     * @param TemplateOverrideInterface $draft
     * @return void
     * @throws LocalizedException
     */
    private function handleImmediatePublish(TemplateOverrideInterface $draft): void
    {
        $existing = $this->overrideRepository->getImmediatePublished(
            $draft->getTemplateIdentifier(),
            $draft->getStoreId()
        );

        if ($existing !== null && (int)$existing->getEntityId() !== (int)$draft->getEntityId()) {
            $this->overrideRepository->delete($existing);
        }
    }

    /**
     * Validate date ordering and detect schedule conflicts before publishing
     *
     * @param TemplateOverrideInterface $draft
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @return void
     * @throws LocalizedException
     */
    private function handleScheduledPublish(
        TemplateOverrideInterface $draft,
        ?string $activeFrom,
        ?string $activeTo
    ): void {
        if ($activeFrom !== null && $activeTo !== null && strtotime($activeFrom) >= strtotime($activeTo)) {
            throw new LocalizedException(__('Active From must be before Active To.'));
        }

        $conflicts = $this->scheduleConflictDetector->detect(
            $draft->getTemplateIdentifier(),
            $draft->getStoreId(),
            $activeFrom,
            $activeTo,
            (int)$draft->getEntityId()
        );

        if (!empty($conflicts)) {
            $conflictNames = array_map(
                static fn(array $c): string => $c['draft_name'] ?? ('Override #' . $c['entity_id']),
                $conflicts
            );

            throw new LocalizedException(
                __('Schedule conflicts detected with: %1', implode(', ', $conflictNames))
            );
        }
    }

    /**
     * Copy all content fields from one override to another
     *
     * @param TemplateOverrideInterface $source
     * @param TemplateOverrideInterface $target
     * @return void
     */
    private function copyContentFields(
        TemplateOverrideInterface $source,
        TemplateOverrideInterface $target
    ): void {
        $target->setTemplateContent($source->getTemplateContent() ?? '');
        $target->setTemplateSubject($source->getTemplateSubject());
        $target->setCustomCss($source->getCustomCss());
        $target->setTailwindCss($source->getTailwindCss());
        $target->setThemeId($source->getThemeId());
        $target->setActiveFrom($source->getActiveFrom());
        $target->setActiveTo($source->getActiveTo());
        $target->setDraftName($source->getDraftName());
    }

    /**
     * Create a version snapshot record for the published template
     *
     * @param TemplateOverrideInterface $override
     * @param string|null $versionComment
     * @return void
     */
    private function createVersionRecord(TemplateOverrideInterface $override, ?string $versionComment): void
    {
        try {
            $identifier = $override->getTemplateIdentifier();
            $storeId = $override->getStoreId();
            $nextVersion = $this->versionRepository->getNextVersionNumber($identifier, $storeId);

            $adminUser = $this->authSession->getUser();

            $version = $this->versionFactory->create();
            $version->setTemplateIdentifier($identifier);
            $version->setVersionNumber($nextVersion);
            $version->setTemplateContent($override->getTemplateContent() ?? '');
            $version->setTemplateSubject($override->getTemplateSubject());
            $version->setCustomCss($override->getCustomCss());
            $version->setTailwindCss($override->getTailwindCss());
            $version->setThemeId($override->getThemeId());
            $version->setStoreId($storeId);
            $version->setVersionComment($versionComment);
            $version->setAdminUserId($adminUser ? (int)$adminUser->getId() : null);
            $version->setAdminUsername($adminUser ? $adminUser->getUserName() : null);
            $version->setPublishedAt($this->dateTime->gmtDate());

            $this->versionRepository->save($version);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to create version record for template "' . $override->getTemplateIdentifier() . '": '
                . $e->getMessage()
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updateSchedule(int $overrideId, ?string $activeFrom, ?string $activeTo): void
    {
        $override = $this->overrideRepository->getById($overrideId);

        if ($override->getStatus() !== TemplateOverrideInterface::STATUS_PUBLISHED) {
            throw new LocalizedException(
                __('Only published overrides can have their schedule updated directly.')
            );
        }

        if ($activeFrom !== null && $activeTo !== null && strtotime($activeFrom) >= strtotime($activeTo)) {
            throw new LocalizedException(__('Active From must be before Active To.'));
        }

        $isBecomingImmediate = empty($activeFrom) && empty($activeTo);
        $identifier = $override->getTemplateIdentifier();
        $storeId = $override->getStoreId();

        if ($isBecomingImmediate) {
            $existing = $this->overrideRepository->getImmediatePublished($identifier, $storeId);

            if ($existing !== null && (int)$existing->getEntityId() !== (int)$override->getEntityId()) {
                throw new LocalizedException(
                    __(
                        'Another immediate published override already exists for this template and store ("%1"). '
                        . 'Delete it first before removing the schedule from this override.',
                        $existing->getDraftName() ?? ('Override #' . $existing->getEntityId())
                    )
                );
            }
        } else {
            $conflicts = $this->scheduleConflictDetector->detect(
                $identifier,
                $storeId,
                $activeFrom,
                $activeTo,
                (int)$override->getEntityId()
            );

            if (!empty($conflicts)) {
                $conflictNames = array_map(
                    static fn(array $c): string => $c['draft_name'] ?? ('Override #' . $c['entity_id']),
                    $conflicts
                );

                throw new LocalizedException(
                    __('Schedule conflicts detected with: %1', implode(', ', $conflictNames))
                );
            }
        }

        $override->setActiveFrom($activeFrom);
        $override->setActiveTo($activeTo);
        $this->setEditorInfo($override);

        $this->overrideRepository->save($override);
    }

    /**
     * Set the current admin user info as the last editor on an override
     *
     * @param TemplateOverrideInterface $override
     * @return void
     */
    private function setEditorInfo(TemplateOverrideInterface $override): void
    {
        $adminUser = $this->authSession->getUser();

        if ($adminUser !== null) {
            $override->setLastEditedByUserId((int)$adminUser->getId());
            $override->setLastEditedByUsername($adminUser->getUserName());
        }

        $override->setLastEditedAt($this->dateTime->gmtDate());
    }
}
