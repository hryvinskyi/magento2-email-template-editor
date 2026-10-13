<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml;

use Magento\Framework\App\RequestInterface;

/**
 * Typed reads of the request parameters an editor endpoint receives
 *
 * A parameter arrives as whatever the browser sent - a string, an array, nothing - and a value of
 * the wrong shape is treated as not sent rather than cast into something nobody sent.
 */
trait ReadsRequestParameters
{
    /**
     * The request being handled
     *
     * @return RequestInterface
     */
    abstract public function getRequest();

    /**
     * A text parameter, or the default when it is absent or not text
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    private function stringParam(string $key, string $default = ''): string
    {
        $value = $this->getRequest()->getParam($key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * A text parameter that means something only when it is not empty
     *
     * @param string $key
     * @return string|null
     */
    private function optionalStringParam(string $key): ?string
    {
        $value = $this->stringParam($key);

        return $value !== '' ? $value : null;
    }

    /**
     * A whole-number parameter, or the default when it is absent or not a number
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    private function intParam(string $key, int $default = 0): int
    {
        $value = $this->getRequest()->getParam($key, $default);

        return is_numeric($value) ? (int)$value : $default;
    }
}
