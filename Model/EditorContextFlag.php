<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\EditorContextFlagInterface;

class EditorContextFlag implements EditorContextFlagInterface
{
    /**
     * @var bool
     */
    private bool $active = false;

    /**
     * {@inheritDoc}
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * {@inheritDoc}
     */
    public function enable(): void
    {
        $this->active = true;
    }

    /**
     * {@inheritDoc}
     */
    public function disable(): void
    {
        $this->active = false;
    }
}
