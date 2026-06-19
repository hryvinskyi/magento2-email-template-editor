<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\TemplateOverride as TemplateOverrideResource;
use Magento\Framework\Model\AbstractModel;

class TemplateOverride extends AbstractModel implements TemplateOverrideInterface
{
    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(TemplateOverrideResource::class);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityId(): ?int
    {
        $id = $this->getData(self::ENTITY_ID);

        return $id !== null ? (int)$id : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getTemplateIdentifier(): ?string
    {
        return $this->getData(self::TEMPLATE_IDENTIFIER);
    }

    /**
     * {@inheritDoc}
     */
    public function setTemplateIdentifier(string $identifier): self
    {
        return $this->setData(self::TEMPLATE_IDENTIFIER, $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getTemplateContent(): ?string
    {
        return $this->getData(self::TEMPLATE_CONTENT);
    }

    /**
     * {@inheritDoc}
     */
    public function setTemplateContent(string $content): self
    {
        return $this->setData(self::TEMPLATE_CONTENT, $content);
    }

    /**
     * {@inheritDoc}
     */
    public function getTemplateSubject(): ?string
    {
        return $this->getData(self::TEMPLATE_SUBJECT);
    }

    /**
     * {@inheritDoc}
     */
    public function setTemplateSubject(?string $subject): self
    {
        return $this->setData(self::TEMPLATE_SUBJECT, $subject);
    }

    /**
     * {@inheritDoc}
     */
    public function getCustomCss(): ?string
    {
        return $this->getData(self::CUSTOM_CSS);
    }

    /**
     * {@inheritDoc}
     */
    public function setCustomCss(?string $customCss): self
    {
        return $this->setData(self::CUSTOM_CSS, $customCss);
    }

    /**
     * {@inheritDoc}
     */
    public function getTailwindCss(): ?string
    {
        return $this->getData(self::TAILWIND_CSS);
    }

    /**
     * {@inheritDoc}
     */
    public function setTailwindCss(?string $tailwindCss): self
    {
        return $this->setData(self::TAILWIND_CSS, $tailwindCss);
    }

    /**
     * {@inheritDoc}
     */
    public function getThemeId(): ?int
    {
        $id = $this->getData(self::THEME_ID);

        return $id !== null ? (int)$id : null;
    }

    /**
     * {@inheritDoc}
     */
    public function setThemeId(?int $themeId): self
    {
        return $this->setData(self::THEME_ID, $themeId);
    }

    /**
     * {@inheritDoc}
     */
    public function getStoreId(): int
    {
        return (int)$this->getData(self::STORE_ID);
    }

    /**
     * {@inheritDoc}
     */
    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus(): string
    {
        return (string)($this->getData(self::STATUS) ?: self::STATUS_DRAFT);
    }

    /**
     * {@inheritDoc}
     */
    public function setStatus(string $status): self
    {
        return $this->setData(self::STATUS, $status);
    }

    /**
     * {@inheritDoc}
     */
    public function getScheduledAt(): ?string
    {
        return $this->getData(self::SCHEDULED_AT);
    }

    /**
     * {@inheritDoc}
     */
    public function setScheduledAt(?string $scheduledAt): self
    {
        return $this->setData(self::SCHEDULED_AT, $scheduledAt);
    }

    /**
     * {@inheritDoc}
     */
    public function getVersionComment(): ?string
    {
        return $this->getData(self::VERSION_COMMENT);
    }

    /**
     * {@inheritDoc}
     */
    public function setVersionComment(?string $versionComment): self
    {
        return $this->setData(self::VERSION_COMMENT, $versionComment);
    }

    /**
     * {@inheritDoc}
     */
    public function getCreatedByUserId(): ?int
    {
        $id = $this->getData(self::CREATED_BY_USER_ID);

        return $id !== null ? (int)$id : null;
    }

    /**
     * {@inheritDoc}
     */
    public function setCreatedByUserId(?int $userId): self
    {
        return $this->setData(self::CREATED_BY_USER_ID, $userId);
    }

    /**
     * {@inheritDoc}
     */
    public function getCreatedByUsername(): ?string
    {
        return $this->getData(self::CREATED_BY_USERNAME);
    }

    /**
     * {@inheritDoc}
     */
    public function setCreatedByUsername(?string $username): self
    {
        return $this->setData(self::CREATED_BY_USERNAME, $username);
    }

    /**
     * {@inheritDoc}
     */
    public function getLastEditedByUserId(): ?int
    {
        $id = $this->getData(self::LAST_EDITED_BY_USER_ID);

        return $id !== null ? (int)$id : null;
    }

    /**
     * {@inheritDoc}
     */
    public function setLastEditedByUserId(?int $userId): self
    {
        return $this->setData(self::LAST_EDITED_BY_USER_ID, $userId);
    }

    /**
     * {@inheritDoc}
     */
    public function getLastEditedByUsername(): ?string
    {
        return $this->getData(self::LAST_EDITED_BY_USERNAME);
    }

    /**
     * {@inheritDoc}
     */
    public function setLastEditedByUsername(?string $username): self
    {
        return $this->setData(self::LAST_EDITED_BY_USERNAME, $username);
    }

    /**
     * {@inheritDoc}
     */
    public function getLastEditedAt(): ?string
    {
        return $this->getData(self::LAST_EDITED_AT);
    }

    /**
     * {@inheritDoc}
     */
    public function setLastEditedAt(?string $lastEditedAt): self
    {
        return $this->setData(self::LAST_EDITED_AT, $lastEditedAt);
    }

    /**
     * {@inheritDoc}
     */
    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * {@inheritDoc}
     */
    public function setCreatedAt(string $createdAt): self
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * {@inheritDoc}
     */
    public function getUpdatedAt(): ?string
    {
        return $this->getData(self::UPDATED_AT);
    }

    /**
     * {@inheritDoc}
     */
    public function setUpdatedAt(string $updatedAt): self
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }

    /**
     * {@inheritDoc}
     */
    public function getDraftName(): ?string
    {
        return $this->getData(self::DRAFT_NAME);
    }

    /**
     * {@inheritDoc}
     */
    public function setDraftName(?string $draftName): self
    {
        return $this->setData(self::DRAFT_NAME, $draftName);
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveFrom(): ?string
    {
        return $this->getData(self::ACTIVE_FROM);
    }

    /**
     * {@inheritDoc}
     */
    public function setActiveFrom(?string $activeFrom): self
    {
        return $this->setData(self::ACTIVE_FROM, $activeFrom);
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveTo(): ?string
    {
        return $this->getData(self::ACTIVE_TO);
    }

    /**
     * {@inheritDoc}
     */
    public function setActiveTo(?string $activeTo): self
    {
        return $this->setData(self::ACTIVE_TO, $activeTo);
    }

    /**
     * {@inheritDoc}
     */
    public function getIsActive(): bool
    {
        return (bool)(int)$this->getData(self::IS_ACTIVE);
    }

    /**
     * {@inheritDoc}
     */
    public function setIsActive(bool $isActive): self
    {
        return $this->setData(self::IS_ACTIVE, $isActive ? 1 : 0);
    }

    /**
     * {@inheritDoc}
     */
    public function getSampleProviderCode(): ?string
    {
        $code = $this->getData(self::SAMPLE_PROVIDER_CODE);

        return $code !== null ? (string)$code : null;
    }

    /**
     * {@inheritDoc}
     */
    public function setSampleProviderCode(?string $providerCode): self
    {
        return $this->setData(self::SAMPLE_PROVIDER_CODE, $providerCode);
    }

    /**
     * {@inheritDoc}
     */
    public function getCustomVariables(): ?string
    {
        return $this->getData(self::CUSTOM_VARIABLES);
    }

    /**
     * {@inheritDoc}
     */
    public function setCustomVariables(?string $customVariables): self
    {
        return $this->setData(self::CUSTOM_VARIABLES, $customVariables);
    }
}
