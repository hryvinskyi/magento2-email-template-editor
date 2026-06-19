<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Model\UtilityCssGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Coverage matrix - server-side token → utility derivations.
 *
 * Tailwind v4 derives utilities from CSS variables on the fly in a browser/Node compiler,
 * but the Emogrifier-based server inliner needs explicit class selectors to match against
 * markup. This test exercises every token bucket the generator emits utilities for, the
 * `!important` variants, edge cases, and the legacy JSON fallback for unmigrated themes.
 *
 * The intentional gaps (not covered server-side, must be supplied via the client iframe
 * compiler or the override's stored tailwind_css cache):
 *  - Static utilities not derived from theme tokens, e.g. `flex`, `block`, `text-center`,
 *    `invert`, `rounded-full` without a matching --radius-full token.
 *  - Arbitrary values: `bg-[#hex]`, `p-[2rem]`.
 *  - Pseudo / responsive modifiers: `hover:`, `md:`, `dark:`.
 *  - Alpha modifier: `bg-primary/50`.
 *  - Negative values: `-mt-4`.
 */
class UtilityCssGeneratorTest extends TestCase
{
    private UtilityCssGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new UtilityCssGenerator();
    }

    // ---------------------------------------------------------------------------------------
    //  Input handling
    // ---------------------------------------------------------------------------------------

    public function testEmptyInputProducesEmptyOutput(): void
    {
        self::assertSame('', $this->generator->generate(''));
    }

    public function testWhitespaceOnlyInputProducesEmptyOutput(): void
    {
        self::assertSame('', $this->generator->generate("\n   \t\n"));
    }

    public function testInputWithoutThemeBlockIsPassedThrough(): void
    {
        $input = '.brand { background: red; }';
        self::assertStringContainsString($input, $this->generator->generate($input));
    }

    public function testThemeBlockIsPreservedVerbatim(): void
    {
        $css = "@theme {\n  --color-primary: #131CCF;\n}";
        $out = $this->generator->generate($css);
        self::assertStringContainsString('@theme {', $out);
        self::assertStringContainsString('--color-primary: #131CCF', $out);
    }

    public function testMultipleThemeBlocksAreAllScanned(): void
    {
        $css = "@theme { --color-a: #aaa; }\n@theme { --color-b: #bbb; }";
        $out = $this->generator->generate($css);
        self::assertStringContainsString('.bg-a', $out);
        self::assertStringContainsString('.bg-b', $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Colors → .text-X, .bg-X, .border-X, .outline-X (+ !)
    // ---------------------------------------------------------------------------------------

    /**
     * @dataProvider colorUtilityProvider
     * @param string[] $expected
     */
    public function testColorTokensEmitFullUtilityFamily(string $themeCss, array $expected): void
    {
        $out = $this->generator->generate($themeCss);
        foreach ($expected as $marker) {
            self::assertStringContainsString($marker, $out, 'Missing marker: ' . $marker);
        }
    }

    public static function colorUtilityProvider(): array
    {
        return [
            'hex color, all four utilities + ! variants' => [
                "@theme { --color-primary: #131CCF; }",
                [
                    '.text-primary {',
                    'color: #131CCF',
                    '.bg-primary {',
                    'background-color: #131CCF',
                    '.border-primary {',
                    'border-color: #131CCF',
                    '.outline-primary {',
                    'outline-color: #131CCF',
                    // ! variants - the leading dot is followed by `\!` which prints as "\!"
                    '.\!text-primary',
                    'color: #131CCF !important',
                    '.\!bg-primary',
                    'background-color: #131CCF !important',
                    '.\!border-primary',
                    '.\!outline-primary',
                ],
            ],
            'rgb modern syntax kept verbatim' => [
                "@theme { --color-alert: rgb(255 0 0); }",
                ['.bg-alert {', 'background-color: rgb(255 0 0)'],
            ],
            'CSS variable reference as a value is kept verbatim' => [
                "@theme { --color-link: var(--brand); }",
                ['.text-link {', 'color: var(--brand)'],
            ],
        ];
    }

    public function testColorVariantTokenWithCamelCaseIsSanitisedToHyphens(): void
    {
        // "textMuted" survives as a class name because letters/digits are preserved.
        $out = $this->generator->generate("@theme { --color-textMuted: #999; }");
        self::assertStringContainsString('.bg-textMuted', $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Spacing → .m/.mx/.my/.mt/.mr/.mb/.ml/.p/.px/.py/.pt/.pr/.pb/.pl/.w/.h (+ !)
    // ---------------------------------------------------------------------------------------

    public function testSpacingEmitsEverySupportedDirection(): void
    {
        $out = $this->generator->generate("@theme { --spacing-4: 16px; }");

        $prefixes = ['m', 'mx', 'my', 'mt', 'mr', 'mb', 'ml',
                     'p', 'px', 'py', 'pt', 'pr', 'pb', 'pl',
                     'w', 'h'];
        foreach ($prefixes as $prefix) {
            self::assertStringContainsString(".{$prefix}-4 {", $out, "Missing .{$prefix}-4");
            self::assertStringContainsString(".\\!{$prefix}-4 {", $out, "Missing !{$prefix}-4");
        }

        // Axis-only utilities expand to two CSS properties; single-side stays single
        self::assertStringContainsString('margin-left: 16px', $out);
        self::assertStringContainsString('margin-right: 16px', $out);
        self::assertStringContainsString('margin-top: 16px', $out);
        self::assertStringContainsString('margin-bottom: 16px', $out);
        self::assertStringContainsString('padding-top: 16px', $out);
        self::assertStringContainsString('padding-right: 16px', $out);
        self::assertStringContainsString('padding-bottom: 16px', $out);
        self::assertStringContainsString('padding-left: 16px', $out);
        self::assertStringContainsString('width: 16px', $out);
        self::assertStringContainsString('height: 16px', $out);
    }

    public function testSpacingImportantVariantCarriesImportant(): void
    {
        $out = $this->generator->generate("@theme { --spacing-4: 16px; }");
        self::assertMatchesRegularExpression(
            '/\.\\\\\!p-4\s*\{\s*padding:\s*16px\s*!important;/',
            $out
        );
    }

    // ---------------------------------------------------------------------------------------
    //  Typography: text (font-size), font (family), font-weight, leading, tracking
    // ---------------------------------------------------------------------------------------

    public function testFontSizeEmitsTextUtility(): void
    {
        $out = $this->generator->generate("@theme { --text-base: 16px; }");
        self::assertStringContainsString('.text-base {', $out);
        self::assertStringContainsString('font-size: 16px', $out);
        self::assertStringContainsString('.\!text-base', $out);
        self::assertStringContainsString('font-size: 16px !important', $out);
    }

    public function testFontFamilyEmitsFontUtility(): void
    {
        $out = $this->generator->generate("@theme { --font-sans: Arial, sans-serif; }");
        self::assertStringContainsString('.font-sans {', $out);
        self::assertStringContainsString('font-family: Arial, sans-serif', $out);
        self::assertStringContainsString('.\!font-sans', $out);
    }

    public function testFontWeightEmitsFontUtility(): void
    {
        $out = $this->generator->generate("@theme { --font-weight-bold: 700; }");
        self::assertStringContainsString('.font-bold {', $out);
        self::assertStringContainsString('font-weight: 700', $out);
        self::assertStringContainsString('.\!font-bold', $out);
    }

    public function testFontFamilyExtractionExcludesFontWeightTokens(): void
    {
        // When both buckets are present, weight tokens must not bleed into the family
        // selector list - i.e. there should be NO `.font-weight-bold` selector.
        $out = $this->generator->generate(
            "@theme { --font-sans: Arial; --font-weight-bold: 700; }"
        );
        self::assertStringContainsString('.font-sans {', $out);
        self::assertStringContainsString('font-family: Arial', $out);
        self::assertStringContainsString('.font-bold {', $out);
        self::assertStringContainsString('font-weight: 700', $out);
        self::assertStringNotContainsString('.font-weight-bold {', $out);
        self::assertStringNotContainsString('font-family: 700', $out);
    }

    public function testLineHeightEmitsLeadingUtility(): void
    {
        $out = $this->generator->generate("@theme { --leading-tight: 1.25; }");
        self::assertStringContainsString('.leading-tight {', $out);
        self::assertStringContainsString('line-height: 1.25', $out);
        self::assertStringContainsString('.\!leading-tight', $out);
    }

    public function testLetterSpacingEmitsTrackingUtility(): void
    {
        $out = $this->generator->generate("@theme { --tracking-wide: 0.05em; }");
        self::assertStringContainsString('.tracking-wide {', $out);
        self::assertStringContainsString('letter-spacing: 0.05em', $out);
        self::assertStringContainsString('.\!tracking-wide', $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Borders, shadows, opacity, z-index, max-width
    // ---------------------------------------------------------------------------------------

    public function testBorderRadiusEmitsRoundedUtility(): void
    {
        $out = $this->generator->generate("@theme { --radius-md: 4px; }");
        self::assertStringContainsString('.rounded-md {', $out);
        self::assertStringContainsString('border-radius: 4px', $out);
        self::assertStringContainsString('.\!rounded-md', $out);
    }

    public function testBoxShadowEmitsShadowUtility(): void
    {
        $out = $this->generator->generate("@theme { --shadow-lg: 0 10px 15px rgba(0,0,0,0.1); }");
        self::assertStringContainsString('.shadow-lg {', $out);
        self::assertStringContainsString('box-shadow: 0 10px 15px rgba(0,0,0,0.1)', $out);
        self::assertStringContainsString('.\!shadow-lg', $out);
    }

    public function testOpacityEmitsOpacityUtility(): void
    {
        $out = $this->generator->generate("@theme { --opacity-50: 0.5; }");
        self::assertStringContainsString('.opacity-50 {', $out);
        self::assertStringContainsString('opacity: 0.5', $out);
        self::assertStringContainsString('.\!opacity-50', $out);
    }

    public function testZIndexEmitsZUtility(): void
    {
        $out = $this->generator->generate("@theme { --z-top: 999; }");
        self::assertStringContainsString('.z-top {', $out);
        self::assertStringContainsString('z-index: 999', $out);
        self::assertStringContainsString('.\!z-top', $out);
    }

    public function testMaxWidthEmitsMaxWUtility(): void
    {
        $out = $this->generator->generate("@theme { --container-md: 28rem; }");
        self::assertStringContainsString('.max-w-md {', $out);
        self::assertStringContainsString('max-width: 28rem', $out);
        self::assertStringContainsString('.\!max-w-md', $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Sanitisation
    // ---------------------------------------------------------------------------------------

    public function testValueSanitisationStripsSemicolonsBracesAndPreservesContent(): void
    {
        $out = $this->generator->generate("@theme { --color-x: red ;};");
        // Semicolons inside the value would break the declaration; the generator strips them.
        self::assertStringNotContainsString('color: red ;', $out);
        self::assertStringContainsString('.bg-x', $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Legacy JSON fallback
    // ---------------------------------------------------------------------------------------

    public function testLegacyJsonInputStillEmitsThemeAndDerivedUtilities(): void
    {
        $json = json_encode([
            'tokens' => [
                'colors' => ['primary' => '#131CCF'],
                'spacing' => ['4' => '16px'],
                'fontSize' => ['base' => '16px'],
            ],
            'elements' => ['body' => ['color' => '#333']],
            'utilities' => ['my-btn' => ['background' => '#000']],
        ]);

        $out = $this->generator->generate($json);

        self::assertStringContainsString('@theme {', $out);
        self::assertStringContainsString('--color-primary: #131CCF', $out);
        self::assertStringContainsString('--spacing-4: 16px', $out);
        self::assertStringContainsString('--text-base: 16px', $out);
        self::assertStringContainsString('.bg-primary {', $out);
        self::assertStringContainsString('.p-4 {', $out);
        self::assertStringContainsString('.text-base {', $out);
        // legacy `elements` and `utilities` blocks still produce direct selectors
        self::assertStringContainsString('body {', $out);
        self::assertStringContainsString('.my-btn {', $out);
    }

    public function testLegacyGoogleFontsBecomeImportRules(): void
    {
        $json = json_encode([
            'tokens' => ['googleFonts' => ['Inter']],
        ]);
        $out = $this->generator->generate($json);
        self::assertStringContainsString("@import url('https://fonts.googleapis.com/css2?family=Inter", $out);
    }

    public function testMalformedJsonStringIsTreatedAsCss(): void
    {
        // The auto-detect only kicks in for parseable JSON with the recognised top-level keys;
        // anything else gets passed through as CSS.
        $broken = '{ not valid json';
        $out = $this->generator->generate($broken);
        self::assertStringContainsString($broken, $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Cross-section combinations - integration of multiple buckets
    // ---------------------------------------------------------------------------------------

    public function testRealisticThemeProducesAllExpectedUtilityFamilies(): void
    {
        $theme = <<<CSS
@theme {
  --color-primary: #131CCF;
  --color-bodyBg: #f5f5f5;
  --spacing-4: 16px;
  --text-base: 16px;
  --font-sans: 'Helvetica Neue', Arial, sans-serif;
  --font-weight-bold: 700;
  --leading-normal: 1.5;
  --tracking-wide: 0.05em;
  --radius-md: 4px;
  --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
  --opacity-75: 0.75;
  --z-modal: 1000;
  --container-md: 28rem;
}
CSS;

        $out = $this->generator->generate($theme);

        $markers = [
            '.bg-primary {', '.\!bg-primary',
            '.p-4 {',        '.\!p-4',
            '.text-base {',  'font-size: 16px',
            '.font-sans {',  'font-family: \'Helvetica Neue\', Arial, sans-serif',
            '.font-bold {',  'font-weight: 700',
            '.leading-normal {',
            '.tracking-wide {',
            '.rounded-md {',
            '.shadow-md {',
            '.opacity-75 {',
            '.z-modal {',
            '.max-w-md {',
        ];
        foreach ($markers as $marker) {
            self::assertStringContainsString($marker, $out, "Missing marker: $marker");
        }
    }
}
