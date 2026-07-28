<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;

/**
 * An immutable directive reference.
 *
 * The constructor takes the kind and the expression as already normalised values: normalisation and
 * validation belong to the parser, so that every reference in the system - whether it came from a
 * canonical string or from directive parts - went through exactly one set of rules. Instances are
 * created with `new`, not through a factory: they are plain values with no dependencies.
 */
class DirectiveReference implements DirectiveReferenceInterface
{
    /**
     * @param string $kind Directive kind in its canonical spelling
     * @param string $expression Expression, already normalised for that kind
     * @param bool $overlong Whether the expression given to the parser was truncated to fit the key
     *                       length limit
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $expression,
        private readonly bool $overlong = false
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
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * @inheritDoc
     */
    public function isOverlong(): bool
    {
        return $this->overlong;
    }

    /**
     * @inheritDoc
     */
    public function toCanonicalString(): string
    {
        return $this->kind . ':' . $this->expression;
    }

    /**
     * @inheritDoc
     */
    public function equals(DirectiveReferenceInterface $other): bool
    {
        return $other->getKind() === $this->kind && $other->getExpression() === $this->expression;
    }
}
