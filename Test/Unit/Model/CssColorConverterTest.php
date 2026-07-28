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
use PHPUnit\Framework\TestCase;

class CssColorConverterTest extends TestCase
{
    private CssColorConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CssColorConverter(new ColorParser(), new ColorMixer());
    }

    /**
     * The whole Tailwind v4 default palette is authored in OKLCH, so the conversion has to
     * land on the same sRGB values Tailwind itself documents for those tokens.
     *
     * @param string $oklch The palette entry as Tailwind emits it
     * @param string $expectedHex The hex Tailwind documents for that token
     * @param string $token Token name, for the failure message
     * @return void
     * @dataProvider tailwindPaletteProvider
     */
    public function testTailwindPaletteColorsConvertToTheirDocumentedHex(
        string $oklch,
        string $expectedHex,
        string $token
    ): void {
        self::assertSame($expectedHex, strtolower($this->converter->toLegacy($oklch)), $token);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function tailwindPaletteProvider(): array
    {
        return [
            'gray-700' => ['oklch(37.3% 0.034 259.733)', '#364153', 'gray-700'],
            'gray-100' => ['oklch(96.7% 0.003 264.542)', '#f3f4f6', 'gray-100'],
            'red-500' => ['oklch(63.7% 0.237 25.331)', '#fb2c36', 'red-500'],
            'blue-500' => ['oklch(62.3% 0.214 259.815)', '#2b7fff', 'blue-500'],
            'black' => ['oklch(0% 0 0)', '#000000', 'black'],
            'white' => ['oklch(100% 0 0)', '#ffffff', 'white'],
        ];
    }

    public function testUnitlessLightnessIsTreatedAsAFractionNotAPercentage(): void
    {
        self::assertSame('#364153', $this->converter->toLegacy('oklch(.373 .034 259.733)'));
    }

    public function testAlphaBelowOneProducesRgba(): void
    {
        self::assertSame('rgba(54, 65, 83, 0.5)', $this->converter->toLegacy('oklch(37.3% 0.034 259.733 / 0.5)'));
    }

    public function testPercentageAlphaIsNormalised(): void
    {
        self::assertSame('rgba(54, 65, 83, 0.5)', $this->converter->toLegacy('oklch(37.3% .034 259.733 / 50%)'));
    }

    public function testOklabIsConvertedThroughTheSameMatrices(): void
    {
        // The cartesian form of oklch(.373 .034 259.733).
        self::assertSame('#364153', $this->converter->toLegacy('oklab(0.373 -0.0063 -0.0335)'));
    }

    public function testHueUnitsAreHonoured(): void
    {
        $degrees = $this->converter->toLegacy('oklch(63.7% 0.237 25.331)');

        self::assertSame($degrees, $this->converter->toLegacy('oklch(63.7% 0.237 0.0703638889turn)'));
        self::assertSame($degrees, $this->converter->toLegacy('oklch(63.7% 0.237 28.1455556grad)'));
    }

    public function testNoneKeywordIsTreatedAsZero(): void
    {
        self::assertSame('#000000', $this->converter->toLegacy('oklch(none none none)'));
    }

    public function testOutOfGamutColorsAreClampedRatherThanEmittingNan(): void
    {
        $out = $this->converter->toLegacy('oklch(60% 0.4 150)');

        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $out);
        self::assertStringNotContainsString('nan', strtolower($out));
    }

    public function testRelativeColorSyntaxIsLeftUntouched(): void
    {
        $css = 'oklch(from var(--brand) l c h)';

        self::assertSame($css, $this->converter->toLegacy($css));
    }

    public function testCalcChannelsAreLeftUntouched(): void
    {
        $css = 'oklch(calc(50% * 2) 0.1 200)';

        self::assertSame($css, $this->converter->toLegacy($css));
    }

    public function testConversionHappensInPlaceInsideDeclarations(): void
    {
        $out = $this->converter->toLegacy('.x { border-color: oklch(63.7% 0.237 25.331); color: red; }');

        self::assertSame('.x { border-color: #fb2c36; color: red; }', $out);
    }

    public function testCssWithoutOkColorsIsReturnedUnchanged(): void
    {
        $css = '.x { color: #fff; background: rgba(0, 0, 0, 0.5); }';

        self::assertSame($css, $this->converter->toLegacy($css));
    }

    // ---------------------------------------------------------------------------------------
    //  color-mix()
    // ---------------------------------------------------------------------------------------

    /**
     * The shape Tailwind v4 compiles every opacity modifier into (`bg-red-500/50`).
     *
     * Mixing with `transparent` is exact regardless of the interpolation space: CSS Color 5
     * interpolates with premultiplied alpha, so a fully transparent operand contributes
     * nothing to the colour channels and the result is the source colour at that alpha.
     */
    public function testOpacityModifierShapeResolvesToTheSourceColorAtThatAlpha(): void
    {
        self::assertSame(
            'rgba(251, 44, 54, 0.5)',
            $this->converter->toLegacy('color-mix(in oklab, #fb2c36 50%, transparent)')
        );
    }

    public function testOpacityModifierResolvesThroughAnInnerOklchColor(): void
    {
        self::assertSame(
            'rgba(251, 44, 54, 0.5)',
            $this->converter->toLegacy('color-mix(in oklab, oklch(63.7% 0.237 25.331) 50%, transparent)')
        );
    }

    public function testSrgbInterpolationOfTwoOpaqueColors(): void
    {
        self::assertSame('#800080', $this->converter->toLegacy('color-mix(in srgb, red, blue)'));
        self::assertSame('#ffbfbf', $this->converter->toLegacy('color-mix(in srgb, red 25%, white)'));
        self::assertSame('#808080', $this->converter->toLegacy('color-mix(in srgb, #fff, #000)'));
    }

    /**
     * OKLab is perceptually uniform, so its midpoint differs from the naive sRGB one - that
     * difference is the whole reason Tailwind interpolates there.
     */
    public function testOklabInterpolationDiffersFromSrgb(): void
    {
        $oklab = $this->converter->toLegacy('color-mix(in oklab, red, blue)');

        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $oklab);
        self::assertNotSame($this->converter->toLegacy('color-mix(in srgb, red, blue)'), $oklab);
    }

    public function testPolarInterpolationHonoursTheHueMethod(): void
    {
        $shorter = $this->converter->toLegacy('color-mix(in oklch, red, blue)');
        $longer = $this->converter->toLegacy('color-mix(in oklch longer hue, red, blue)');

        self::assertNotSame($shorter, $longer);
    }

    /**
     * Per CSS Color 5, percentages that add up to less than 100% scale the result's alpha by
     * the shortfall.
     */
    public function testPercentagesSummingBelowOneHundredApplyAnAlphaMultiplier(): void
    {
        self::assertSame(
            'rgba(128, 0, 128, 0.4)',
            $this->converter->toLegacy('color-mix(in srgb, red 20%, blue 20%)')
        );
    }

    public function testAlphaOfBothOperandsIsCarriedIntoTheMix(): void
    {
        $out = $this->converter->toLegacy('color-mix(in srgb, rgb(255, 0, 0) 30%, rgba(0, 0, 255, 0.5))');

        self::assertSame('rgba(118, 0, 137, 0.65)', $out);
    }

    public function testUnresolvableOperandsLeaveTheMixUntouched(): void
    {
        foreach ([
            'color-mix(in oklab, var(--brand) 50%, transparent)',
            'color-mix(in oklab, currentcolor 50%, transparent)',
        ] as $css) {
            self::assertSame($css, $this->converter->toLegacy($css));
        }
    }

    /**
     * Interpolating in CIE Lab needs a D50 XYZ round trip this converter does not implement;
     * leaving the declaration alone beats emitting a colour that is quietly wrong.
     */
    public function testUnsupportedInterpolationSpaceIsLeftUntouched(): void
    {
        $css = 'color-mix(in lab, red, blue)';

        self::assertSame($css, $this->converter->toLegacy($css));
    }

    public function testMixIsResolvedInPlaceInsideADeclaration(): void
    {
        $out = $this->converter->toLegacy(
            '.x { background-color: color-mix(in oklab, oklch(62.3% .214 259.815) 50%, transparent); }'
        );

        self::assertSame('.x { background-color: rgba(43, 127, 255, 0.5); }', $out);
    }

    public function testNestedMixesResolveOuterAfterInner(): void
    {
        $out = $this->converter->toLegacy(
            'color-mix(in oklab, color-mix(in srgb, red, blue) 50%, transparent)'
        );

        self::assertSame('rgba(128, 0, 128, 0.5)', $out);
    }

    // ---------------------------------------------------------------------------------------
    //  Modern space-separated rgb()/hsl()
    // ---------------------------------------------------------------------------------------

    public function testModernRgbBecomesCommaSeparated(): void
    {
        self::assertSame('rgb(255, 0, 0)', $this->converter->toLegacy('rgb(255 0 0)'));
    }

    public function testModernRgbWithAlphaBecomesRgba(): void
    {
        self::assertSame('rgba(255, 0, 0, 0.5)', $this->converter->toLegacy('rgb(255 0 0 / 0.5)'));
    }

    /**
     * Minified Tailwind output writes alphas as `/ .5`; a `\d+(\.\d+)?` channel pattern
     * misses the leading-dot form and leaves the whole modern-syntax colour in place.
     */
    public function testLeadingDotAlphaIsConverted(): void
    {
        self::assertSame('rgba(255, 0, 0, .5)', $this->converter->toLegacy('rgb(255 0 0 / .5)'));
    }

    public function testModernHslWithAlphaBecomesHsla(): void
    {
        self::assertSame('hsla(200, 50%, 50%, 0.75)', $this->converter->toLegacy('hsl(200 50% 50% / 0.75)'));
    }

    public function testHslHueWithAngleUnitIsConverted(): void
    {
        self::assertSame('hsl(200deg, 50%, 50%)', $this->converter->toLegacy('hsl(200deg 50% 50%)'));
    }
}
