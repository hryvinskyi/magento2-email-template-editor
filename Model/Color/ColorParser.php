<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Color;

use Hryvinskyi\EmailTemplateEditor\Api\Color\ColorParserInterface;

class ColorParser implements ColorParserInterface
{
    /**
     * Reference value a 100% chroma maps to in `oklch()` C and `oklab()` a/b
     */
    private const CHROMA_PERCENTAGE_REFERENCE = 0.4;

    /**
     * Degrees per unit for each `<angle>` unit
     */
    private const ANGLE_UNIT_DEGREES = [
        '' => 1.0,
        'deg' => 1.0,
        'grad' => 0.9,
        'rad' => 57.29577951308232,
        'turn' => 360.0,
    ];

    /**
     * The CSS named colours, as 24-bit RGB
     *
     * `transparent` is handled separately since it is the only keyword with a non-1 alpha.
     */
    private const NAMED_COLORS = [
        'aliceblue' => 0xF0F8FF, 'antiquewhite' => 0xFAEBD7, 'aqua' => 0x00FFFF,
        'aquamarine' => 0x7FFFD4, 'azure' => 0xF0FFFF, 'beige' => 0xF5F5DC,
        'bisque' => 0xFFE4C4, 'black' => 0x000000, 'blanchedalmond' => 0xFFEBCD,
        'blue' => 0x0000FF, 'blueviolet' => 0x8A2BE2, 'brown' => 0xA52A2A,
        'burlywood' => 0xDEB887, 'cadetblue' => 0x5F9EA0, 'chartreuse' => 0x7FFF00,
        'chocolate' => 0xD2691E, 'coral' => 0xFF7F50, 'cornflowerblue' => 0x6495ED,
        'cornsilk' => 0xFFF8DC, 'crimson' => 0xDC143C, 'cyan' => 0x00FFFF,
        'darkblue' => 0x00008B, 'darkcyan' => 0x008B8B, 'darkgoldenrod' => 0xB8860B,
        'darkgray' => 0xA9A9A9, 'darkgreen' => 0x006400, 'darkgrey' => 0xA9A9A9,
        'darkkhaki' => 0xBDB76B, 'darkmagenta' => 0x8B008B, 'darkolivegreen' => 0x556B2F,
        'darkorange' => 0xFF8C00, 'darkorchid' => 0x9932CC, 'darkred' => 0x8B0000,
        'darksalmon' => 0xE9967A, 'darkseagreen' => 0x8FBC8F, 'darkslateblue' => 0x483D8B,
        'darkslategray' => 0x2F4F4F, 'darkslategrey' => 0x2F4F4F, 'darkturquoise' => 0x00CED1,
        'darkviolet' => 0x9400D3, 'deeppink' => 0xFF1493, 'deepskyblue' => 0x00BFFF,
        'dimgray' => 0x696969, 'dimgrey' => 0x696969, 'dodgerblue' => 0x1E90FF,
        'firebrick' => 0xB22222, 'floralwhite' => 0xFFFAF0, 'forestgreen' => 0x228B22,
        'fuchsia' => 0xFF00FF, 'gainsboro' => 0xDCDCDC, 'ghostwhite' => 0xF8F8FF,
        'gold' => 0xFFD700, 'goldenrod' => 0xDAA520, 'gray' => 0x808080,
        'green' => 0x008000, 'greenyellow' => 0xADFF2F, 'grey' => 0x808080,
        'honeydew' => 0xF0FFF0, 'hotpink' => 0xFF69B4, 'indianred' => 0xCD5C5C,
        'indigo' => 0x4B0082, 'ivory' => 0xFFFFF0, 'khaki' => 0xF0E68C,
        'lavender' => 0xE6E6FA, 'lavenderblush' => 0xFFF0F5, 'lawngreen' => 0x7CFC00,
        'lemonchiffon' => 0xFFFACD, 'lightblue' => 0xADD8E6, 'lightcoral' => 0xF08080,
        'lightcyan' => 0xE0FFFF, 'lightgoldenrodyellow' => 0xFAFAD2, 'lightgray' => 0xD3D3D3,
        'lightgreen' => 0x90EE90, 'lightgrey' => 0xD3D3D3, 'lightpink' => 0xFFB6C1,
        'lightsalmon' => 0xFFA07A, 'lightseagreen' => 0x20B2AA, 'lightskyblue' => 0x87CEFA,
        'lightslategray' => 0x778899, 'lightslategrey' => 0x778899, 'lightsteelblue' => 0xB0C4DE,
        'lightyellow' => 0xFFFFE0, 'lime' => 0x00FF00, 'limegreen' => 0x32CD32,
        'linen' => 0xFAF0E6, 'magenta' => 0xFF00FF, 'maroon' => 0x800000,
        'mediumaquamarine' => 0x66CDAA, 'mediumblue' => 0x0000CD, 'mediumorchid' => 0xBA55D3,
        'mediumpurple' => 0x9370DB, 'mediumseagreen' => 0x3CB371, 'mediumslateblue' => 0x7B68EE,
        'mediumspringgreen' => 0x00FA9A, 'mediumturquoise' => 0x48D1CC,
        'mediumvioletred' => 0xC71585, 'midnightblue' => 0x191970, 'mintcream' => 0xF5FFFA,
        'mistyrose' => 0xFFE4E1, 'moccasin' => 0xFFE4B5, 'navajowhite' => 0xFFDEAD,
        'navy' => 0x000080, 'oldlace' => 0xFDF5E6, 'olive' => 0x808000,
        'olivedrab' => 0x6B8E23, 'orange' => 0xFFA500, 'orangered' => 0xFF4500,
        'orchid' => 0xDA70D6, 'palegoldenrod' => 0xEEE8AA, 'palegreen' => 0x98FB98,
        'paleturquoise' => 0xAFEEEE, 'palevioletred' => 0xDB7093, 'papayawhip' => 0xFFEFD5,
        'peachpuff' => 0xFFDAB9, 'peru' => 0xCD853F, 'pink' => 0xFFC0CB,
        'plum' => 0xDDA0DD, 'powderblue' => 0xB0E0E6, 'purple' => 0x800080,
        'rebeccapurple' => 0x663399, 'red' => 0xFF0000, 'rosybrown' => 0xBC8F8F,
        'royalblue' => 0x4169E1, 'saddlebrown' => 0x8B4513, 'salmon' => 0xFA8072,
        'sandybrown' => 0xF4A460, 'seagreen' => 0x2E8B57, 'seashell' => 0xFFF5EE,
        'sienna' => 0xA0522D, 'silver' => 0xC0C0C0, 'skyblue' => 0x87CEEB,
        'slateblue' => 0x6A5ACD, 'slategray' => 0x708090, 'slategrey' => 0x708090,
        'snow' => 0xFFFAFA, 'springgreen' => 0x00FF7F, 'steelblue' => 0x4682B4,
        'tan' => 0xD2B48C, 'teal' => 0x008080, 'thistle' => 0xD8BFD8,
        'tomato' => 0xFF6347, 'turquoise' => 0x40E0D0, 'violet' => 0xEE82EE,
        'wheat' => 0xF5DEB3, 'white' => 0xFFFFFF, 'whitesmoke' => 0xF5F5F5,
        'yellow' => 0xFFFF00, 'yellowgreen' => 0x9ACD32,
    ];

    /**
     * {@inheritDoc}
     */
    public function parse(string $color): ?Rgba
    {
        $color = trim($color);
        if ($color === '') {
            return null;
        }

        $keyword = strtolower($color);

        if ($keyword === 'transparent') {
            return new Rgba(0.0, 0.0, 0.0, 0.0);
        }

        if (isset(self::NAMED_COLORS[$keyword])) {
            $packed = self::NAMED_COLORS[$keyword];

            return Rgba::fromBytes(($packed >> 16) & 0xFF, ($packed >> 8) & 0xFF, $packed & 0xFF);
        }

        if ($color[0] === '#') {
            return $this->parseHex($color);
        }

        if (preg_match('/^([a-z]+)\(\s*(.*)\s*\)$/is', $color, $matches) !== 1) {
            return null;
        }

        $function = strtolower($matches[1]);
        $arguments = $matches[2];

        return match ($function) {
            'rgb', 'rgba' => $this->parseRgbFunction($arguments),
            'hsl', 'hsla' => $this->parseHslFunction($arguments),
            'oklch' => $this->parseOkFunction($arguments, true),
            'oklab' => $this->parseOkFunction($arguments, false),
            default => null,
        };
    }

    /**
     * Parse `#rgb`, `#rgba`, `#rrggbb` or `#rrggbbaa`
     *
     * @param string $color
     * @return Rgba|null
     */
    private function parseHex(string $color): ?Rgba
    {
        $digits = substr($color, 1);
        if (preg_match('/^[0-9a-f]+$/i', $digits) !== 1) {
            return null;
        }

        $length = strlen($digits);
        if ($length === 3 || $length === 4) {
            $expanded = '';
            foreach (str_split($digits) as $digit) {
                $expanded .= $digit . $digit;
            }
            $digits = $expanded;
            $length *= 2;
        }

        if ($length !== 6 && $length !== 8) {
            return null;
        }

        return Rgba::fromBytes(
            (int)hexdec(substr($digits, 0, 2)),
            (int)hexdec(substr($digits, 2, 2)),
            (int)hexdec(substr($digits, 4, 2)),
            $length === 8 ? (int)hexdec(substr($digits, 6, 2)) / 255 : 1.0
        );
    }

    /**
     * Parse the argument list of `rgb()` / `rgba()`
     *
     * @param string $arguments
     * @return Rgba|null
     */
    private function parseRgbFunction(string $arguments): ?Rgba
    {
        $split = $this->splitChannels($arguments);
        if ($split === null) {
            return null;
        }

        [$channels, $rawAlpha] = $split;
        if (count($channels) !== 3) {
            return null;
        }

        $components = [];
        foreach ($channels as $channel) {
            // A percentage channel is relative to 255; a number already is 0..255.
            $value = $this->parseNumberOrPercentage($channel, 255.0);
            if ($value === null) {
                return null;
            }
            $components[] = max(0.0, min(1.0, $value / 255));
        }

        $alpha = $this->parseAlpha($rawAlpha);
        if ($alpha === null) {
            return null;
        }

        return new Rgba($components[0], $components[1], $components[2], $alpha);
    }

    /**
     * Parse the argument list of `hsl()` / `hsla()`
     *
     * @param string $arguments
     * @return Rgba|null
     */
    private function parseHslFunction(string $arguments): ?Rgba
    {
        $split = $this->splitChannels($arguments);
        if ($split === null) {
            return null;
        }

        [$channels, $rawAlpha] = $split;
        if (count($channels) !== 3) {
            return null;
        }

        $hue = $this->parseAngle($channels[0]);
        $saturation = $this->parseNumberOrPercentage($channels[1], 1.0);
        $lightness = $this->parseNumberOrPercentage($channels[2], 1.0);
        $alpha = $this->parseAlpha($rawAlpha);

        if ($hue === null || $saturation === null || $lightness === null || $alpha === null) {
            return null;
        }

        return Rgba::fromHsl(
            $hue,
            max(0.0, min(1.0, $saturation)),
            max(0.0, min(1.0, $lightness)),
            $alpha
        );
    }

    /**
     * Parse the argument list of `oklch()` / `oklab()`
     *
     * @param string $arguments
     * @param bool $isPolar True for `oklch()`, false for `oklab()`
     * @return Rgba|null
     */
    private function parseOkFunction(string $arguments, bool $isPolar): ?Rgba
    {
        // Relative colour syntax needs a resolved base colour, which is not available here.
        if (preg_match('/^\s*from\b/i', $arguments) === 1) {
            return null;
        }

        $split = $this->splitChannels($arguments);
        if ($split === null) {
            return null;
        }

        [$channels, $rawAlpha] = $split;
        if (count($channels) !== 3) {
            return null;
        }

        $lightness = $this->parseNumberOrPercentage($channels[0], 1.0);
        $alpha = $this->parseAlpha($rawAlpha);
        if ($lightness === null || $alpha === null) {
            return null;
        }

        if ($isPolar) {
            $chroma = $this->parseNumberOrPercentage($channels[1], self::CHROMA_PERCENTAGE_REFERENCE);
            $hue = $this->parseAngle($channels[2]);
            if ($chroma === null || $hue === null) {
                return null;
            }

            return Rgba::fromOkLch($lightness, $chroma, $hue, $alpha);
        }

        $a = $this->parseNumberOrPercentage($channels[1], self::CHROMA_PERCENTAGE_REFERENCE);
        $b = $this->parseNumberOrPercentage($channels[2], self::CHROMA_PERCENTAGE_REFERENCE);
        if ($a === null || $b === null) {
            return null;
        }

        return Rgba::fromOkLab($lightness, $a, $b, $alpha);
    }

    /**
     * Split a colour function's arguments into three channels plus an optional alpha
     *
     * Accepts both the modern `A B C / D` form and the legacy `A, B, C, D` one.
     *
     * @param string $arguments
     * @return array{0: string[], 1: string|null}|null
     */
    private function splitChannels(string $arguments): ?array
    {
        $arguments = trim($arguments);
        if ($arguments === '') {
            return null;
        }

        $alpha = null;
        if (str_contains($arguments, '/')) {
            [$arguments, $alpha] = explode('/', $arguments, 2);
            $arguments = trim($arguments);
            $alpha = trim($alpha);
            if ($alpha === '') {
                return null;
            }
        }

        $channels = preg_split('/\s*,\s*|\s+/', $arguments, -1, PREG_SPLIT_NO_EMPTY);
        if ($channels === false) {
            return null;
        }

        if ($alpha === null && count($channels) === 4) {
            $alpha = array_pop($channels);
        }

        return [$channels, $alpha];
    }

    /**
     * Resolve an optional alpha token, defaulting to fully opaque
     *
     * @param string|null $rawAlpha
     * @return float|null Null when the token is present but unparseable
     */
    private function parseAlpha(?string $rawAlpha): ?float
    {
        if ($rawAlpha === null) {
            return 1.0;
        }

        $alpha = $this->parseNumberOrPercentage($rawAlpha, 1.0);

        return $alpha === null ? null : max(0.0, min(1.0, $alpha));
    }

    /**
     * Parse a `<number>` / `<percentage>` / `none` token
     *
     * @param string $value
     * @param float $percentageReference The value 100% maps to
     * @return float|null
     */
    private function parseNumberOrPercentage(string $value, float $percentageReference): ?float
    {
        $value = trim($value);

        if (strcasecmp($value, 'none') === 0) {
            return 0.0;
        }

        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+))(%?)$/', $value, $matches) !== 1) {
            return null;
        }

        $number = (float)$matches[1];

        return $matches[2] === '%' ? $number / 100 * $percentageReference : $number;
    }

    /**
     * Parse an `<angle>` token into degrees
     *
     * @param string $value
     * @return float|null
     */
    private function parseAngle(string $value): ?float
    {
        $value = trim($value);

        if (strcasecmp($value, 'none') === 0) {
            return 0.0;
        }

        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+))(deg|grad|rad|turn)?$/i', $value, $matches) !== 1) {
            return null;
        }

        return (float)$matches[1] * self::ANGLE_UNIT_DEGREES[strtolower($matches[2] ?? '')];
    }
}
