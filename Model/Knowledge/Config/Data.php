<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Config;

use Magento\Framework\Config\Data as FrameworkConfigData;

/**
 * The merged email_variables.xml, read once and then served from the configuration cache.
 *
 * It adds no behaviour to the framework's cached configuration; it exists so that the reader and the
 * cache key can be wired to a name of this module's own, and so that this is the type the knowledge
 * provider is given rather than a shared one.
 *
 * The whole array is always fetched at once. Path lookups are not usable here: the framework splits
 * a path on slashes, and the keys of this configuration are canonical directive references, which
 * contain slashes of their own whenever they name a configuration path.
 *
 * Everything the reader produced lands in the configuration cache, so a change to any module's
 * email_variables.xml is invisible until that cache is flushed - and so is the report of a
 * contribution the converter refused, because the converter only runs when the cache is cold.
 */
class Data extends FrameworkConfigData
{
}
