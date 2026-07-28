<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeFactoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeJsonValidatorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeWriterInterface;
use Magento\Framework\Exception\LocalizedException;

class ThemeWriter implements ThemeWriterInterface
{
    /**
     * @param ThemeRepositoryInterface $themeRepository
     * @param ThemeJsonValidatorInterface $themeJsonValidator
     * @param ThemeFactoryInterface $themeFactory
     */
    public function __construct(
        private readonly ThemeRepositoryInterface $themeRepository,
        private readonly ThemeJsonValidatorInterface $themeJsonValidator,
        private readonly ThemeFactoryInterface $themeFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function create(string $name, string $themeCss, int $storeId): ThemeInterface
    {
        if ($name === '') {
            throw new LocalizedException(__('Theme name is required.'));
        }

        $this->assertContentIsUsable($themeCss);

        $theme = $this->themeFactory->create();
        $theme->setName($name);
        $theme->setThemeCss($themeCss);
        $theme->setStoreId($storeId);
        $this->themeRepository->save($theme);

        return $theme;
    }

    /**
     * @inheritDoc
     */
    public function updateContent(int $themeId, string $themeCss, ?string $name = null): ThemeInterface
    {
        // Reject unusable content before the theme is loaded, so a bad request never reaches storage.
        $this->assertContentIsUsable($themeCss);

        $theme = $this->themeRepository->getById($themeId);
        $theme->setThemeCss($themeCss);

        if ($name !== null && $name !== '') {
            $theme->setName($name);
        }

        // The store scope is settled when a theme is created and is never written here: editing
        // content must not change which stores resolve this theme.
        $this->themeRepository->save($theme);

        return $theme;
    }

    /**
     * @inheritDoc
     */
    public function changeScope(int $themeId, int $storeId): ThemeInterface
    {
        $theme = $this->themeRepository->getById($themeId);

        $this->assertStoresKeepADefaultTheme($theme, $storeId);

        // Only the scope is written here: which stores resolve a theme says nothing about what
        // the theme contains, so moving one must never rewrite its name, source or default flag.
        $theme->setStoreId($storeId);
        $this->themeRepository->save($theme);

        return $theme;
    }

    /**
     * Refuse a move that would leave store views with no default theme to fall back to
     *
     * The default theme is resolved with `store_id IN (0, <current store>)`, so a default theme
     * that reaches every store view stops doing so the moment it is limited to one: every other
     * store view then resolves nothing and its emails render with no theme variables. A move to
     * the global scope, or between two specific store views, can never cause that.
     *
     * The remaining reach is established by asking storage for the default theme every store view
     * resolves and checking that it is some other theme.
     *
     * @param ThemeInterface $theme Theme about to be moved, as loaded from storage.
     * @param int $storeId Store view the caller wants the theme scoped to.
     * @return void
     * @throws LocalizedException When the theme is the only default reachable from every store
     *                            view and would stop being reachable from all of them.
     */
    private function assertStoresKeepADefaultTheme(ThemeInterface $theme, int $storeId): void
    {
        if ($storeId === 0 || !$theme->getIsDefault() || $theme->getStoreId() !== 0) {
            return;
        }

        $remaining = $this->themeRepository->getDefaultTheme(0);

        if ($remaining !== null && $remaining->getThemeId() !== $theme->getThemeId()) {
            return;
        }

        // Deliberately states the constraint without advising a remedy: nothing in the admin UI
        // can flag a different theme as the default, so telling the admin to do that first would
        // send them after an action that does not exist.
        throw new LocalizedException(
            __(
                'This is the only default theme available to all store views, so it cannot be '
                . 'limited to one of them - every other store view would be left with no theme '
                . 'at all.'
            )
        );
    }

    /**
     * Reject theme content that must never be persisted
     *
     * @param string $themeCss Authored theme source as submitted.
     * @return void
     * @throws LocalizedException When the source is blank or the validator rejects it.
     */
    private function assertContentIsUsable(string $themeCss): void
    {
        if ($themeCss === '') {
            throw new LocalizedException(__('Theme CSS is required.'));
        }

        if (!$this->themeJsonValidator->validate($themeCss)) {
            $errors = $this->themeJsonValidator->getErrors();

            throw new LocalizedException(__('Invalid theme CSS: %1', implode(', ', $errors)));
        }
    }
}
