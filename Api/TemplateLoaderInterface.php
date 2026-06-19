<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

interface TemplateLoaderInterface
{
    /**
     * Load the list of all available email templates grouped by module
     *
     * @param int $storeId
     * @return array<string, array<int, array{id: string, label: string, module: string, area: string, has_draft: bool, has_published: bool, scheduled_at: string|null}>>
     */
    public function loadTemplateList(int $storeId = 0): array;

    /**
     * Load a specific email template by its identifier
     *
     * When neither a managed override nor a draft exists for the identifier yet a
     * legacy email_template row id is supplied, that row's stored content is used
     * as the seed for editing and the response carries `is_legacy_seed = true`.
     *
     * @param string $identifier
     * @param int $storeId
     * @param int|null $overrideEntityId
     * @param bool $defaultOnly When true, return only the default Magento template without loading overrides
     * @param int|null $legacyId Optional legacy email_template.template_id used to seed the editor when no managed override exists yet
     * @return array{content: string, subject: string, variables: string, type: int, is_override: bool, status: string, has_published: bool, has_draft: bool, active_from: string, active_to: string, drafts: array<int, array<string, mixed>>}
     */
    public function loadTemplate(
        string $identifier,
        int $storeId = 0,
        ?int $overrideEntityId = null,
        bool $defaultOnly = false,
        ?int $legacyId = null
    ): array;
}
