<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data;

interface ThemeInterface
{
    public const THEME_ID = 'theme_id';
    public const NAME = 'name';
    public const THEME_CSS = 'theme_css';
    public const IS_DEFAULT = 'is_default';
    public const STORE_ID = 'store_id';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * Get theme ID
     *
     * @return int|null
     */
    public function getThemeId(): ?int;

    /**
     * Set theme ID
     *
     * @param int $themeId
     * @return $this
     */
    public function setThemeId(int $themeId): self;

    /**
     * Get theme name
     *
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * Set theme name
     *
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * Get theme CSS (Tailwind v4 `@theme {…}` block)
     *
     * @return string|null
     */
    public function getThemeCss(): ?string;

    /**
     * Set theme CSS (Tailwind v4 `@theme {…}` block)
     *
     * @param string $themeCss
     * @return $this
     */
    public function setThemeCss(string $themeCss): self;

    /**
     * Get is default flag
     *
     * @return bool
     */
    public function getIsDefault(): bool;

    /**
     * Set is default flag
     *
     * @param bool $isDefault
     * @return $this
     */
    public function setIsDefault(bool $isDefault): self;

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
}
