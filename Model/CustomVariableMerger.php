<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\CustomVariableMergerInterface;

class CustomVariableMerger implements CustomVariableMergerInterface
{
    /**
     * {@inheritDoc}
     */
    public function merge(array $baseVariables, ?string $customVariablesJson): array
    {
        if ($customVariablesJson === null || trim($customVariablesJson) === '') {
            return $baseVariables;
        }

        try {
            $custom = json_decode($customVariablesJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $baseVariables;
        }

        if (!is_array($custom)) {
            return $baseVariables;
        }

        return $this->mergeArray($baseVariables, $custom);
    }

    /**
     * Recursively merge custom values into the base set following the contract rules
     *
     * Empty custom values do not clear an existing base default, provider-supplied
     * objects are never overwritten, and matching nested arrays are merged in depth.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $custom
     * @return array<string, mixed>
     */
    private function mergeArray(array $base, array $custom): array
    {
        foreach ($custom as $key => $value) {
            $hasBase = array_key_exists($key, $base);

            // Provider-supplied objects (e.g. the store model) power URL/logo
            // resolution during rendering and must never be replaced.
            if ($hasBase && is_object($base[$key])) {
                continue;
            }

            // Empty custom values are ignored when a base default already exists.
            if ($hasBase && $this->isEmpty($value)) {
                continue;
            }

            if (is_array($value) && $hasBase && is_array($base[$key])) {
                $base[$key] = $this->mergeArray($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Whether a custom value is considered empty (and should not override a default)
     *
     * @param mixed $value
     * @return bool
     */
    private function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return $value === null || $value === '';
    }
}
