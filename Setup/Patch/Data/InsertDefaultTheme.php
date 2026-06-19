<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Setup\Patch\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\ThemeFactory;
use Hryvinskyi\EmailTemplateEditor\Model\ResourceModel\Theme as ThemeResource;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class InsertDefaultTheme implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ThemeFactory $themeFactory
     * @param ThemeResource $themeResource
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ThemeFactory $themeFactory,
        private readonly ThemeResource $themeResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $theme = $this->themeFactory->create();
        $theme->setData([
            ThemeInterface::NAME => 'Default',
            ThemeInterface::THEME_CSS => json_encode($this->getDefaultThemeJson(), JSON_PRETTY_PRINT),
            ThemeInterface::IS_DEFAULT => 1,
            ThemeInterface::STORE_ID => 0,
        ]);

        $this->themeResource->save($theme);
        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Get the default theme JSON structure
     *
     * @return array<string, mixed>
     */
    private function getDefaultThemeJson(): array
    {
        return [
            'tokens' => [
                'colors' => [
                    'primary' => '#1a1a2e',
                    'secondary' => '#16213e',
                    'accent' => '#0f3460',
                    'highlight' => '#e94560',
                    'text' => '#333333',
                    'textLight' => '#666666',
                    'textMuted' => '#999999',
                    'link' => '#007dbd',
                    'linkHover' => '#005a8c',
                    'success' => '#79a22e',
                    'warning' => '#f0ad4e',
                    'danger' => '#d9534f',
                    'info' => '#5bc0de',
                    'white' => '#ffffff',
                    'black' => '#000000',
                    'gray100' => '#f5f5f5',
                    'gray200' => '#e3e3e3',
                    'gray300' => '#cccccc',
                    'gray400' => '#999999',
                    'gray500' => '#666666',
                    'gray600' => '#4a3f39',
                    'bodyBg' => '#f5f5f5',
                    'containerBg' => '#ffffff',
                    'headerBg' => '#1a1a2e',
                    'footerBg' => '#f5f5f5',
                    'borderColor' => '#e3e3e3',
                ],
                'spacing' => [
                    '0' => '0',
                    '1' => '4px',
                    '2' => '8px',
                    '3' => '12px',
                    '4' => '16px',
                    '5' => '20px',
                    '6' => '24px',
                    '8' => '32px',
                    '10' => '40px',
                    '12' => '48px',
                    '16' => '64px',
                ],
                'fontSize' => [
                    'xs' => '12px',
                    'sm' => '14px',
                    'base' => '16px',
                    'lg' => '18px',
                    'xl' => '20px',
                    '2xl' => '24px',
                    '3xl' => '30px',
                    '4xl' => '36px',
                ],
                'lineHeight' => [
                    'tight' => '1.25',
                    'normal' => '1.5',
                    'relaxed' => '1.75',
                ],
                'fontFamily' => [
                    'sans' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
                    'serif' => 'Georgia, "Times New Roman", Times, serif',
                    'mono' => "'Courier New', Courier, monospace",
                ],
                'borderRadius' => [
                    'none' => '0',
                    'sm' => '2px',
                    'md' => '4px',
                    'lg' => '8px',
                    'xl' => '12px',
                    'full' => '9999px',
                ],
                'borderWidth' => [
                    '0' => '0',
                    '1' => '1px',
                    '2' => '2px',
                    '4' => '4px',
                ],
                'opacity' => [
                    '0' => '0',
                    '25' => '0.25',
                    '50' => '0.5',
                    '75' => '0.75',
                    '100' => '1',
                ],
                'shadow' => [
                    'none' => 'none',
                    'sm' => '0 1px 2px rgba(0,0,0,0.05)',
                    'md' => '0 4px 6px rgba(0,0,0,0.1)',
                    'lg' => '0 10px 15px rgba(0,0,0,0.1)',
                ],
                'googleFonts' => [],
            ],
            'elements' => [
                'body' => [
                    'font-family' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
                    'font-size' => '16px',
                    'line-height' => '1.5',
                    'color' => '#333333',
                    'background-color' => '#f5f5f5',
                    'margin' => '0',
                    'padding' => '0',
                ],
                'h1' => [
                    'font-size' => '30px',
                    'font-weight' => '700',
                    'line-height' => '1.25',
                    'color' => '#1a1a2e',
                    'margin' => '0 0 16px 0',
                ],
                'h2' => [
                    'font-size' => '24px',
                    'font-weight' => '700',
                    'line-height' => '1.25',
                    'color' => '#1a1a2e',
                    'margin' => '0 0 12px 0',
                ],
                'h3' => [
                    'font-size' => '20px',
                    'font-weight' => '600',
                    'line-height' => '1.25',
                    'color' => '#333333',
                    'margin' => '0 0 12px 0',
                ],
                'h4' => [
                    'font-size' => '18px',
                    'font-weight' => '600',
                    'line-height' => '1.25',
                    'color' => '#333333',
                    'margin' => '0 0 8px 0',
                ],
                'h5' => [
                    'font-size' => '16px',
                    'font-weight' => '600',
                    'line-height' => '1.25',
                    'color' => '#333333',
                    'margin' => '0 0 8px 0',
                ],
                'h6' => [
                    'font-size' => '14px',
                    'font-weight' => '600',
                    'line-height' => '1.25',
                    'color' => '#666666',
                    'margin' => '0 0 8px 0',
                ],
                'p' => [
                    'font-size' => '16px',
                    'line-height' => '1.5',
                    'color' => '#333333',
                    'margin' => '0 0 16px 0',
                ],
                'a' => [
                    'color' => '#007dbd',
                    'text-decoration' => 'underline',
                ],
                'ul' => [
                    'margin' => '0 0 16px 0',
                    'padding' => '0 0 0 24px',
                ],
                'li' => [
                    'margin' => '0 0 4px 0',
                    'line-height' => '1.5',
                ],
                'table' => [
                    'width' => '100%',
                    'border-collapse' => 'collapse',
                ],
                'th' => [
                    'padding' => '8px 12px',
                    'text-align' => 'left',
                    'font-weight' => '600',
                    'border-bottom' => '2px solid #e3e3e3',
                    'color' => '#333333',
                ],
                'td' => [
                    'padding' => '8px 12px',
                    'border-bottom' => '1px solid #e3e3e3',
                    'color' => '#333333',
                ],
            ],
            'utilities' => [
                'mb-0' => ['margin-bottom' => '0'],
                'mb-1' => ['margin-bottom' => '4px'],
                'mb-2' => ['margin-bottom' => '8px'],
                'mb-3' => ['margin-bottom' => '12px'],
                'mb-4' => ['margin-bottom' => '16px'],
                'mb-5' => ['margin-bottom' => '20px'],
                'mb-6' => ['margin-bottom' => '24px'],
                'mb-8' => ['margin-bottom' => '32px'],
                'mt-0' => ['margin-top' => '0'],
                'mt-1' => ['margin-top' => '4px'],
                'mt-2' => ['margin-top' => '8px'],
                'mt-3' => ['margin-top' => '12px'],
                'mt-4' => ['margin-top' => '16px'],
                'mt-5' => ['margin-top' => '20px'],
                'mt-6' => ['margin-top' => '24px'],
                'mt-8' => ['margin-top' => '32px'],
                'p-0' => ['padding' => '0'],
                'p-1' => ['padding' => '4px'],
                'p-2' => ['padding' => '8px'],
                'p-3' => ['padding' => '12px'],
                'p-4' => ['padding' => '16px'],
                'p-5' => ['padding' => '20px'],
                'p-6' => ['padding' => '24px'],
                'p-8' => ['padding' => '32px'],
                'text-xs' => ['font-size' => '12px'],
                'text-sm' => ['font-size' => '14px'],
                'text-base' => ['font-size' => '16px'],
                'text-lg' => ['font-size' => '18px'],
                'text-xl' => ['font-size' => '20px'],
                'text-2xl' => ['font-size' => '24px'],
                'text-3xl' => ['font-size' => '30px'],
                'text-center' => ['text-align' => 'center'],
                'text-left' => ['text-align' => 'left'],
                'text-right' => ['text-align' => 'right'],
                'font-bold' => ['font-weight' => '700'],
                'font-semibold' => ['font-weight' => '600'],
                'font-normal' => ['font-weight' => '400'],
                'leading-tight' => ['line-height' => '1.25'],
                'leading-normal' => ['line-height' => '1.5'],
                'leading-relaxed' => ['line-height' => '1.75'],
                'no-list-style' => ['list-style' => 'none', 'padding-left' => '0'],
                'border-none' => ['border' => 'none'],
                'w-full' => ['width' => '100%'],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
