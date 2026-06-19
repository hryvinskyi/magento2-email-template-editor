<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Model\CssVariableResolver;
use PHPUnit\Framework\TestCase;

class CssVariableResolverTest extends TestCase
{
    private CssVariableResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CssVariableResolver();
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
}
