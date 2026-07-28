<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\WritabilityVerdictInterface;

/**
 * The single place that decides whether a store configuration path may be written from the editor.
 *
 * It exists as a port rather than as a private method because the question is asked twice and the
 * two askers are far apart: once when an entry is built, to decide whether an inline editor is
 * offered at all, and again when a write actually arrives, because the browser's opinion is never
 * trusted. Two separately written copies of that predicate would drift, and the copy that drifts is
 * the one nobody is watching - so there is one implementation and both sides inject it.
 *
 * What it does not cover is deliberate: it answers only about the path and the scope. Whether the
 * administrator holds the resources the write needs, and whether the submitted store id names a
 * real store, are separate questions answered by whoever accepts the write.
 */
interface ConfigPathWritabilityInterface
{
    /**
     * Decide whether the value at a configuration path may be written at a scope
     *
     * @param string $path Store configuration path, as a {{config}} directive would name it
     * @param int $storeId Store view the write is meant for; zero is the default scope
     * @return WritabilityVerdictInterface
     */
    public function evaluate(string $path, int $storeId): WritabilityVerdictInterface;
}
