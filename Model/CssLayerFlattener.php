<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\CssLayerFlattenerInterface;

class CssLayerFlattener implements CssLayerFlattenerInterface
{
    /**
     * {@inheritDoc}
     */
    public function flatten(string $css): string
    {
        $css = (string)preg_replace(
            '/@layer\s+(?:base|properties)\s*\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}/i',
            '',
            $css
        );

        $css = (string)preg_replace(
            '/@property\s+--[\w-]+\s*\{[^{}]*\}/i',
            '',
            $css
        );

        $css = (string)preg_replace_callback(
            '/@layer\s+[\w-]+(?:\s*,\s*[\w-]+)*\s*\{((?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*)\}/i',
            static function (array $matches): string {
                return $matches[1];
            },
            $css
        );

        return $css;
    }
}
