<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;

/**
 * One source of knowledge about directive references.
 *
 * Providers are pooled and consulted in a fixed order until one of them answers, so a provider only
 * has to know about the references it is actually the authority on and returns null for everything
 * else. Answering "I do not know" is a normal outcome, not a failure; the pool as a whole is what
 * guarantees that some answer comes back.
 *
 * A provider must not answer with a guess. An entry it returns is one it can stand behind, because
 * returning null lets a lower-priority provider - or the honest not-documented entry - have the
 * reference instead, and that is always better than a plausible invention.
 */
interface VariableKnowledgeProviderInterface
{
    /**
     * Describe one reference, or return null if this provider is not the authority on it
     *
     * @param DirectiveReferenceInterface $reference Reference to describe
     * @param int $storeId Store view the description is asked for
     * @return VariableKnowledgeInterface|null
     */
    public function describe(DirectiveReferenceInterface $reference, int $storeId): ?VariableKnowledgeInterface;

    /**
     * Every reference this provider knows about, in no particular order
     *
     * @param int $storeId Store view the descriptions are asked for
     * @return VariableKnowledgeInterface[]
     */
    public function listAll(int $storeId): array;
}
