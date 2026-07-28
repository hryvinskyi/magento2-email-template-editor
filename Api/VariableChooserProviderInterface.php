<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

interface VariableChooserProviderInterface
{
    /**
     * Get available template variable groups for the variable chooser panel
     *
     * A group carries a code and a label as two separate things. The code is what anything keying on
     * a group - the panel's collapsed-state map, a stylesheet, a test - must use: it is stable, and
     * it stays the same when the label is translated. The label is for reading and for nothing else.
     *
     * Each row carries the canonical reference of the directive it inserts, or an empty string when
     * the reference cannot be built without deciding which parameter names a directive of that kind.
     * A row with a reference can be explained; a row without one is still perfectly insertable.
     *
     * @param string $templateId Template identifier
     * @param int $storeId Store ID for store-specific variables
     * @return array<int, array{
     *     code: string,
     *     label: string,
     *     variables: array<int, array{label: string, value: string, reference: string}>
     * }> Variable groups in the order they are offered
     */
    public function getVariableGroups(string $templateId, int $storeId = 0): array;
}
