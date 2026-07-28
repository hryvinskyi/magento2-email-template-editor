<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Color\ColorMixerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Color\ColorParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssColorConverterInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Color\Rgba;

class CssColorConverter implements CssColorConverterInterface
{
    /**
     * Guard against a pathological nest of `color-mix()` inside `color-mix()`
     */
    private const MAX_COLOR_MIX_DEPTH = 8;

    /**
     * @param ColorParserInterface $colorParser
     * @param ColorMixerInterface $colorMixer
     */
    public function __construct(
        private readonly ColorParserInterface $colorParser,
        private readonly ColorMixerInterface $colorMixer
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function toLegacy(string $css): string
    {
        $css = $this->convertOkColors($css);
        $css = $this->convertModernRgbAndHslSyntax($css);

        return $this->convertColorMix($css);
    }

    /**
     * Rewrite `oklch()` / `oklab()` colours as `#rrggbb` or `rgba()`
     *
     * @param string $css
     * @return string
     */
    private function convertOkColors(string $css): string
    {
        if (stripos($css, 'okl') === false) {
            return $css;
        }

        // `[^()]*` deliberately refuses to match nested functions. An `oklch(from …)`
        // relative colour, a `calc()` channel or a still-unresolved `var()` therefore falls
        // through untouched instead of being converted from garbage.
        return (string)preg_replace_callback(
            '/\b(?:oklch|oklab)\(\s*[^()]*?\s*\)/i',
            function (array $matches): string {
                $color = $this->colorParser->parse($matches[0]);

                return $color === null ? $matches[0] : $color->toCssString();
            },
            $css
        );
    }

    /**
     * Evaluate `color-mix()` and replace it with the resulting colour
     *
     * Tailwind v4 compiles every opacity modifier (`bg-red-500/50`, `text-black/70`, …) into
     * `color-mix(in oklab, <colour> <pct>, transparent)`, which no email client renders. A
     * duplicated-declaration CSS fallback is no help here either: Emogrifier merges
     * declarations by property name into one `style` attribute, so only the last value
     * survives inlining.
     *
     * @param string $css
     * @return string
     */
    private function convertColorMix(string $css): string
    {
        if (stripos($css, 'color-mix(') === false) {
            return $css;
        }

        // The balanced-brace match is outermost-first, so a nested mix is consumed as part of
        // its parent's argument list and never gets its own turn here; `parseMixOperand`
        // recurses into it instead.
        return (string)preg_replace_callback(
            '/\bcolor-mix(\((?:[^()]++|(?1))*+\))/i',
            function (array $matches): string {
                return $this->evaluateColorMix(substr($matches[1], 1, -1), 0) ?? $matches[0];
            },
            $css
        );
    }

    /**
     * Evaluate a single `color-mix()` argument list
     *
     * @param string $arguments The argument list without the surrounding parentheses
     * @param int $depth Current nesting depth, for the recursion guard
     * @return string|null Null when the mix cannot be evaluated statically
     */
    private function evaluateColorMix(string $arguments, int $depth): ?string
    {
        if ($depth >= self::MAX_COLOR_MIX_DEPTH) {
            return null;
        }

        $parts = $this->splitTopLevel($arguments);
        if (count($parts) !== 3) {
            return null;
        }

        $method = $this->parseInterpolationMethod($parts[0]);
        if ($method === null) {
            return null;
        }

        [$space, $hueMethod] = $method;

        $first = $this->parseMixOperand($parts[1], $depth);
        $second = $this->parseMixOperand($parts[2], $depth);
        if ($first === null || $second === null) {
            return null;
        }

        [$firstColor, $firstPercentage] = $first;
        [$secondColor, $secondPercentage] = $second;

        $weights = $this->resolveWeights($firstPercentage, $secondPercentage);
        if ($weights === null) {
            return null;
        }

        [$firstWeight, $alphaMultiplier] = $weights;

        $mixed = $this->colorMixer->mix($firstColor, $secondColor, $firstWeight, $space, $hueMethod);
        if ($mixed === null) {
            return null;
        }

        if ($alphaMultiplier < 1.0) {
            $mixed = $mixed->withAlpha($mixed->getAlpha() * $alphaMultiplier);
        }

        return $mixed->toCssString();
    }

    /**
     * Parse the `in <space> [<method> hue]` prelude
     *
     * @param string $prelude
     * @return array{0: string, 1: string}|null Space keyword and hue interpolation method
     */
    private function parseInterpolationMethod(string $prelude): ?array
    {
        $pattern = '/^in\s+([\w-]+)(?:\s+(shorter|longer|increasing|decreasing)\s+hue)?$/i';
        if (preg_match($pattern, trim($prelude), $matches) !== 1) {
            return null;
        }

        return [strtolower($matches[1]), strtolower($matches[2] ?? 'shorter')];
    }

    /**
     * Split a `<color> [<percentage>]` operand, in either order
     *
     * @param string $operand
     * @param int $depth Nesting depth of the enclosing mix, for the recursion guard
     * @return array{0: Rgba, 1: float|null}|null Null when the colour cannot be resolved
     */
    private function parseMixOperand(string $operand, int $depth): ?array
    {
        $operand = trim($operand);
        $percentage = null;

        // A trailing or leading bare percentage is unambiguous: every colour notation ends
        // in `)` or a hex digit, never in `%`.
        if (preg_match('/\s+([+-]?(?:\d+\.?\d*|\.\d+))%$/', $operand, $matches) === 1) {
            $percentage = (float)$matches[1];
            $operand = trim(substr($operand, 0, -strlen($matches[0])));
        } elseif (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+))%\s+/', $operand, $matches) === 1) {
            $percentage = (float)$matches[1];
            $operand = trim(substr($operand, strlen($matches[0])));
        }

        // A nested mix is not a colour notation the parser knows, so resolve it first.
        if (preg_match('/^color-mix(\((?:[^()]++|(?1))*+\))$/i', $operand, $nested) === 1) {
            $resolved = $this->evaluateColorMix(substr($nested[1], 1, -1), $depth + 1);
            if ($resolved === null) {
                return null;
            }
            $operand = $resolved;
        }

        $color = $this->colorParser->parse($operand);

        return $color === null ? null : [$color, $percentage];
    }

    /**
     * Turn the two declared percentages into a normalised weight and an alpha multiplier
     *
     * Per CSS Color 5: an omitted percentage is the complement of the other; both omitted
     * means 50/50; percentages that do not add up to 100% are scaled to, and when they add
     * up to *less* than 100% the shortfall is applied to the result's alpha.
     *
     * @param float|null $firstPercentage
     * @param float|null $secondPercentage
     * @return array{0: float, 1: float}|null Weight of the first colour and the alpha
     *                                        multiplier, or null when both weights are zero
     */
    private function resolveWeights(?float $firstPercentage, ?float $secondPercentage): ?array
    {
        if ($firstPercentage === null && $secondPercentage === null) {
            return [0.5, 1.0];
        }

        if ($firstPercentage === null) {
            $firstPercentage = 100.0 - (float)$secondPercentage;
        } elseif ($secondPercentage === null) {
            $secondPercentage = 100.0 - $firstPercentage;
        }

        $firstPercentage = max(0.0, $firstPercentage);
        $secondPercentage = max(0.0, (float)$secondPercentage);
        $sum = $firstPercentage + $secondPercentage;

        if ($sum <= 0.0) {
            return null;
        }

        return [$firstPercentage / $sum, $sum < 100.0 ? $sum / 100 : 1.0];
    }

    /**
     * Split an argument list on commas that are not nested inside parentheses
     *
     * @param string $arguments
     * @return string[]
     */
    private function splitTopLevel(string $arguments): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($arguments) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $character;
        }

        $parts[] = trim($current);

        return $parts;
    }

    /**
     * Convert space-separated `rgb()` / `hsl()` to the comma-separated legacy form
     *
     * Emogrifier and many email clients do not support the modern syntax.
     *
     * The channel pattern accepts a leading-dot decimal (`.5`), an explicit sign and an
     * angle unit on the hue. Minified Tailwind output writes alphas as `/ .5`, which an
     * `\d+(\.\d+)?` pattern misses - leaving the whole modern-syntax colour in place.
     *
     * @param string $css
     * @return string
     */
    private function convertModernRgbAndHslSyntax(string $css): string
    {
        $channel = '[+-]?(?:\d+\.?\d*|\.\d+)(?:%|deg|grad|rad|turn)?';

        return (string)preg_replace_callback(
            '/(rgb|hsl)a?\(\s*(' . $channel . ')\s+(' . $channel . ')\s+(' . $channel
            . ')\s*(?:\/\s*(' . $channel . '))?\s*\)/',
            static function (array $matches): string {
                $function = $matches[1];
                $first = $matches[2];
                $second = $matches[3];
                $third = $matches[4];
                $alpha = $matches[5] ?? null;

                if ($alpha !== null) {
                    return $function . 'a(' . $first . ', ' . $second . ', ' . $third . ', ' . $alpha . ')';
                }

                return $function . '(' . $first . ', ' . $second . ', ' . $third . ')';
            },
            $css
        );
    }
}
