<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

use Magento\Email\Model\BackendTemplate;

interface LegacyTemplateRepositoryInterface
{
    /**
     * Get all legacy email_template rows whose orig_template_code equals the given file id
     *
     * @param string $origCode
     * @return BackendTemplate[]
     */
    public function getByOrigCode(string $origCode): array;

    /**
     * Load a single legacy email_template row by its primary key
     *
     * Loads with the plugin bypass flag enabled so the runtime overlay does not
     * overlay a managed override onto the result. Returns null when the row does
     * not exist.
     *
     * @param int $templateId
     * @return BackendTemplate|null
     */
    public function getById(int $templateId): ?BackendTemplate;

    /**
     * Resolve the store views a legacy template_id is referenced from in core_config_data
     *
     * Returns [0] when bound at the default scope (applies to all stores), specific
     * store ids when bound at the stores scope, and the expanded store list when
     * bound at the websites scope. Returns an empty array when no binding exists.
     *
     * @param int $templateId
     * @return int[]
     */
    public function getScopeBindings(int $templateId): array;
}
