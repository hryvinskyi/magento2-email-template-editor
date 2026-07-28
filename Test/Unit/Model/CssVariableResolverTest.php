<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Model\CssColorConverter;
use Hryvinskyi\EmailTemplateEditor\Model\Color\ColorMixer;
use Hryvinskyi\EmailTemplateEditor\Model\Color\ColorParser;
use Hryvinskyi\EmailTemplateEditor\Model\Css\CssStructureParser;
use Hryvinskyi\EmailTemplateEditor\Model\Css\CssSyntaxScanner;
use Hryvinskyi\EmailTemplateEditor\Model\CssVariableResolver;
use PHPUnit\Framework\TestCase;

class CssVariableResolverTest extends TestCase
{
    private CssVariableResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CssVariableResolver(
            new CssColorConverter(new ColorParser(), new ColorMixer()),
            new CssStructureParser(new CssSyntaxScanner()),
            new CssSyntaxScanner()
        );
    }

    public function testSimpleVariableSubstitution(): void
    {
        $css = '.x { --c: #f00; color: var(--c); }';
        $out = $this->resolver->resolve($css);
        self::assertStringContainsString('color: #f00', $out);
        self::assertStringNotContainsString('var(--c)', $out);
        self::assertStringNotContainsString('--c:', $out);
    }

    public function testVarWithFallbackUsesValueWhenDefined(): void
    {
        $css = '.x { --c: #f00; color: var(--c, blue); }';
        $out = $this->resolver->resolve($css);
        self::assertStringContainsString('color: #f00', $out);
    }

    public function testVarWithFallbackUsesFallbackWhenUndefined(): void
    {
        $css = '.x { color: var(--missing, blue); }';
        $out = $this->resolver->resolve($css);
        self::assertStringContainsString('color: blue', $out);
    }

    public function testVarWithoutFallbackOrDefinitionIsPreserved(): void
    {
        $css = '.x { color: var(--missing); }';
        $out = $this->resolver->resolve($css);
        self::assertStringContainsString('var(--missing)', $out);
    }

    /**
     * Tailwind v4 emits `var(--tw-blur,)` (note the trailing comma, no fallback after it)
     * inside compositional `filter`/`transform` declarations. The fallback should be
     * treated as empty, dropping the contribution when the var is undefined.
     */
    public function testEmptyFallbackResolvesToEmptyString(): void
    {
        $css = '.x { filter: var(--missing,) var(--also-missing,); }';
        $out = $this->resolver->resolve($css);
        // Both var() refs should collapse to empty.
        self::assertStringNotContainsString('var(--missing', $out);
        self::assertStringNotContainsString('var(--also-missing', $out);
    }

    public function testEmptyFallbackUsesDefinedValueWhenAvailable(): void
    {
        $css = '.x { --tw-invert: invert(100%); filter: var(--tw-invert,); }';
        $out = $this->resolver->resolve($css);
        self::assertStringContainsString('filter: invert(100%)', $out);
    }

    public function testChainedVariablesResolveTransitively(): void
    {
        $css = ':root { --a: red; --b: var(--a); } .x { color: var(--b); }';
        $out = $this->resolver->resolve($css);
        self::assertStringContainsString('color: red', $out);
    }

    /**
     * @link The bug fix that triggered this test: Tailwind v3 ".\\!bg-white" used to compile
     *       --tw-bg-opacity: 1 !important; background-color: rgb(255 255 255 / var(--tw-bg-opacity, 1)) !important;
     *       Stripping the !important flag from the *value* of the custom property is required so
     *       it isn't carried into the substituted rgb() call (which would be invalid CSS).
     */
    public function testImportantFlagIsStrippedFromCustomPropertyValue(): void
    {
        $css = <<<CSS
.x {
  --tw-bg-opacity: 1 !important;
  background-color: rgb(255 255 255 / var(--tw-bg-opacity, 1)) !important;
}
CSS;
        $out = $this->resolver->resolve($css);
        // The substituted value must NOT contain "!important" inside rgb().
        self::assertStringNotContainsString('1 !important)', $out);
        // The substituted, parsed color should be valid - rgba() form is the post-conversion shape.
        self::assertStringContainsString('rgba(255, 255, 255, 1)', $out);
        // The declaration's own !important flag is preserved.
        self::assertStringContainsString('!important', $out);
    }

    public function testRgbModernSyntaxIsConvertedToLegacyRgba(): void
    {
        $out = $this->resolver->resolve('.x { color: rgb(255 0 0 / 0.5); }');
        self::assertStringContainsString('rgba(255, 0, 0, 0.5)', $out);
    }

    public function testRgbWithoutAlphaConvertsToCommaSeparated(): void
    {
        $out = $this->resolver->resolve('.x { color: rgb(255 0 0); }');
        self::assertStringContainsString('rgb(255, 0, 0)', $out);
    }

    public function testHslModernSyntaxIsConvertedToLegacyHsla(): void
    {
        $out = $this->resolver->resolve('.x { color: hsl(200 50% 50% / 0.75); }');
        self::assertStringContainsString('hsla(200, 50%, 50%, 0.75)', $out);
    }

    public function testVariableDeclarationsAreRemovedFromOutput(): void
    {
        $css = '.x { --foo: red; color: var(--foo); }';
        $out = $this->resolver->resolve($css);
        self::assertStringNotContainsString('--foo:', $out);
    }

    public function testEmptyRuleBlocksAfterVariableRemovalAreDropped(): void
    {
        // The :root selector contains only variable declarations; after stripping it should vanish.
        $css = ":root { --a: red; --b: blue; }\n.x { color: var(--a); }";
        $out = $this->resolver->resolve($css);
        self::assertStringNotContainsString(':root', $out);
        self::assertStringContainsString('.x', $out);
    }

    public function testLastDeclarationInBlockWithoutTrailingSemicolonIsAlsoStripped(): void
    {
        $css = '.x { --a: red }';
        $out = $this->resolver->resolve($css);
        self::assertStringNotContainsString('--a', $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Scope. A custom property is resolved from the innermost rule that declares it for the
    //  rule being resolved, and a declaration confined to an at-rule stays confined to it.
    // ---------------------------------------------------------------------------------------

    /**
     * Tailwind v4 routes every `border-*` utility through the same `--tw-border-style` slot.
     * `.border-dashed` sets that slot for itself; a stylesheet-wide variable map turns the
     * single presence of that class into dashed borders on every element that carries any
     * `border-*` utility, whether or not it is also `.border-dashed`.
     */
    public function testDeclarationInOneRuleDoesNotReachAnother(): void
    {
        $css = <<<'CSS'
:root { --tw-border-style: solid; }
.border-2 { border-style: var(--tw-border-style); border-width: 2px; }
.border-dashed { --tw-border-style: dashed; border-style: var(--tw-border-style); }
CSS;
        $out = $this->resolver->resolve($css);

        self::assertStringContainsString('.border-2 { border-style: solid;', $out);
        self::assertStringContainsString('.border-dashed { border-style: dashed;', $out);
    }

    public function testRuleLocalDeclarationOutranksTheDocumentWideOne(): void
    {
        $out = $this->resolver->resolve(':root { --c: red; } .x { --c: blue; color: var(--c); }');

        self::assertStringContainsString('color: blue', $out);
        self::assertStringNotContainsString('red', $out);
    }

    public function testRootDeclarationReachesEveryRuleInTheSameRuleList(): void
    {
        $out = $this->resolver->resolve('.x { color: var(--c); } :root { --c: red; } .y { color: var(--c); }');

        self::assertStringContainsString('.x { color: red; }', $out);
        self::assertStringContainsString('.y { color: red; }', $out);
    }

    public function testLaterRootDeclarationWinsOverTheEarlierOne(): void
    {
        $out = $this->resolver->resolve(':root { --c: red; } :root { --c: blue; } .x { color: var(--c); }');

        self::assertStringContainsString('color: blue', $out);
    }

    /**
     * Tailwind v4's `@theme` block is compiled into `:root` by the real compiler, and the
     * editor stores the authored theme verbatim, so it has to define the same outermost scope.
     */
    public function testThemeBlockDeclaresForTheWholeStylesheet(): void
    {
        $out = $this->resolver->resolve("@theme { --color-primary: #131CCF; }\n.x { color: var(--color-primary); }");

        self::assertStringContainsString('color: #131CCF', $out);
        self::assertStringNotContainsString('@theme', $out);
    }

    public function testHostAndUniversalSelectorsAlsoDeclareForTheWholeStylesheet(): void
    {
        $out = $this->resolver->resolve(':root, :host { --a: red; } *, ::before { --b: 2px; }'
            . ' .x { color: var(--a); border-width: var(--b); }');

        self::assertStringContainsString('color: red', $out);
        self::assertStringContainsString('border-width: 2px', $out);
    }

    /**
     * The inlined email is the unconditional rendering: it is delivered once and every
     * recipient sees the same `style` attributes. A `--color-*` override under
     * `prefers-color-scheme: dark` therefore describes a rendering the message will never
     * have, and must not reach the rules outside its at-rule.
     */
    public function testDarkModeOverrideDoesNotEscapeItsMediaQuery(): void
    {
        $css = <<<'CSS'
:root { --color-bg: #ffffff; }
@media (prefers-color-scheme: dark) {
  :root { --color-bg: #000000; }
}
.card { background-color: var(--color-bg); }
CSS;
        $out = $this->resolver->resolve($css);

        self::assertStringContainsString('.card { background-color: #ffffff; }', $out);
        self::assertStringNotContainsString('#000000', $out);
    }

    public function testDeclarationInsideAMediaQueryStillAppliesToRulesInsideIt(): void
    {
        $css = <<<'CSS'
:root { --color-bg: #ffffff; }
@media (prefers-color-scheme: dark) {
  :root { --color-bg: #000000; }
  .card { background-color: var(--color-bg); }
}
CSS;
        $out = $this->resolver->resolve($css);

        self::assertStringContainsString('.card { background-color: #000000; }', $out);
    }

    public function testDeclarationInsideASupportsBlockDoesNotEscapeIt(): void
    {
        $css = <<<'CSS'
:root { --gap: 4px; }
@supports (display: grid) { :root { --gap: 16px; } }
.x { margin: var(--gap); }
CSS;
        $out = $this->resolver->resolve($css);

        self::assertStringContainsString('margin: 4px', $out);
        self::assertStringNotContainsString('16px', $out);
    }

    public function testNestedRuleSeesTheDeclarationsOfTheRuleContainingIt(): void
    {
        $out = $this->resolver->resolve('.card { --brand: red; & h1 { color: var(--brand); } }');

        self::assertStringContainsString('color: red', $out);
        self::assertStringNotContainsString('--brand', $out);
    }

    /**
     * A `data:` URI carries a semicolon inside its value. Cutting the declaration there leaves
     * the tail of the URI standing in the block as a fragment no parser can read, which costs
     * the element every declaration in that rule - not just the one that was truncated.
     */
    public function testSemicolonInsideACustomPropertyValueDoesNotTruncateIt(): void
    {
        $css = <<<'CSS'
.logo {
  --logo-image: url("data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=");
  background-image: var(--logo-image);
  color: red;
}
CSS;
        $expected = <<<'CSS'
.logo {
  background-image: url("data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=");
  color: red;
}
CSS;

        // Asserted whole: what breaks the rule is not only the truncated declaration but the
        // orphaned tail of the URI left standing beside it, which no CSS parser can read.
        self::assertSame($expected, $this->resolver->resolve($css));
    }

    public function testSemicolonInsideAnUnquotedUrlDoesNotTruncateTheValue(): void
    {
        $css = '.logo { --logo: url(data:image/svg+xml;base64,PHN2Zz4=); background-image: var(--logo); }';
        $out = $this->resolver->resolve($css);

        self::assertStringContainsString('background-image: url(data:image/svg+xml;base64,PHN2Zz4=)', $out);
    }

    public function testBraceInsideAStringDoesNotDisplaceTheFollowingRules(): void
    {
        $css = '.a::before { content: "}"; --c: red; } .b { color: var(--c, blue); }';
        $out = $this->resolver->resolve($css);

        self::assertStringContainsString('.a::before { content: "}"; }', $out);
        // `--c` belongs to `.a::before` alone, so `.b` falls back rather than borrowing it.
        self::assertStringContainsString('.b { color: blue; }', $out);
    }

    public function testUnresolvableReferenceWithoutFallbackIsLeftInPlaceRatherThanEmptied(): void
    {
        $out = $this->resolver->resolve('.a { --c: red; } .b { color: var(--c); }');

        self::assertStringContainsString('color: var(--c)', $out);
    }

    public function testSelfReferentialVariableDoesNotLoopForever(): void
    {
        $out = $this->resolver->resolve(':root { --a: var(--b); --b: var(--a); } .x { color: var(--a); }');

        self::assertStringContainsString('.x', $out);
    }

    public function testCommentedOutReferenceIsNotSubstituted(): void
    {
        $out = $this->resolver->resolve(':root { --c: red; } .x { color: blue; /* was var(--c) */ }');

        self::assertStringContainsString('/* was var(--c) */', $out);
    }

    public function testKeyframeStepDeclarationsAreScopedToTheirStep(): void
    {
        $css = '@keyframes fade { from { --o: 0; opacity: var(--o); } to { opacity: var(--o, 1); } }';
        $out = $this->resolver->resolve($css);

        self::assertStringContainsString('from { opacity: 0; }', $out);
        self::assertStringContainsString('to { opacity: 1; }', $out);
    }

    /**
     * The resolver reads bytes, not characters, so a stylesheet that is not valid UTF-8 is
     * still a stylesheet it can substitute into - it must never answer with an empty one.
     */
    public function testInvalidUtf8ByteDoesNotEmptyTheStylesheet(): void
    {
        $css = ":root { --c: red; } .x { content: \"\xB1\xC3\"; color: var(--c); }";

        self::assertFalse(mb_check_encoding($css, 'UTF-8'), 'The fixture must not be valid UTF-8');

        $out = $this->resolver->resolve($css);

        self::assertStringContainsString('color: red', $out);
    }

    public function testStatementAtRuleSurvivesTheRemovalOfTheBlockBehindIt(): void
    {
        $out = $this->resolver->resolve("@import url('a.css');\n:root { --a: red; }");

        self::assertStringContainsString("@import url('a.css');", $out);
        self::assertStringNotContainsString('--a', $out);
    }
}
