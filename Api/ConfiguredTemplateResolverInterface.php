<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Which templates a store includes through a design configuration path
 *
 * An email pulls its header in with {{template config_path="design/email/header_template"}}, and
 * the path may point at the stock template, at a legacy "Transactional Emails" row or at a template
 * of another name. Each of them is that store's header, and the editor knows each by its own
 * identifier.
 *
 * @api
 */
interface ConfiguredTemplateResolverInterface
{
    /**
     * Identifiers of the templates a configuration path stands for at a store
     *
     * The stock template the path is named after comes first, then the template the store is
     * configured with when that is a different one.
     *
     * @param string $configPath Design configuration path, e.g. design/email/header_template
     * @param int $storeId Store view the configuration is read for; 0 reads the default scope
     * @return list<string>
     */
    public function identifiersFor(string $configPath, int $storeId): array;
}
