<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Model\Css\CssStructureParser;
use Hryvinskyi\EmailTemplateEditor\Model\Css\CssSyntaxScanner;
use Hryvinskyi\EmailTemplateEditor\Model\CssLayerFlattener;
use PHPUnit\Framework\TestCase;

class CssLayerFlattenerTest extends TestCase
{
    private CssLayerFlattener $flattener;

    protected function setUp(): void
    {
        $this->flattener = new CssLayerFlattener(new CssStructureParser(new CssSyntaxScanner()));
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

    public function testPropertyInitialValueIsHoistedIntoRootBlock(): void
    {
        $css = '@layer utilities { .border-2 { border-style: var(--tw-border-style); border-width: 2px; } }
                @property --tw-border-style { syntax: "*"; inherits: false; initial-value: solid; }';
        $out = $this->flattener->flatten($css);

        self::assertStringNotContainsString('@property', $out);
        self::assertStringContainsString('--tw-border-style: solid;', $out);
        // Must lead the output so per-utility declarations still win in the resolver's map.
        self::assertStringStartsWith(':root{', $out);
    }

    public function testPropertyWithoutInitialValueHoistsNothing(): void
    {
        $css = '@property --tw-invert { syntax: "*"; inherits: false; }
                .keep { color: red; }';
        $out = $this->flattener->flatten($css);

        self::assertStringNotContainsString(':root{', $out);
        self::assertStringContainsString('.keep', $out);
    }

    public function testHoistedDefaultDoesNotOverrideAPerRuleDeclaration(): void
    {
        $css = '@property --tw-border-style { syntax: "*"; inherits: false; initial-value: solid; }
                @layer utilities { .border-dashed { --tw-border-style: dashed; border-style: dashed; } }';
        $out = $this->flattener->flatten($css);

        self::assertLessThan(
            strpos($out, '--tw-border-style: dashed'),
            strpos($out, '--tw-border-style: solid'),
            'The registered default must precede per-rule declarations (the resolver is last-wins)'
        );
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

    /**
     * An author writing `content: "{"` puts a brace in the stylesheet that carries no
     * structural meaning. Counting it leaves the layer wrapper in place, and Emogrifier
     * silently drops every rule still wrapped in `@layer` - so one decorative pseudo-element
     * costs the email its entire utility stylesheet.
     */
    public function testOpeningBraceInsideAStringStillLeavesTheLayerUnwrapped(): void
    {
        $css = <<<'CSS'
@layer utilities {
  .marker::before { content: "{"; }
  .after { color: red; }
}
CSS;
        $out = $this->flattener->flatten($css);

        self::assertStringNotContainsString('@layer', $out);
        self::assertStringContainsString('.marker::before { content: "{"; }', $out);
        self::assertStringContainsString('.after { color: red; }', $out);
    }

    /**
     * The mirror case cuts the layer short instead: the rules after the string end up outside
     * the block that was supposed to contain them, and a stray closing brace is left behind.
     */
    public function testClosingBraceInsideAStringDoesNotCutTheLayerShort(): void
    {
        $css = <<<'CSS'
@layer utilities {
  .marker::before { content: "}"; color: blue; }
  .after { color: red; }
}
.keep { color: green; }
CSS;
        $out = $this->flattener->flatten($css);

        self::assertStringContainsString('.marker::before { content: "}"; color: blue; }', $out);
        self::assertStringContainsString('.after { color: red; }', $out);
        self::assertStringContainsString('.keep { color: green; }', $out);
        // No orphaned brace may be left where the layer wrapper used to close.
        self::assertStringNotContainsString('}
.keep', $out);
    }

    public function testBraceInsideACommentDoesNotCutTheLayerShort(): void
    {
        $css = '@layer utilities { /* } */ .after { color: red; } }';
        $out = $this->flattener->flatten($css);

        self::assertStringNotContainsString('@layer', $out);
        self::assertStringContainsString('.after { color: red; }', $out);
    }

    /**
     * A stylesheet is bytes, and a pasted custom-CSS block can carry a byte sequence that is
     * not valid UTF-8 - most often from a font-icon `content` value copied out of a legacy
     * editor. A `/u` pattern refuses to run on such a subject and answers "no result", which
     * cast to a string is an empty stylesheet: the email goes out with no styling at all and
     * nothing to say why. Losing the layer unwrapping would be bad; losing everything is worse.
     */
    public function testInvalidUtf8ByteDoesNotEmptyTheStylesheet(): void
    {
        $css = "@layer utilities { .x { content: \"\xB1\xC3\"; color: red; } }";

        self::assertFalse(mb_check_encoding($css, 'UTF-8'), 'The fixture must not be valid UTF-8');

        $out = $this->flattener->flatten($css);

        self::assertStringNotContainsString('@layer', $out);
        self::assertStringContainsString('color: red', $out);
    }

    public function testStatementFormLayerIsDroppedWithoutTakingTheImportBeforeItAlong(): void
    {
        $css = "@import url('a.css');\n@layer theme, utilities;\n.keep { color: red; }";
        $out = $this->flattener->flatten($css);

        self::assertStringContainsString("@import url('a.css');", $out);
        self::assertStringNotContainsString('@layer', $out);
        self::assertStringContainsString('.keep { color: red; }', $out);
    }

    public function testPropertyRegisteredInsideADroppedLayerStillContributesItsDefault(): void
    {
        $css = <<<'CSS'
@layer properties {
  @property --tw-border-style { syntax: "*"; inherits: false; initial-value: solid; }
}
@layer utilities { .border-2 { border-style: var(--tw-border-style); } }
CSS;
        $out = $this->flattener->flatten($css);

        self::assertStringStartsWith(':root{', $out);
        self::assertStringContainsString('--tw-border-style: solid;', $out);
    }

    public function testSemicolonInsideAPropertyDescriptorValueDoesNotSplitTheInitialValue(): void
    {
        $css = '@property --tw-content { syntax: "*"; initial-value: url(data:image/svg+xml;base64,AAA); }'
            . ' .x { content: var(--tw-content); }';
        $out = $this->flattener->flatten($css);

        self::assertStringContainsString('--tw-content: url(data:image/svg+xml;base64,AAA);', $out);
    }
}
