<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;

/**
 * An immutable origin.
 *
 * Nothing is validated here on purpose: the kind is matched by the strategies that claim it, and a
 * kind none of them claims is a wiring gap those strategies report, not something this value can
 * usefully refuse. Instances are created with `new`, not through a factory - they are plain values
 * with no dependencies.
 */
class Origin implements OriginInterface
{
    /**
     * @param string $kind Origin kind, one of those published on the interface
     * @param string $locator What identifies the value inside its origin, empty when there is none
     * @param string $explanation Prose describing how the value is produced
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $locator = '',
        private readonly string $explanation = ''
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function getLocator(): string
    {
        return $this->locator;
    }

    /**
     * @inheritDoc
     */
    public function getExplanation(): string
    {
        return $this->explanation;
    }
}
