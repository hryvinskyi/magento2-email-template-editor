<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

interface CustomVariableMergerInterface
{
    /**
     * Merge user-provided custom variables (a JSON string) into a base variable set
     *
     * Non-empty custom values override or extend the base set; empty custom values
     * are ignored when the key already exists in the base set so that provider
     * defaults are preserved. Provider-supplied objects (such as the store model)
     * are never overwritten, as they power URL and logo resolution during rendering.
     *
     * @param array<string, mixed> $baseVariables Variables produced by a sample data provider
     * @param string|null $customVariablesJson Raw JSON entered in the custom data editor
     * @return array<string, mixed> Merged variable set ready for template rendering
     */
    public function merge(array $baseVariables, ?string $customVariablesJson): array;
}
