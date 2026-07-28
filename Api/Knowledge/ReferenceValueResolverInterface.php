<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;

/**
 * Answers "what does this directive render as right now".
 *
 * The single way in for anything that wants a current value. It picks the strategy that understands
 * the entry's origin, shortens whatever comes back to one length for every answer, and never lets a
 * failure escape: a strategy that breaks is recorded and the answer becomes "nothing could be read",
 * because a sample builder falling over must not take the inspector down with it.
 */
interface ReferenceValueResolverInterface
{
    /**
     * The current value behind an entry
     *
     * @param VariableKnowledgeInterface $entry Entry to read a value for
     * @param int $storeId Store view the value is asked for
     * @param string $templateId Template the reference was found in
     * @return ResolvedValueInterface Always a value object; unavailable when nothing could be read
     */
    public function resolve(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $templateId
    ): ResolvedValueInterface;
}
