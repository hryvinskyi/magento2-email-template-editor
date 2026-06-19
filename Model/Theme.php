<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\Theme as ThemeResource;
use Magento\Framework\Model\AbstractModel;

class Theme extends AbstractModel implements ThemeInterface
{
    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ThemeResource::class);
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
    public function setThemeId(int $themeId): self
    {
        return $this->setData(self::THEME_ID, $themeId);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): ?string
    {
        return $this->getData(self::NAME);
    }

    /**
     * {@inheritDoc}
     */
    public function setName(string $name): self
    {
        return $this->setData(self::NAME, $name);
    }

    /**
     * Legacy column name kept on disk until the declarative-schema rename runs.
     * The model writes to both columns and reads with a fallback, so the editor works
     * whether or not `setup:upgrade` has applied yet. Magento's ORM silently drops
     * setData() calls for columns that don't exist in the table.
     */
    private const LEGACY_THEME_COLUMN = 'theme_json';

    /**
     * {@inheritDoc}
     */
    public function getThemeCss(): ?string
    {
        $value = $this->getData(self::THEME_CSS);
        if ($value === null || $value === '') {
            $value = $this->getData(self::LEGACY_THEME_COLUMN);
        }

        return $value !== null ? (string)$value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function setThemeCss(string $themeCss): self
    {
        $this->setData(self::THEME_CSS, $themeCss);
        $this->setData(self::LEGACY_THEME_COLUMN, $themeCss);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getIsDefault(): bool
    {
        return (bool)$this->getData(self::IS_DEFAULT);
    }

    /**
     * {@inheritDoc}
     */
    public function setIsDefault(bool $isDefault): self
    {
        return $this->setData(self::IS_DEFAULT, $isDefault ? 1 : 0);
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
}
