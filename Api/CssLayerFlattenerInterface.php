<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Flatten Tailwind v4 cascade-layer output so the Emogrifier-based inliner can match it.
 *
 * Tailwind v4 emits utilities inside `@layer utilities { … }`, base resets inside
 * `@layer base { … }`, and per-property scope defaults inside `@layer properties { … }`.
 * Pelago Emogrifier silently drops every rule wrapped in `@layer`, so the inliner never
 * sees a single Tailwind class until those wrappers are stripped or unwrapped.
 */
interface CssLayerFlattenerInterface
{
    /**
     * Unwrap meaningful `@layer X { rules }` blocks and drop the inliner-incompatible ones
     *
     * - `@layer base { … }` is dropped entirely (preflight resets would shred email layouts).
     * - `@layer properties { … }` is dropped entirely (Tailwind variable initialisation).
     * - Standalone `@property … { … }` rules are dropped.
     * - Every other `@layer X { rules }` is unwrapped so the inner rules become top-level.
     *
     * @param string $css
     * @return string
     */
    public function flatten(string $css): string;
}
