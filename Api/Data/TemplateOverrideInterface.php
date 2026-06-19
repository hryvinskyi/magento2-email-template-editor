<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data;

interface TemplateOverrideInterface
{
    public const ENTITY_ID = 'entity_id';
    public const TEMPLATE_IDENTIFIER = 'template_identifier';
    public const TEMPLATE_CONTENT = 'template_content';
    public const TEMPLATE_SUBJECT = 'template_subject';
    public const CUSTOM_CSS = 'custom_css';
    public const TAILWIND_CSS = 'tailwind_css';
    public const THEME_ID = 'theme_id';
    public const STORE_ID = 'store_id';
    public const STATUS = 'status';
    public const SCHEDULED_AT = 'scheduled_at';
    public const VERSION_COMMENT = 'version_comment';
    public const CREATED_BY_USER_ID = 'created_by_user_id';
    public const CREATED_BY_USERNAME = 'created_by_username';
    public const LAST_EDITED_BY_USER_ID = 'last_edited_by_user_id';
    public const LAST_EDITED_BY_USERNAME = 'last_edited_by_username';
    public const LAST_EDITED_AT = 'last_edited_at';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    public const DRAFT_NAME = 'draft_name';
    public const ACTIVE_FROM = 'active_from';
    public const ACTIVE_TO = 'active_to';
    public const IS_ACTIVE = 'is_active';
    public const SAMPLE_PROVIDER_CODE = 'sample_provider_code';
    public const CUSTOM_VARIABLES = 'custom_variables';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SCHEDULED = 'scheduled';

    /**
     * Get entity ID
     *
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * Get template identifier
     *
     * @return string|null
     */
    public function getTemplateIdentifier(): ?string;

    /**
     * Set template identifier
     *
     * @param string $identifier
     * @return $this
     */
    public function setTemplateIdentifier(string $identifier): self;

    /**
     * Get template content
     *
     * @return string|null
     */
    public function getTemplateContent(): ?string;

    /**
     * Set template content
     *
     * @param string $content
     * @return $this
     */
    public function setTemplateContent(string $content): self;

    /**
     * Get template subject
     *
     * @return string|null
     */
    public function getTemplateSubject(): ?string;

    /**
     * Set template subject
     *
     * @param string|null $subject
     * @return $this
     */
    public function setTemplateSubject(?string $subject): self;

    /**
     * Get custom CSS
     *
     * @return string|null
     */
    public function getCustomCss(): ?string;

    /**
     * Set custom CSS
     *
     * @param string|null $customCss
     * @return $this
     */
    public function setCustomCss(?string $customCss): self;

    /**
     * Get Tailwind CSS
     *
     * @return string|null
     */
    public function getTailwindCss(): ?string;

    /**
     * Set Tailwind CSS
     *
     * @param string|null $tailwindCss
     * @return $this
     */
    public function setTailwindCss(?string $tailwindCss): self;

    /**
     * Get theme ID
     *
     * @return int|null
     */
    public function getThemeId(): ?int;

    /**
     * Set theme ID
     *
     * @param int|null $themeId
     * @return $this
     */
    public function setThemeId(?int $themeId): self;

    /**
     * Get store ID
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Set store ID
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * Get override status
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * Set override status
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * Get scheduled publication date
     *
     * @return string|null
     */
    public function getScheduledAt(): ?string;

    /**
     * Set scheduled publication date
     *
     * @param string|null $scheduledAt
     * @return $this
     */
    public function setScheduledAt(?string $scheduledAt): self;

    /**
     * Get version comment
     *
     * @return string|null
     */
    public function getVersionComment(): ?string;

    /**
     * Set version comment
     *
     * @param string|null $versionComment
     * @return $this
     */
    public function setVersionComment(?string $versionComment): self;

    /**
     * Get ID of the admin user who created the override
     *
     * @return int|null
     */
    public function getCreatedByUserId(): ?int;

    /**
     * Set ID of the admin user who created the override
     *
     * @param int|null $userId
     * @return $this
     */
    public function setCreatedByUserId(?int $userId): self;

    /**
     * Get username of the admin user who created the override
     *
     * @return string|null
     */
    public function getCreatedByUsername(): ?string;

    /**
     * Set username of the admin user who created the override
     *
     * @param string|null $username
     * @return $this
     */
    public function setCreatedByUsername(?string $username): self;

    /**
     * Get ID of the admin user who last edited the override
     *
     * @return int|null
     */
    public function getLastEditedByUserId(): ?int;

    /**
     * Set ID of the admin user who last edited the override
     *
     * @param int|null $userId
     * @return $this
     */
    public function setLastEditedByUserId(?int $userId): self;

    /**
     * Get username of the admin user who last edited the override
     *
     * @return string|null
     */
    public function getLastEditedByUsername(): ?string;

    /**
     * Set username of the admin user who last edited the override
     *
     * @param string|null $username
     * @return $this
     */
    public function setLastEditedByUsername(?string $username): self;

    /**
     * Get last edited timestamp
     *
     * @return string|null
     */
    public function getLastEditedAt(): ?string;

    /**
     * Set last edited timestamp
     *
     * @param string|null $lastEditedAt
     * @return $this
     */
    public function setLastEditedAt(?string $lastEditedAt): self;

    /**
     * Get creation time
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Set creation time
     *
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt(string $createdAt): self;

    /**
     * Get update time
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * Set update time
     *
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt(string $updatedAt): self;

    /**
     * Get draft name
     *
     * @return string|null
     */
    public function getDraftName(): ?string;

    /**
     * Set draft name
     *
     * @param string|null $draftName
     * @return $this
     */
    public function setDraftName(?string $draftName): self;

    /**
     * Get schedule start datetime
     *
     * @return string|null
     */
    public function getActiveFrom(): ?string;

    /**
     * Set schedule start datetime
     *
     * @param string|null $activeFrom
     * @return $this
     */
    public function setActiveFrom(?string $activeFrom): self;

    /**
     * Get schedule end datetime
     *
     * @return string|null
     */
    public function getActiveTo(): ?string;

    /**
     * Set schedule end datetime
     *
     * @param string|null $activeTo
     * @return $this
     */
    public function setActiveTo(?string $activeTo): self;

    /**
     * Get whether the override is active
     *
     * @return bool
     */
    public function getIsActive(): bool;

    /**
     * Set whether the override is active
     *
     * @param bool $isActive
     * @return $this
     */
    public function setIsActive(bool $isActive): self;

    /**
     * Get the selected preview sample-data provider code
     *
     * @return string|null
     */
    public function getSampleProviderCode(): ?string;

    /**
     * Set the selected preview sample-data provider code
     *
     * @param string|null $providerCode
     * @return $this
     */
    public function setSampleProviderCode(?string $providerCode): self;

    /**
     * Get the custom preview sample-data JSON (used when the provider is custom)
     *
     * @return string|null
     */
    public function getCustomVariables(): ?string;

    /**
     * Set the custom preview sample-data JSON (used when the provider is custom)
     *
     * @param string|null $customVariables
     * @return $this
     */
    public function setCustomVariables(?string $customVariables): self;
}
