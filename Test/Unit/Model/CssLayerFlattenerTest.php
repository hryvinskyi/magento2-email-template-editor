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

    public function testMultipleLayerNamesAreUnwrappedWhenNoDropName(): void
    {
        $out = $this->flattener->flatten('@layer theme, utilities { .x { color: red; } }');
        self::assertStringContainsString('.x { color: red; }', $out);
        self::assertStringNotContainsString('@layer theme, utilities', $out);
    }

    public function testCommaListIncludingDropNameDropsBlock(): void
    {
        // A @layer block whose name list mentions `base` or `properties` is treated as
        // belonging to a drop layer (Tailwind v4 doesn't emit this shape, but it's the
        // safer interpretation since the rule was opted into a drop layer at all).
        $out = $this->flattener->flatten('@layer theme, base { .x { color: red; } }');
        self::assertStringNotContainsString('.x', $out);
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

    /**
     * Tailwind v4's `@layer base` contains an `@supports` chain with three levels of
     * nested at-rules (e.g. `@supports { ::placeholder { @supports { color: ... } } }`).
     * A non-recursive matcher caps at 2 levels of nesting and silently fails to match
     * the whole block - leaving preflight rules in the output where they get inlined
     * onto every email element (`display: block; max-width: 100%; …`).
     */
    public function testDeeplyNestedSupportsInsideLayerBaseStillDropped(): void
    {
        $css = <<<'CSS'
@layer base {
  *, ::after, ::before { box-sizing: border-box; margin: 0; }
  img, video { max-width: 100%; height: auto; }
  @supports (not (-webkit-appearance: -apple-pay-button)) or (contain-intrinsic-size: 1px) {
    ::placeholder {
      color: currentcolor;
      @supports (color: color-mix(in lab, red, red)) {
        color: color-mix(in oklab, currentcolor 50%, transparent);
      }
    }
  }
}
@layer utilities { .invert { filter: invert(100%); } }
CSS;
        $out = $this->flattener->flatten($css);

        self::assertStringNotContainsString('@layer base', $out);
        self::assertStringNotContainsString('box-sizing: border-box', $out);
        self::assertStringNotContainsString('max-width: 100%', $out);
        self::assertStringContainsString('.invert', $out);
    }
}
