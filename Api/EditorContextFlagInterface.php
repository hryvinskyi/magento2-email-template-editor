<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Runtime flag marking that rendering is happening inside the editor (preview / test send)
 *
 * When active, override-aware rendering is applied even if the module is otherwise
 * disabled for the store in configuration, so the editor always reflects the saved
 * customizations being worked on.
 */
interface EditorContextFlagInterface
{
    /**
     * Check whether the current request is rendering within the editor context
     *
     * @return bool
     */
    public function isActive(): bool;

    /**
     * Enter editor context
     *
     * @return void
     */
    public function enable(): void;

    /**
     * Leave editor context
     *
     * @return void
     */
    public function disable(): void;
}
