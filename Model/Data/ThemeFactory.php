<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeFactoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterfaceFactory;

/**
 * Infrastructure adapter for the theme creation port.
 *
 * Naming the framework's code-generated factory is this class's whole job, so that services can
 * depend on `ThemeFactoryInterface` instead. (The theme import controller still injects the
 * generated factory directly and has not been moved behind this port.)
 *
 * The class deliberately lives under `Model\Data` rather than `Model`: the name
 * `Hryvinskyi\EmailTemplateEditor\Model\ThemeFactory` already belongs to the generated factory for
 * `Model\Theme`, which `Model\ThemeRepository` and the default-theme setup patch inject. A source
 * file at that name would shadow the generated class and break both.
 */
class ThemeFactory implements ThemeFactoryInterface
{
    /**
     * @param ThemeInterfaceFactory $themeFactory
     */
    public function __construct(
        private readonly ThemeInterfaceFactory $themeFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function create(): ThemeInterface
    {
        return $this->themeFactory->create();
    }
}
