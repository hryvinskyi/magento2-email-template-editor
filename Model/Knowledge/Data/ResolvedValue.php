<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;

/**
 * An immutable resolved value.
 *
 * Whoever builds one owns the distinction the interface describes: a value read from where the
 * email will read it is exact and states the scope it came from, while a value assembled from
 * sample data is not exact and states no scope, because it was never read from one. Instances are
 * created with `new`, not through a factory - they are plain values with no dependencies.
 */
class ResolvedValue implements ResolvedValueInterface
{
    /**
     * @param bool $available Whether a value could be produced at all
     * @param bool $exact Whether this is the value the email will actually render
     * @param string $preview The value, already shortened for display
     * @param bool $truncated Whether the preview is shorter than the value it was taken from
     * @param string $scope Scope type the value was read from, empty when it came from no scope
     * @param int $scopeId Identifier of that scope, zero for the default scope and for no scope
     * @param string $scopeLabel That scope named the way the administrator sees it
     */
    public function __construct(
        private readonly bool $available = false,
        private readonly bool $exact = false,
        private readonly string $preview = '',
        private readonly bool $truncated = false,
        private readonly string $scope = '',
        private readonly int $scopeId = 0,
        private readonly string $scopeLabel = ''
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * @inheritDoc
     */
    public function isExact(): bool
    {
        return $this->exact;
    }

    /**
     * @inheritDoc
     */
    public function getPreview(): string
    {
        return $this->preview;
    }

    /**
     * @inheritDoc
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @inheritDoc
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * @inheritDoc
     */
    public function getScopeId(): int
    {
        return $this->scopeId;
    }

    /**
     * @inheritDoc
     */
    public function getScopeLabel(): string
    {
        return $this->scopeLabel;
    }
}
