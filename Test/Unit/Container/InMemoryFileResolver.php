<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Container;

use Magento\Framework\Config\FileResolverInterface;

/**
 * Answers a configuration reader with the files a test hands it, as an installation's module list would
 */
class InMemoryFileResolver implements FileResolverInterface
{
    /**
     * @param array<string, array<string, string>> $filesByName File contents keyed by a path naming
     *        them in error messages, grouped by the file name a reader asks for, in merge order
     */
    public function __construct(private readonly array $filesByName)
    {
    }

    /**
     * @inheritDoc
     *
     * @return array<string, string>
     */
    public function get($filename, $scope)
    {
        return $this->filesByName[$filename] ?? [];
    }
}
