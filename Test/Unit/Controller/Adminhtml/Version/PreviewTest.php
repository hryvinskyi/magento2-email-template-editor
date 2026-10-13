<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Controller\Adminhtml\Version;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateVersionInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateRendererInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVersionRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Version\Preview;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

/**
 * A version is previewed with the theme it was saved with, as it was sent.
 */
class PreviewTest extends TestCase
{
    /**
     * Theme source the renderer was handed
     *
     * @var string|false|null False until the renderer is called
     */
    private mixed $renderedTheme = false;

    /**
     * The version's own theme supplies the tokens
     *
     * @return void
     */
    public function testTheVersionsThemeIsUsed(): void
    {
        $theme = $this->createMock(ThemeInterface::class);
        $theme->method('getThemeCss')->willReturn('@theme { --color-primary: #131ccf; }');
        $themes = $this->createMock(ThemeRepositoryInterface::class);
        $themes->method('getById')->with(3)->willReturn($theme);

        $this->controller(3, $themes)->execute();

        self::assertSame('@theme { --color-primary: #131ccf; }', $this->renderedTheme);
    }

    /**
     * A version that names no theme, or a deleted one, falls back to the store's default theme
     *
     * @return void
     */
    public function testAMissingThemeFallsBackToTheStoreDefault(): void
    {
        $themes = $this->createMock(ThemeRepositoryInterface::class);
        $themes->method('getById')->willThrowException(new NoSuchEntityException());

        $this->controller(3, $themes)->execute();
        self::assertNull($this->renderedTheme);

        $this->renderedTheme = false;
        $this->controller(null, $themes)->execute();
        self::assertNull($this->renderedTheme);
    }

    /**
     * Build the controller previewing a version saved with the given theme
     *
     * @param int|null $themeId
     * @param ThemeRepositoryInterface $themes
     * @return Preview
     */
    private function controller(?int $themeId, ThemeRepositoryInterface $themes): Preview
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['version_id', 0, '12']]);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnSelf();
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $version = $this->createMock(TemplateVersionInterface::class);
        $version->method('getTemplateContent')->willReturn('<p>v</p>');
        $version->method('getStoreId')->willReturn(1);
        $version->method('getTemplateIdentifier')->willReturn('sales_email_order_template');
        $version->method('getThemeId')->willReturn($themeId);
        $versions = $this->createMock(TemplateVersionRepositoryInterface::class);
        $versions->method('getById')->willReturn($version);

        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            function (
                string $content,
                array $variables,
                int $storeId,
                ?string $customCss = null,
                ?string $tailwindCss = null,
                ?string $templateIdentifier = null,
                bool $isMockData = false,
                ?string $themeCssOverride = null
            ): string {
                $this->renderedTheme = $themeCssOverride;

                return '<p>v</p>';
            }
        );

        return new Preview($context, $jsonFactory, $renderer, $versions, $themes);
    }
}
