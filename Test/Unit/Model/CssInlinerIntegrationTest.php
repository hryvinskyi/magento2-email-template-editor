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

        $syntaxScanner = new CssSyntaxScanner();
        $structureParser = new CssStructureParser($syntaxScanner);

        $this->generator = new UtilityCssGenerator(new CssStructureParser(new CssSyntaxScanner()), new CssSyntaxScanner());
        $this->inliner = new CssInliner(
            new CssVariableResolver(
                new CssColorConverter(new ColorParser(), new ColorMixer()),
                $structureParser,
                $syntaxScanner
            ),
            new NullLogger(),
            new CssLayerFlattener($structureParser),
            new CssImportantPromoter($structureParser)
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

    // ---------------------------------------------------------------------------------------
    //  Custom-property scope, end to end. A `var()` is only replaceable by a literal if the
    //  replacement is the one the recipient's client would have computed for that element.
    // ---------------------------------------------------------------------------------------

    /**
     * An email is inlined once and delivered to everyone, so the only rendering it can express
     * is the unconditional one. A `--color-*` override under `prefers-color-scheme: dark`
     * describes a rendering this message will never have; if it reaches the rules outside the
     * media query, every recipient - on a light client too - receives the dark palette.
     */
    public function testDarkModeVariableOverrideDoesNotReachTheInlinedStyles(): void
    {
        $css = <<<'CSS'
@layer theme {
  :root, :host { --color-bg: #ffffff; }
}
@layer utilities {
  .card { background-color: var(--color-bg); }
}
@media (prefers-color-scheme: dark) {
  :root, :host { --color-bg: #000000; }
}
CSS;
        $html = '<table><tr><td class="card">x</td></tr></table>';
        $out = $this->inliner->inline($html, null, $css);

        // Emogrifier's CSS parser normalises #ffffff down to its short form.
        self::assertMatchesRegularExpression(
            '/<td[^>]*style="[^"]*background-color:\s*#(?:fff|ffffff)\b/i',
            $out
        );
        self::assertDoesNotMatchRegularExpression('/background-color:\s*#(?:000|000000)\b/i', $out);
    }

    /**
     * A `data:` URI held in a custom property carries a semicolon inside its value. Cutting
     * the declaration there both truncates the URI and leaves its tail standing in the block
     * as a fragment, which costs the element every declaration in that rule - here the colour
     * too, not only the image that was truncated.
     */
    public function testDataUriHeldInACustomPropertyIsInlinedWhole(): void
    {
        $custom = <<<'CSS'
.logo {
  --logo-image: url("data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=");
  background-image: var(--logo-image);
  color: #336699;
}
CSS;
        $html = '<div class="logo">x</div>';
        $out = $this->inliner->inline($html, $custom);

        self::assertStringContainsString(
            'background-image: url("data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=")',
            $out
        );
        // Emogrifier's CSS parser normalises #336699 down to its short form.
        self::assertMatchesRegularExpression('/<div[^>]*style=[^>]*color:\s*#(?:369|336699)\b/i', $out);
    }

    /**
     * Tailwind v4 routes every `border-*` utility through one `--tw-border-style` slot, and
     * `.border-dashed` is simply the rule that sets it. Resolving that slot per stylesheet
     * rather than per rule makes the presence of `.border-dashed` anywhere in the email turn
     * every other bordered element dashed as well.
     */
    public function testBorderStyleSlotIsResolvedPerRuleNotPerStylesheet(): void
    {
        $css = <<<'CSS'
@layer utilities {
  .border-2 { border-style: var(--tw-border-style); border-width: 2px; }
  .border-dashed { --tw-border-style: dashed; border-style: var(--tw-border-style); }
}
@property --tw-border-style {
  syntax: "*";
  inherits: false;
  initial-value: solid;
}
CSS;
        $html = '<table><tr><td class="border-2">a</td><td class="border-dashed">b</td></tr></table>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertMatchesRegularExpression(
            '/<td class="border-2"[^>]*style="[^"]*border-style:\s*solid/i',
            $out
        );
        self::assertMatchesRegularExpression(
            '/<td class="border-dashed"[^>]*style="[^"]*border-style:\s*dashed/i',
            $out
        );
    }

    /**
     * A brace inside a `content` string is not a block boundary. Counting it as one leaves the
     * `@layer` wrapper standing, and Emogrifier drops every rule still wrapped in a layer - so
     * one decorative pseudo-element strips the whole utility stylesheet out of the email.
     */
    public function testBraceInsideAContentStringDoesNotCostTheStylesheetItsOtherRules(): void
    {
        $css = <<<'CSS'
@layer utilities {
  .marker::before { content: "{"; }
  .after { color: #a10c0c; }
}
CSS;
        $html = '<div class="marker">a</div><div class="after">b</div>';
        $out = $this->inliner->inline($html, null, $css);

        self::assertMatchesRegularExpression('/<div class="after"[^>]*style="[^"]*color:\s*#a10c0c/i', $out);
    }

    /**
     * Custom CSS is pasted, and pasted bytes are not always valid UTF-8. A pattern that
     * refuses to run on such a subject reports no result, and a `<style>` block rewritten from
     * that result is empty - the rules the block carried for media queries and pseudo-elements,
     * which no inliner can express as a `style` attribute in the first place, are simply gone
     * from the delivered email with nothing to say why. Passing the block through untouched
     * leaves those rules where the client can still read them.
     */
    public function testInvalidUtf8ByteInAStyleBlockDoesNotEmptyThatBlock(): void
    {
        $html = "<style type=\"text/css\">@media (max-width: 600px)"
            . " { .x { content: \"\xB1\xC3\"; color: #a10c0c; } }</style>\n<div class=\"x\">a</div>";

        self::assertFalse(mb_check_encoding($html, 'UTF-8'), 'The fixture must not be valid UTF-8');

        $out = $this->inliner->inline($html);

        self::assertStringContainsString('@media (max-width: 600px)', $out);
        self::assertStringContainsString('color: #a10c0c', $out);
    }
}
