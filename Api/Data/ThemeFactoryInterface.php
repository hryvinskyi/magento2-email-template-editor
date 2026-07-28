<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data;

/**
 * Creation port for themes.
 *
 * Domain services depend on this port instead of the framework's code-generated
 * `ThemeInterfaceFactory`, so that no service names a generated concrete class. The adapter behind
 * this port is `Hryvinskyi\EmailTemplateEditor\Model\Data\ThemeFactory`.
 */
interface ThemeFactoryInterface
{
    /**
     * Create a new, unpersisted theme instance
     *
     * @return ThemeInterface A theme with no identity yet; the caller populates and saves it.
     */
    public function create(): ThemeInterface;
}
