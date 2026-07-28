<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ModifierCallInterface;

/**
 * An immutable modifier call.
 *
 * Name and arguments are stored exactly as they were read from the template. The renderer looks a
 * modifier up by exact name and silently ignores anything it does not recognise, so a call that
 * looks like a misspelling has to keep its spelling: rewriting it here would describe a template
 * that does not exist. Instances are created with `new`, not through a factory.
 */
class ModifierCall implements ModifierCallInterface
{
    /**
     * @param string $name Modifier name as written in the template
     * @param string[] $arguments Positional arguments, in order
     */
    public function __construct(
        private readonly string $name,
        private readonly array $arguments = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @inheritDoc
     */
    public function toString(): string
    {
        if ($this->arguments === []) {
            return $this->name;
        }

        return $this->name . ':' . implode(':', $this->arguments);
    }
}
