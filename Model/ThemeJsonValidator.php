<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\ThemeJsonValidatorInterface;

/**
 * Lenient validator for the v4 CSS-first theme format
 *
 * Accepts either a Tailwind v4 CSS-first theme string (with at least one
 * `@theme { … }` block) or the legacy pre-v4 JSON shape, so unmigrated stored themes
 * continue to validate. The class/interface name keeps the "ThemeJson" wording for
 * binary compatibility with downstream code; the contract widened from JSON to CSS.
 */
class ThemeJsonValidator implements ThemeJsonValidatorInterface
{
    /**
     * Accumulated validation errors
     *
     * @var array<string>
     */
    private array $errors = [];

    /**
     * @inheritDoc
     */
    public function validate(string $value): bool
    {
        $this->errors = [];
        $trimmed = trim($value);

        if ($trimmed === '') {
            $this->errors[] = 'Theme content is empty.';
            return false;
        }

        if (str_starts_with($trimmed, '{')) {
            $data = json_decode($trimmed, true);
            if (is_array($data) && (isset($data['tokens']) || isset($data['elements']) || isset($data['utilities']))) {
                return true;
            }
        }

        if (!preg_match('/@theme\s*\{[^}]*\}/i', $trimmed)) {
            $this->errors[] = 'Theme CSS must contain at least one "@theme { … }" block.';
            return false;
        }

        if (substr_count($trimmed, '{') !== substr_count($trimmed, '}')) {
            $this->errors[] = 'Theme CSS has unbalanced braces.';
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
