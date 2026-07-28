<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeFactoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeJsonValidatorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Model\ThemeWriter;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ThemeWriterTest extends TestCase
{
    private const VALID_CSS = '@theme { --color-primary: #131CCF; }';

    private ThemeRepositoryInterface&MockObject $themeRepository;
    private ThemeJsonValidatorInterface&MockObject $themeJsonValidator;
    private ThemeFactoryInterface&MockObject $themeFactory;
    private ThemeInterface&MockObject $theme;
    private ThemeWriter $writer;

    protected function setUp(): void
    {
        $this->themeRepository = $this->createMock(ThemeRepositoryInterface::class);
        $this->themeJsonValidator = $this->createMock(ThemeJsonValidatorInterface::class);
        $this->themeFactory = $this->createMock(ThemeFactoryInterface::class);
        $this->theme = $this->createMock(ThemeInterface::class);

        $this->writer = new ThemeWriter(
            $this->themeRepository,
            $this->themeJsonValidator,
            $this->themeFactory
        );
    }

    public function testCreateStoresTheGivenStoreScopeAndReturnsTheNewTheme(): void
    {
        $this->themeJsonValidator->method('validate')->willReturn(true);
        $this->themeFactory->expects(self::once())->method('create')->willReturn($this->theme);

        $this->theme->expects(self::once())->method('setName')->with('Brand');
        $this->theme->expects(self::once())->method('setThemeCss')->with(self::VALID_CSS);
        $this->theme->expects(self::once())->method('setStoreId')->with(3);
        $this->themeRepository->expects(self::once())->method('save')->with($this->theme);

        self::assertSame(
            $this->theme,
            $this->writer->create('Brand', self::VALID_CSS, 3)
        );
    }

    public function testUpdateContentNeverMovesTheThemeBetweenStoreScopes(): void
    {
        $this->themeJsonValidator->method('validate')->willReturn(true);
        $this->themeRepository->method('getById')->with(7)->willReturn($this->theme);

        $this->theme->expects(self::never())->method('setStoreId');

        try {
            $this->writer->updateContent(7, self::VALID_CSS, 'Renamed');
        } catch (ExpectationFailedException) {
            self::fail(
                'updateContent() wrote store_id. Updating authored content must never move a theme '
                . 'between store scopes: the default theme is resolved with store_id IN (0, <current '
                . 'store>), so a theme moved off the global scope stops every other store resolving '
                . 'a theme at all.'
            );
        }
    }

    public function testUpdateContentSavesTheLoadedThemeAndReturnsIt(): void
    {
        $this->themeJsonValidator->method('validate')->willReturn(true);
        $this->themeRepository->expects(self::once())->method('getById')->with(7)->willReturn($this->theme);

        $this->theme->expects(self::once())->method('setThemeCss')->with(self::VALID_CSS);
        $this->themeRepository->expects(self::once())->method('save')->with($this->theme);

        self::assertSame(
            $this->theme,
            $this->writer->updateContent(7, self::VALID_CSS)
        );
    }

    public function testCreateRejectsBlankNameBeforeTheFactoryIsCalled(): void
    {
        $this->themeFactory->expects(self::never())->method('create');
        $this->themeJsonValidator->expects(self::never())->method('validate');
        $this->themeRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Theme name is required.');

        $this->writer->create('', self::VALID_CSS, 3);
    }

    public function testCreateRejectsBlankCssBeforeTheValidatorRuns(): void
    {
        $this->themeJsonValidator->expects(self::never())->method('validate');
        $this->themeFactory->expects(self::never())->method('create');
        $this->themeRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Theme CSS is required.');

        $this->writer->create('Brand', '', 3);
    }

    public function testUpdateContentRejectsBlankCssBeforeTheValidatorRuns(): void
    {
        $this->themeJsonValidator->expects(self::never())->method('validate');
        $this->themeRepository->expects(self::never())->method('getById');
        $this->themeRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Theme CSS is required.');

        $this->writer->updateContent(7, '');
    }

    public function testCreateRejectsInvalidCssBeforeAnythingIsPersisted(): void
    {
        $this->themeJsonValidator->method('validate')->willReturn(false);
        $this->themeJsonValidator->method('getErrors')->willReturn(['a', 'b']);

        $this->themeFactory->expects(self::never())->method('create');
        $this->themeRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid theme CSS: a, b');

        $this->writer->create('Brand', 'not a theme', 3);
    }

    public function testUpdateContentRejectsInvalidCssBeforeTheThemeIsLoaded(): void
    {
        $this->themeJsonValidator->method('validate')->willReturn(false);
        $this->themeJsonValidator->method('getErrors')->willReturn(['a', 'b']);

        $this->themeRepository->expects(self::never())->method('getById');
        $this->themeRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid theme CSS: a, b');

        $this->writer->updateContent(7, 'not a theme');
    }

    /**
     * @param string|null $name Name as the caller supplies it when it means "not supplied".
     * @return void
     * @dataProvider unsuppliedNameProvider
     */
    public function testUpdateContentKeepsTheStoredNameWhenNoneIsSupplied(?string $name): void
    {
        $this->themeJsonValidator->method('validate')->willReturn(true);
        $this->themeRepository->method('getById')->with(7)->willReturn($this->theme);

        $this->theme->expects(self::never())->method('setName');
        $this->themeRepository->expects(self::once())->method('save')->with($this->theme);

        $this->writer->updateContent(7, self::VALID_CSS, $name);
    }

    /**
     * Values that both mean "the caller supplied no name"
     *
     * The admin controller reads its `name` param with a `''` default, so `''` is what production
     * sends; `null` is only reachable from direct PHP callers.
     *
     * @return array<string, array{0: string|null}>
     */
    public function unsuppliedNameProvider(): array
    {
        return [
            'empty string, as the admin controller sends' => [''],
            'null, the parameter default' => [null],
        ];
    }

    public function testUpdateContentSetsTheNameWhenOneIsSupplied(): void
    {
        $this->themeJsonValidator->method('validate')->willReturn(true);
        $this->themeRepository->method('getById')->with(7)->willReturn($this->theme);

        $this->theme->expects(self::once())->method('setName')->with('Renamed');
        $this->themeRepository->expects(self::once())->method('save')->with($this->theme);

        $this->writer->updateContent(7, self::VALID_CSS, 'Renamed');
    }

    public function testChangeScopeNeverTouchesTheThemeContent(): void
    {
        $this->givenLoadedTheme(7, false, 0);

        $this->theme->expects(self::never())->method('setThemeCss');
        $this->theme->expects(self::never())->method('setName');
        $this->theme->expects(self::never())->method('setIsDefault');

        try {
            $this->writer->changeScope(7, 5);
        } catch (ExpectationFailedException) {
            self::fail(
                'changeScope() wrote authored content. Moving a theme between store scopes and '
                . 'editing what it contains are separate intents, and neither may perform the '
                . "other's effect."
            );
        }
    }

    public function testChangeScopeStoresTheGivenScopeAndReturnsTheTheme(): void
    {
        $this->givenLoadedTheme(7, false, 0);

        $this->theme->expects(self::once())->method('setStoreId')->with(5);
        $this->themeRepository->expects(self::once())->method('save')->with($this->theme);

        self::assertSame(
            $this->theme,
            $this->writer->changeScope(7, 5)
        );
    }

    public function testChangeScopeRefusesToLimitTheOnlyDefaultThemeToOneStoreView(): void
    {
        $this->givenLoadedTheme(7, true, 0);
        $this->themeRepository->method('getDefaultTheme')->with(0)->willReturn($this->theme);

        $this->theme->expects(self::never())->method('setStoreId');
        $this->themeRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('every other store view would be left with no theme at all.');

        $this->writer->changeScope(7, 5);
    }

    public function testChangeScopeMovesADefaultThemeWhenAnotherOneStillReachesEveryStoreView(): void
    {
        $this->givenLoadedTheme(7, true, 0);

        $survivor = $this->createMock(ThemeInterface::class);
        $survivor->method('getThemeId')->willReturn(4);
        $this->themeRepository->method('getDefaultTheme')->with(0)->willReturn($survivor);

        $this->theme->expects(self::once())->method('setStoreId')->with(5);
        $this->themeRepository->expects(self::once())->method('save')->with($this->theme);

        $this->writer->changeScope(7, 5);
    }

    public function testChangeScopeAlwaysAllowsAMoveBackToEveryStoreView(): void
    {
        $this->givenLoadedTheme(7, true, 3);

        $this->themeRepository->expects(self::never())->method('getDefaultTheme');
        $this->theme->expects(self::once())->method('setStoreId')->with(0);
        $this->themeRepository->expects(self::once())->method('save')->with($this->theme);

        $this->writer->changeScope(7, 0);
    }

    /**
     * Make the repository hand out the shared theme mock in the state a test needs
     *
     * @param int $themeId Identifier the theme is loaded by and reports as its own.
     * @param bool $isDefault Whether the stored theme is flagged as the default one.
     * @param int $storeId Store scope the theme currently carries.
     * @return void
     */
    private function givenLoadedTheme(int $themeId, bool $isDefault, int $storeId): void
    {
        $this->theme->method('getThemeId')->willReturn($themeId);
        $this->theme->method('getIsDefault')->willReturn($isDefault);
        $this->theme->method('getStoreId')->willReturn($storeId);

        $this->themeRepository->method('getById')->with($themeId)->willReturn($this->theme);
    }
}
