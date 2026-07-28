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
use Hryvinskyi\EmailTemplateEditor\Model\CssImportantPromoter;
use Hryvinskyi\EmailTemplateEditor\Model\CssInliner;
use Hryvinskyi\EmailTemplateEditor\Model\CssLayerFlattener;
use Hryvinskyi\EmailTemplateEditor\Model\CssVariableResolver;
use Hryvinskyi\EmailTemplateEditor\Model\UtilityCssGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end pipeline coverage - the real path from a Tailwind v4 `@theme {}` block to
 * inlined HTML via Emogrifier. Goes through:
 *
 *   theme CSS  ─►  UtilityCssGenerator  ─►  CssVariableResolver (in CssInliner)  ─►
 *   Pelago\Emogrifier  ─►  inlined HTML
 *
 * If any one of these contracts drifts, this test breaks instead of waiting for a
 * user-visible regression in the editor.
 */
class CssInlinerIntegrationTest extends TestCase
{
    private UtilityCssGenerator $generator;
    private CssInliner $inliner;

    protected function setUp(): void
    {
        if (!class_exists(\DOMDocument::class)) {
            self::markTestSkipped('DOMDocument is required by Emogrifier.');
        }
        if (!class_exists(\Pelago\Emogrifier\CssInliner::class)) {
            self::markTestSkipped('Pelago\Emogrifier is not available in the test environment.');
        }

        $this->generator = new UtilityCssGenerator();
        $this->inliner = new CssInliner(
            new CssVariableResolver(new CssColorConverter(new ColorParser(), new ColorMixer())),
            new NullLogger(),
            new CssLayerFlattener(),
            new CssImportantPromoter()
        );
    }

    public function testImportantBgClassWinsOverElementRule(): void
    {
        // Mirrors the production composition: UtilityCssGenerator emits the theme's own
        // baseline rules verbatim first and appends the token-derived utilities after them,
        // so an equally specific utility wins on source order.
        $themeCss = "@theme { --color-primary: #131CCF; }\n.header { background-color: #153453; }";
        $css = $this->generator->generate($themeCss);

        $html = '<table><tr><td class="header !bg-primary">x</td></tr></table>';
        $out = $this->inliner->inline($html, null, null, $css);

        self::assertMatchesRegularExpression(
            '/<td[^>]*style="[^"]*background-color:\s*#131CCF/i',
            $out,
            'The !bg-primary override must win over .header background-color'
        );
    }

    /**
     * The plain (unprefixed) utility has to win over a baseline rule of the same specificity
     * too - the `!` modifier must not be the only thing that makes an override stick.
     */
    public function testPlainUtilityClassAlsoWinsOverElementRule(): void
    {
        $themeCss = "@theme { --color-primary: #131CCF; }\n.header { background-color: #153453; }";
        $css = $this->generator->generate($themeCss);

        $html = '<table><tr><td class="header bg-primary">x</td></tr></table>';
        $out = $this->inliner->inline($html, null, null, $css);

        self::assertMatchesRegularExpression('/<td[^>]*style="[^"]*background-color:\s*#131CCF/i', $out);
    }

    public function testTextColorTokenIsInlinedOnMatchingClass(): void
    {
        $themeCss = "@theme { --color-link: #007dbd; }";
        $css = $this->generator->generate($themeCss);
        $html = '<a class="text-link" href="#">link</a>';
        $out = $this->inliner->inline($html, null, null, $css);
        self::assertMatchesRegularExpression(
            '/<a[^>]*style="[^"]*color:\s*#007dbd/i',
            $out
        );
    }

    public function testSpacingTokenInlinedAsPadding(): void
    {
        $themeCss = "@theme { --spacing-4: 16px; }";
        $css = $this->generator->generate($themeCss);
        $html = '<div class="p-4">x</div>';
        $out = $this->inliner->inline($html, null, null, $css);
        self::assertMatchesRegularExpression(
            '/<div[^>]*style="[^"]*padding:\s*16px/i',
            $out
        );
    }

    public function testFontSizeTokenInlinedOnTextClass(): void
    {
        $themeCss = "@theme { --text-lg: 18px; }";
        $css = $this->generator->generate($themeCss);
        $html = '<p class="text-lg">x</p>';
        $out = $this->inliner->inline($html, null, null, $css);
        self::assertMatchesRegularExpression(
            '/<p[^>]*style="[^"]*font-size:\s*18px/i',
            $out
        );
    }

    public function testFontWeightAndFontFamilyShareNamespaceWithoutCrossPollination(): void
    {
        $themeCss = "@theme { --font-sans: Arial, sans-serif; --font-weight-bold: 700; }";
        $css = $this->generator->generate($themeCss);

        $weightHtml = '<span class="font-bold">x</span>';
        $weightOut = $this->inliner->inline($weightHtml, null, null, $css);
        self::assertMatchesRegularExpression('/style="[^"]*font-weight:\s*700/i', $weightOut);
        self::assertDoesNotMatchRegularExpression('/style="[^"]*font-family:\s*700/i', $weightOut);

        $familyHtml = '<span class="font-sans">x</span>';
        $familyOut = $this->inliner->inline($familyHtml, null, null, $css);
        self::assertMatchesRegularExpression('/style="[^"]*font-family:\s*Arial/i', $familyOut);
        self::assertDoesNotMatchRegularExpression('/style="[^"]*font-weight:\s*Arial/i', $familyOut);
    }

    public function testBorderRadiusAndBoxShadowInlinedFromTokens(): void
    {
        $themeCss = "@theme { --radius-md: 4px; --shadow-md: 0 4px 6px rgba(0,0,0,0.1); }";
        $css = $this->generator->generate($themeCss);
        $html = '<div class="rounded-md shadow-md">x</div>';
        $out = $this->inliner->inline($html, null, null, $css);
        self::assertMatchesRegularExpression('/style="[^"]*border-radius:\s*4px/i', $out);
        self::assertMatchesRegularExpression('/style="[^"]*box-shadow:[^"]*rgba/i', $out);
    }

    public function testCustomCssLayerIsAlsoInlined(): void
    {
        $html = '<a class="link">x</a>';
        $custom = '.link { color: hotpink; }';
        $out = $this->inliner->inline($html, $custom);
        self::assertMatchesRegularExpression('/style="[^"]*color:\s*hotpink/i', $out);
    }

    /**
     * The parts are concatenated theme → tailwind → custom, the same order
     * `EmailTemplatePlugin::buildCombinedCss` uses for real sends, so the preview resolves a
     * conflict between equally specific rules the way the delivered email will.
     */
    public function testCustomCssWinsOverThemeCssOnEqualSpecificity(): void
    {
        $html = '<a class="link">x</a>';
        $out = $this->inliner->inline($html, '.link { color: hotpink; }', null, '.link { color: teal; }');

        self::assertMatchesRegularExpression('/style="[^"]*color:\s*hotpink/i', $out);
        self::assertDoesNotMatchRegularExpression('/style="[^"]*color:\s*teal/i', $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Tailwind v4 browser-bundle output - the real shape with @layer wrappers, @property
    //  rules and per-property scope resets that the iframe sends to the server.
    // ---------------------------------------------------------------------------------------

    public function testTailwindV4LayerUtilitiesAreInlinedAfterFlattening(): void
    {
        // Without the @layer flattening step Emogrifier silently drops every rule wrapped in
        // @layer { … } - which is every Tailwind v4 utility. Cover the regression here.
        $css = <<<CSS
@layer utilities {
  .bg-token { background-color: #131CCF; }
  .\\!bg-token { background-color: #131CCF !important; }
}
CSS;
        $html = '<table><tr><td class="bg-token">x</td><td class="!bg-token">y</td></tr></table>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertMatchesRegularExpression('/<td class="bg-token"[^>]*style="[^"]*#131CCF/i', $out);
        self::assertMatchesRegularExpression('/<td class="!bg-token"[^>]*style="[^"]*#131CCF/i', $out);
    }

    public function testTailwindV4PreflightLayerIsDroppedFromInlining(): void
    {
        // @layer base contains preflight rules that match `*`, `html`, `body` -
        // applying them via inline styles would aggressively flatten table-based emails.
        $css = <<<CSS
@layer base {
  *, ::after, ::before { box-sizing: border-box; margin: 0; padding: 0; }
}
@layer utilities {
  .x { color: red; }
}
CSS;
        $html = '<div class="x"><p>untouched</p></div>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertMatchesRegularExpression('/class="x"[^>]*style="[^"]*color:\s*red/i', $out);
        // The <p> must NOT pick up box-sizing/margin/padding from preflight.
        self::assertDoesNotMatchRegularExpression('/<p[^>]*style="[^"]*box-sizing/i', $out);
        self::assertDoesNotMatchRegularExpression('/<p[^>]*style="[^"]*padding:\s*0/i', $out);
    }

    public function testTailwindV4InvertFilterResolvesLocalDeclarationOverInitial(): void
    {
        // The .invert rule sets `--tw-invert: invert(100%)` and uses it in the filter
        // composition. The @layer properties scope reset sets `--tw-invert: initial` on
        // every element. Without flatten-before-resolve, the resolver would pick up
        // `initial` instead of the local declaration; with it, only the per-rule value
        // survives and `.invert` inlines correctly.
        $css = <<<'CSS'
@layer utilities {
  .invert {
    --tw-invert: invert(100%);
    filter: var(--tw-blur,) var(--tw-brightness,) var(--tw-invert,) var(--tw-sepia,);
  }
}
@property --tw-invert { syntax: "*"; inherits: false; }
@layer properties {
  @supports ((-webkit-hyphens: none)) {
    *, ::before, ::after { --tw-invert: initial; --tw-blur: initial; --tw-brightness: initial; --tw-sepia: initial; }
  }
}
CSS;
        $html = '<img class="invert"/>';
        $out = $this->inliner->inline($html, null, $css);
        self::assertMatchesRegularExpression('/<img[^>]*style="[^"]*invert\(100%\)/i', $out);
    }

    /**
     * Reproduces the "included header" scenario: a parent email includes the header via
     * `{{template config_path="design/email/header_template"}}`. At runtime the plugin
     * embeds the header override's stored tailwind_css as a <style> block in the
     * processed sub-template - that block is wrapped in @layer utilities {…} and uses
     * var(--color-primary). The CssInliner must flatten + resolve embedded <style>
     * blocks (not just the CSS parameters) so the override classes still inline.
     */
    public function testEmbeddedStyleBlocksWithLayersAreFlattenedAndResolved(): void
    {
        $assembledHtml = <<<'HTML'
<table><tr><td class="header"><img class="invert"/></td></tr></table>
<style type="text/css">
@layer theme {
  :root, :host { --color-primary: #131CCF; }
}
@layer utilities {
  .invert {
    --tw-invert: invert(100%);
    filter: var(--tw-blur,) var(--tw-brightness,) var(--tw-invert,) var(--tw-sepia,);
  }
  .\!bg-primary { background-color: var(--color-primary) !important; }
}
@layer properties {
  @supports ((-webkit-hyphens: none)) {
    *, ::before, ::after { --tw-invert: initial; --tw-blur: initial; --tw-brightness: initial; --tw-sepia: initial; }
  }
}
</style>
<p class="!bg-primary">body</p>
HTML;

        // Parent template has no Tailwind classes itself; the editor sends no tailwind_css.
        $out = $this->inliner->inline($assembledHtml);

        self::assertMatchesRegularExpression(
            '/<img class="invert"[^>]*style="[^"]*filter:\s*invert\(100%\)/i',
            $out,
            'invert class from embedded <style> must inline filter property'
        );
        self::assertMatchesRegularExpression(
            '/<p[^>]*style="[^"]*background-color:\s*#131CCF/i',
            $out,
            '!bg-primary from embedded <style> must resolve --color-primary and inline'
        );
    }

    /**
     * `border-2` / `border-b` only ever emit `border-style: var(--tw-border-style)`; the
     * value itself lives in an `@property` registration. Both that rule and the
     * `@layer properties` fallback are dropped during flattening, so without harvesting the
     * registered `initial-value` the declaration inlines as an unresolvable `var()` - which
     * computes to `border-style: none` and renders no border at all.
     */
    public function testBorderStyleSlotResolvesFromPropertyInitialValue(): void
    {
        $css = <<<'CSS'
@layer utilities {
  .border-2 { border-style: var(--tw-border-style); border-width: 2px; }
  .border-b { border-bottom-style: var(--tw-border-style); border-bottom-width: 1px; }
}
@property --tw-border-style {
  syntax: "*";
  inherits: false;
  initial-value: solid;
}
@layer properties {
  @supports ((-webkit-hyphens: none)) {
    *, ::before, ::after, ::backdrop { --tw-border-style: solid; }
  }
}
CSS;
        $html = '<table><tr><td class="border-b border-2">x</td></tr></table>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertStringNotContainsString('var(--tw-border-style)', $out);
        self::assertMatchesRegularExpression('/<td[^>]*style="[^"]*border-style:\s*solid/i', $out);
        self::assertMatchesRegularExpression('/<td[^>]*style="[^"]*border-bottom-style:\s*solid/i', $out);
        self::assertMatchesRegularExpression('/<td[^>]*style="[^"]*border-width:\s*2px/i', $out);
    }

    /**
     * Tailwind v4's default palette lives behind `--color-*` variables holding `oklch()`
     * values, which Outlook, Yahoo and every pre-2023 client drop outright - for a colour
     * property that means falling back to `currentColor`. The pipeline converts them to sRGB
     * after variable substitution, so what lands inline is a plain hex.
     */
    public function testTailwindOklchPaletteColorsAreInlinedAsSrgb(): void
    {
        $css = <<<'CSS'
@layer theme {
  :root, :host { --color-gray-700: oklch(37.3% 0.034 259.733); }
}
@layer utilities {
  .border-gray-700 { border-color: var(--color-gray-700); }
}
CSS;
        $html = '<table><tr><td class="border-gray-700">x</td></tr></table>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertStringNotContainsString('oklch', $out);
        self::assertMatchesRegularExpression('/<td[^>]*style="[^"]*border-color:\s*#364153/i', $out);
    }

    /**
     * Magento's {{inlinecss}} directive writes css/email-inline.css into `style="…"`
     * attributes before this inliner ever runs, and Emogrifier re-applies pre-existing
     * inline styles after every stylesheet rule. A plain `.text-black` therefore used to
     * lose to the stock `a { color: … }` that had already been inlined. The editor's CSS is
     * promoted to `!important` so it wins - and Emogrifier strips the annotation again, so
     * the emitted style attribute stays clean.
     */
    public function testEditorCssBeatsAlreadyInlinedTemplateStyles(): void
    {
        $html = '<a class="text-black" href="#" style="color: #e9501c; text-decoration: none;">About Us</a>';
        $css = '@layer utilities { .text-black { color: #000000; } }';

        $out = $this->inliner->inline($html, null, $css);

        // Emogrifier's CSS parser normalises #000000 down to its short form.
        self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*color:\s*#(?:000|000000)\b/i', $out);
        self::assertDoesNotMatchRegularExpression('/<a[^>]*style="[^"]*color:\s*#e9501c/i', $out);
        self::assertStringNotContainsString('!important', $out);
        // Declarations the editor does not touch must survive untouched.
        self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*text-decoration:\s*none/i', $out);
    }

    /**
     * The same precedence rule has to hold for the CSS that arrives as an embedded
     * `<style>` block - the shape `EmailTemplatePlugin` produces for a header/footer
     * override pulled into a parent template via `{{template config_path="…"}}`. The plugin
     * promotes that block before embedding it, so it is already `!important` here.
     */
    public function testPromotedEmbeddedStyleBlockBeatsAlreadyInlinedTemplateStyles(): void
    {
        $assembledHtml = <<<'HTML'
<style type="text/css">
.text-black { color: #000000 !important; }
</style>
<a class="text-black" href="#" style="color: #e9501c; text-decoration: none;">About Us</a>
HTML;

        $out = $this->inliner->inline($assembledHtml);

        // Emogrifier's CSS parser normalises #000000 down to its short form.
        self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*color:\s*#(?:000|000000)\b/i', $out);
        self::assertDoesNotMatchRegularExpression('/<a[^>]*style="[^"]*color:\s*#e9501c/i', $out);
    }

    /**
     * Only the editor's own CSS is promoted. A base-template `<style>` block travelling in
     * the markup keeps stock precedence, so it must not start overriding the inline styles
     * Magento produced for templates that carry no editor CSS.
     */
    public function testBaseTemplateStyleBlocksAreNotPromoted(): void
    {
        $assembledHtml = <<<'HTML'
<style type="text/css">
a { color: #123456; }
</style>
<a href="#" style="color: #e9501c;">About Us</a>
HTML;

        $out = $this->inliner->inline($assembledHtml);

        self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*color:\s*#e9501c/i', $out);
    }

    /**
     * Emogrifier ranks a selector by counting `.`/`[`/`:` followed by a *word* character, so
     * every escaped Tailwind class (`.\!text-black`, `.p-\[10px\]`, `.w-1\/2`, `.p-1\.5`)
     * scored 1 - element-level - and was applied before any plain class rule instead of
     * after it. The inliner restates those selectors as `[class~="…"]` to restore the tie.
     */
    public function testEscapedUtilityClassOutranksAnEarlierPlainClassRule(): void
    {
        $css = <<<'CSS'
.header { padding: 30px; color: #153453; }
@layer utilities {
  .p-\[10px\] { padding: 10px; }
  .\!text-black { color: #000000 !important; }
}
CSS;
        $html = '<table><tr><td class="header p-[10px] !text-black">x</td></tr></table>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertMatchesRegularExpression('/<td[^>]*style="[^"]*padding:\s*10px/i', $out);
        self::assertDoesNotMatchRegularExpression('/<td[^>]*style="[^"]*padding:\s*30px/i', $out);
        self::assertMatchesRegularExpression('/<td[^>]*style="[^"]*color:\s*#(?:000|000000)\b/i', $out);
    }

    public function testEscapedFractionAndDecimalClassesStillMatchTheirElements(): void
    {
        $css = '@layer utilities { .w-1\/2 { width: 50%; } .p-1\.5 { padding: 6px; } }';
        $html = '<div class="w-1/2 p-1.5">x</div>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertMatchesRegularExpression('/<div[^>]*style="[^"]*width:\s*50%/i', $out);
        self::assertMatchesRegularExpression('/<div[^>]*style="[^"]*padding:\s*6px/i', $out);
    }

    public function testTailwindV4ThemeLayerVariablesAreResolved(): void
    {
        $css = <<<CSS
@layer theme {
  :root, :host { --color-primary: #131CCF; --spacing: 0.25rem; }
}
@layer utilities {
  .\\!bg-primary { background-color: var(--color-primary) !important; }
  .mb-11 { margin-bottom: calc(var(--spacing) * 11); }
}
CSS;
        $html = '<td class="!bg-primary mb-11">x</td>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertMatchesRegularExpression('/style="[^"]*background-color:\s*#131CCF/i', $out);
        self::assertMatchesRegularExpression('/style="[^"]*margin-bottom:\s*calc\(\.25rem\s*\*\s*11\)/i', $out);
    }
}
