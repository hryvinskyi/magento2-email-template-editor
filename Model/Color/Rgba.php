<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Color;

/**
 * An immutable sRGB colour with alpha, plus the colour-space conversions the pipeline needs.
 *
 * Channels are kept as gamma-encoded sRGB in the 0..1 range rather than as bytes so that a
 * value can round-trip through OKLab and back without accumulating quantisation error; the
 * 8-bit rounding happens once, in {@see toCssString()}.
 */
final class Rgba
{
    /**
     * @param float $red Gamma-encoded sRGB red, 0..1
     * @param float $green Gamma-encoded sRGB green, 0..1
     * @param float $blue Gamma-encoded sRGB blue, 0..1
     * @param float $alpha Alpha, 0..1
     */
    public function __construct(
        private readonly float $red,
        private readonly float $green,
        private readonly float $blue,
        private readonly float $alpha = 1.0
    ) {
    }

    /**
     * Build a colour from 8-bit channels
     *
     * @param int $red 0..255
     * @param int $green 0..255
     * @param int $blue 0..255
     * @param float $alpha 0..1
     * @return self
     */
    public static function fromBytes(int $red, int $green, int $blue, float $alpha = 1.0): self
    {
        return new self($red / 255, $green / 255, $blue / 255, $alpha);
    }

    /**
     * Build a colour from linear-light sRGB, clamping out-of-gamut values
     *
     * Clamping happens before the transfer function is applied: `pow()` on a negative base
     * returns NAN, which would poison every downstream channel.
     *
     * @param float $red Linear-light red
     * @param float $green Linear-light green
     * @param float $blue Linear-light blue
     * @param float $alpha 0..1
     * @return self
     */
    public static function fromLinear(float $red, float $green, float $blue, float $alpha = 1.0): self
    {
        return new self(
            self::encodeTransfer($red),
            self::encodeTransfer($green),
            self::encodeTransfer($blue),
            $alpha
        );
    }

    /**
     * Build a colour from OKLab coordinates
     *
     * Uses Björn Ottosson's OKLab matrices.
     *
     * @param float $lightness OKLab L
     * @param float $a OKLab a
     * @param float $b OKLab b
     * @param float $alpha 0..1
     * @return self
     */
    public static function fromOkLab(float $lightness, float $a, float $b, float $alpha = 1.0): self
    {
        $long = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $medium = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $short = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return self::fromLinear(
            4.0767416621 * $long - 3.3077115913 * $medium + 0.2309699292 * $short,
            -1.2684380046 * $long + 2.6097574011 * $medium - 0.3413193965 * $short,
            -0.0041960863 * $long - 0.7034186147 * $medium + 1.7076147010 * $short,
            $alpha
        );
    }

    /**
     * Build a colour from OKLCH coordinates
     *
     * @param float $lightness OKLab L
     * @param float $chroma OKLCH C
     * @param float $hue OKLCH H, in degrees
     * @param float $alpha 0..1
     * @return self
     */
    public static function fromOkLch(float $lightness, float $chroma, float $hue, float $alpha = 1.0): self
    {
        $radians = deg2rad($hue);

        return self::fromOkLab($lightness, $chroma * cos($radians), $chroma * sin($radians), $alpha);
    }

    /**
     * Build a colour from HSL coordinates
     *
     * @param float $hue Degrees
     * @param float $saturation 0..1
     * @param float $lightness 0..1
     * @param float $alpha 0..1
     * @return self
     */
    public static function fromHsl(float $hue, float $saturation, float $lightness, float $alpha = 1.0): self
    {
        $hue = fmod(fmod($hue, 360) + 360, 360);
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $secondary = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $match = $lightness - $chroma / 2;

        $sector = (int)floor($hue / 60);
        $components = match ($sector) {
            0 => [$chroma, $secondary, 0.0],
            1 => [$secondary, $chroma, 0.0],
            2 => [0.0, $chroma, $secondary],
            3 => [0.0, $secondary, $chroma],
            4 => [$secondary, 0.0, $chroma],
            default => [$chroma, 0.0, $secondary],
        };

        return new self(
            $components[0] + $match,
            $components[1] + $match,
            $components[2] + $match,
            $alpha
        );
    }

    /**
     * @return float Gamma-encoded sRGB red, 0..1
     */
    public function getRed(): float
    {
        return $this->red;
    }

    /**
     * @return float Gamma-encoded sRGB green, 0..1
     */
    public function getGreen(): float
    {
        return $this->green;
    }

    /**
     * @return float Gamma-encoded sRGB blue, 0..1
     */
    public function getBlue(): float
    {
        return $this->blue;
    }

    /**
     * @return float Alpha, 0..1
     */
    public function getAlpha(): float
    {
        return $this->alpha;
    }

    /**
     * Return the same colour with a different alpha
     *
     * @param float $alpha 0..1
     * @return self
     */
    public function withAlpha(float $alpha): self
    {
        return new self($this->red, $this->green, $this->blue, $alpha);
    }

    /**
     * Decode the channels to linear light
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function toLinear(): array
    {
        return [
            self::decodeTransfer($this->red),
            self::decodeTransfer($this->green),
            self::decodeTransfer($this->blue),
        ];
    }

    /**
     * Convert to OKLab coordinates
     *
     * @return array{0: float, 1: float, 2: float} L, a, b
     */
    public function toOkLab(): array
    {
        [$red, $green, $blue] = $this->toLinear();

        $long = (0.4122214708 * $red + 0.5363325363 * $green + 0.0514459929 * $blue) ** (1 / 3);
        $medium = (0.2119034982 * $red + 0.6806995451 * $green + 0.1073969566 * $blue) ** (1 / 3);
        $short = (0.0883024619 * $red + 0.2817188376 * $green + 0.6299787005 * $blue) ** (1 / 3);

        return [
            0.2104542553 * $long + 0.7936177850 * $medium - 0.0040720468 * $short,
            1.9779984951 * $long - 2.4285922050 * $medium + 0.4505937099 * $short,
            0.0259040371 * $long + 0.7827717662 * $medium - 0.8086757660 * $short,
        ];
    }

    /**
     * Convert to OKLCH coordinates
     *
     * @return array{0: float, 1: float, 2: float} L, C, H (degrees)
     */
    public function toOkLch(): array
    {
        [$lightness, $a, $b] = $this->toOkLab();
        $hue = rad2deg(atan2($b, $a));

        return [$lightness, sqrt($a * $a + $b * $b), $hue < 0 ? $hue + 360 : $hue];
    }

    /**
     * Render as the most email-compatible notation
     *
     * @return string `#rrggbb` for an opaque colour, `rgba()` otherwise
     */
    public function toCssString(): string
    {
        $red = self::toByte($this->red);
        $green = self::toByte($this->green);
        $blue = self::toByte($this->blue);

        if ($this->alpha >= 1.0) {
            return sprintf('#%02x%02x%02x', $red, $green, $blue);
        }

        $alpha = rtrim(rtrim(number_format(max(0.0, $this->alpha), 4, '.', ''), '0'), '.');

        return sprintf('rgba(%d, %d, %d, %s)', $red, $green, $blue, $alpha === '' ? '0' : $alpha);
    }

    /**
     * Apply the sRGB transfer function to one linear-light channel, clamped to gamut
     *
     * @param float $linear
     * @return float 0..1
     */
    private static function encodeTransfer(float $linear): float
    {
        $clamped = max(0.0, min(1.0, $linear));

        return $clamped <= 0.0031308
            ? 12.92 * $clamped
            : 1.055 * ($clamped ** (1 / 2.4)) - 0.055;
    }

    /**
     * Invert the sRGB transfer function for one gamma-encoded channel
     *
     * @param float $encoded
     * @return float Linear light
     */
    private static function decodeTransfer(float $encoded): float
    {
        $clamped = max(0.0, min(1.0, $encoded));

        return $clamped <= 0.04045
            ? $clamped / 12.92
            : (($clamped + 0.055) / 1.055) ** 2.4;
    }

    /**
     * Quantise one 0..1 channel to 8 bits
     *
     * @param float $channel
     * @return int 0..255
     */
    private static function toByte(float $channel): int
    {
        return (int)round(max(0.0, min(1.0, $channel)) * 255);
    }
}
