<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DescribeContextInterface;

/**
 * The describe context, held for the life of the request.
 *
 * Clearing puts it back exactly as it started, so that "nothing is being described" is one state
 * rather than two: a source asking an unset context and a source asking a cleared one get the same
 * empty answer and behave the same way.
 */
class DescribeContext implements DescribeContextInterface
{
    /**
     * Identifier of the template being described, empty when nothing is
     *
     * @var string
     */
    private string $templateId = '';

    /**
     * Store view the description is asked for, zero when nothing is being described
     *
     * @var int
     */
    private int $storeId = 0;

    /**
     * @inheritDoc
     */
    public function set(string $templateId, int $storeId): void
    {
        $this->templateId = $templateId;
        $this->storeId = $storeId;
    }

    /**
     * @inheritDoc
     */
    public function getTemplateId(): string
    {
        return $this->templateId;
    }

    /**
     * @inheritDoc
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        $this->templateId = '';
        $this->storeId = 0;
    }
}
