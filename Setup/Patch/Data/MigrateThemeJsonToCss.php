<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Convert legacy JSON content carried over to the renamed `theme_css` column into a
 * Tailwind v4 CSS-first theme block. The declarative-schema rename (theme_json → theme_css)
 * runs before this data patch, so existing rows already live in `theme_css` but still hold
 * the old JSON payload at this point.
 */
class MigrateThemeJsonToCss implements DataPatchInterface
{
    /**
     * Tailwind v4 @theme namespace prefix per legacy token section
     *
     * Must stay in sync with Hryvinskyi\EmailTemplateEditor\Model\UtilityCssGenerator
     */
    private const TOKEN_TO_V4_PREFIX = [
        'colors' => 'color',
        'spacing' => 'spacing',
        'fontSize' => 'text',
        'fontFamily' => 'font',
        'fontWeight' => 'font-weight',
        'lineHeight' => 'leading',
        'letterSpacing' => 'tracking',
        'borderRadius' => 'radius',
        'boxShadow' => 'shadow',
        'opacity' => 'opacity',
        'maxWidth' => 'container',
        'zIndex' => 'z',
    ];

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('hryvinskyi_email_theme');

        $rows = $connection->fetchAll(
            $connection->select()->from($table, ['theme_id', 'theme_css'])
        );

        foreach ($rows as $row) {
            $css = $this->convertIfLegacyJson((string)$row['theme_css']);
            if ($css === null) {
                continue;
            }

            $connection->update(
                $table,
                ['theme_css' => $css],
                ['theme_id = ?' => (int)$row['theme_id']]
            );
        }

        return $this;
    }

    /**
     * Convert a legacy JSON theme payload into v4 CSS, or return null if already CSS
     *
     * @param string $value
     * @return string|null
     */
    private function convertIfLegacyJson(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !str_starts_with($trimmed, '{')) {
            return null;
        }

        $data = json_decode($trimmed, true);
        if (!is_array($data)) {
            return null;
        }

        $blocks = [];

        if (!empty($data['tokens']['googleFonts']) && is_array($data['tokens']['googleFonts'])) {
            foreach ($data['tokens']['googleFonts'] as $font) {
                $blocks[] = sprintf(
                    "@import url('https://fonts.googleapis.com/css2?family=%s&display=swap');",
                    urlencode((string)$font)
                );
            }
        }

        $themeBlock = $this->buildThemeBlock($data['tokens'] ?? []);
        if ($themeBlock !== '') {
            $blocks[] = $themeBlock;
        }

        if (!empty($data['elements']) && is_array($data['elements'])) {
            foreach ($data['elements'] as $selector => $declarations) {
                $rule = $this->buildRule((string)$selector, $declarations);
                if ($rule !== '') {
                    $blocks[] = $rule;
                }
            }
        }

        if (!empty($data['utilities']) && is_array($data['utilities'])) {
            foreach ($data['utilities'] as $className => $declarations) {
                $rule = $this->buildRule('.' . $this->escapeClassName((string)$className), $declarations);
                if ($rule !== '') {
                    $blocks[] = $rule;
                }
            }
        }

        return implode("\n\n", array_filter($blocks));
    }

    /**
     * Build the `@theme { … }` block from the legacy tokens map
     *
     * @param array<string, mixed> $tokens
     * @return string
     */
    private function buildThemeBlock(array $tokens): string
    {
        $lines = [];

        foreach (self::TOKEN_TO_V4_PREFIX as $tokenKey => $prefix) {
            if (empty($tokens[$tokenKey]) || !is_array($tokens[$tokenKey])) {
                continue;
            }

            foreach ($tokens[$tokenKey] as $name => $value) {
                $lines[] = sprintf(
                    '  --%s-%s: %s;',
                    $prefix,
                    $this->escapeIdent((string)$name),
                    $this->escapeValue((string)$value)
                );
            }
        }

        return $lines === [] ? '' : "@theme {\n" . implode("\n", $lines) . "\n}";
    }

    /**
     * Build a CSS rule from a selector + declaration map
     *
     * @param string $selector
     * @param mixed $declarations
     * @return string
     */
    private function buildRule(string $selector, mixed $declarations): string
    {
        if (!is_array($declarations) || $declarations === []) {
            return '';
        }

        $lines = [];
        foreach ($declarations as $property => $value) {
            $lines[] = sprintf(
                '  %s: %s;',
                preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$property) ?? '',
                $this->escapeValue((string)$value)
            );
        }

        return $selector . " {\n" . implode("\n", $lines) . "\n}";
    }

    /**
     * Sanitize a custom-property identifier
     *
     * @param string $name
     * @return string
     */
    private function escapeIdent(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name) ?? $name;
    }

    /**
     * Sanitize a CSS class name
     *
     * @param string $name
     * @return string
     */
    private function escapeClassName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name) ?? $name;
    }

    /**
     * Strip declaration terminators from a CSS value
     *
     * @param string $value
     * @return string
     */
    private function escapeValue(string $value): string
    {
        return preg_replace('/[;\{\}]/', '', $value) ?? $value;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [InsertDefaultTheme::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
