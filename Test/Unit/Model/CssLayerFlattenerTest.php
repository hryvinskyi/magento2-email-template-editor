<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Model\CssLayerFlattener;
use PHPUnit\Framework\TestCase;

class CssLayerFlattenerTest extends TestCase
{
    private CssLayerFlattener $flattener;

    protected function setUp(): void
    {
        $this->flattener = new CssLayerFlattener();
    }

    public function testUtilitiesLayerIsUnwrapped(): void
    {
        $out = $this->flattener->flatten('@layer utilities { .x { color: red; } }');
        self::assertStringContainsString('.x { color: red; }', $out);
        self::assertStringNotContainsString('@layer utilities', $out);
    }

    public function testThemeLayerIsUnwrapped(): void
    {
        $out = $this->flattener->flatten('@layer theme { :root { --c: red; } }');
        self::assertStringContainsString(':root { --c: red; }', $out);
        self::assertStringNotContainsString('@layer theme', $out);
    }

    public function testBaseLayerIsDroppedEntirely(): void
    {
        $css = '@layer base { *, ::before { box-sizing: border-box; margin: 0; } }
                @layer utilities { .keep { color: red; } }';
        $out = $this->flattener->flatten($css);
        self::assertStringNotContainsString('box-sizing', $out);
        self::assertStringNotContainsString('@layer base', $out);
        self::assertStringContainsString('.keep', $out);
    }

    public function testPropertiesLayerIsDroppedEntirely(): void
    {
        $css = '@layer properties { @supports ((-webkit-hyphens: none)) {
                    *, ::before { --tw-invert: initial; --tw-blur: initial; }
                } }
                @layer utilities { .keep { color: red; } }';
        $out = $this->flattener->flatten($css);
        self::assertStringNotContainsString('--tw-invert: initial', $out);
        self::assertStringNotContainsString('@layer properties', $out);
        self::assertStringContainsString('.keep', $out);
    }

    public function testPropertyAtRulesAreDropped(): void
    {
        $css = '@property --tw-invert { syntax: "*"; inherits: false; }
                @property --tw-blur { syntax: "*"; inherits: false; }
                .keep { color: red; }';
        $out = $this->flattener->flatten($css);
        self::assertStringNotContainsString('@property', $out);
        self::assertStringContainsString('.keep', $out);
    }

    public function testMultipleLayerNamesAreUnwrapped(): void
    {
        $out = $this->flattener->flatten('@layer theme, base, utilities { .x { color: red; } }');
        self::assertStringContainsString('.x { color: red; }', $out);
        self::assertStringNotContainsString('@layer theme, base, utilities', $out);
    }

    public function testNestedAtRulesInsidePreservedLayerAreUnwrapped(): void
    {
        // @media inside @layer utilities - the @layer is dropped, the @media kept
        $css = '@layer utilities { @media (max-width: 600px) { .x { color: red; } } }';
        $out = $this->flattener->flatten($css);
        self::assertStringContainsString('@media (max-width: 600px)', $out);
        self::assertStringContainsString('.x { color: red; }', $out);
    }

    public function testCssWithoutLayersIsPassedThrough(): void
    {
        $css = '.a { color: red; } .b { background: blue; }';
        self::assertSame($css, $this->flattener->flatten($css));
    }
}
