<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

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
            new CssVariableResolver(),
            new NullLogger(),
            new CssLayerFlattener()
        );
    }

    public function testImportantBgClassWinsOverElementRule(): void
    {
        $themeCss = "@theme { --color-primary: #131CCF; }";
        $css = $this->generator->generate($themeCss)
            . "\n.header { background-color: #153453; }";

        $html = '<table><tr><td class="header !bg-primary">x</td></tr></table>';
        $out = $this->inliner->inline($html, null, null, $css);

        self::assertMatchesRegularExpression(
            '/<td[^>]*style="[^"]*background-color:\s*#131CCF/i',
            $out,
            'The !bg-primary override must win over .header background-color'
        );
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
