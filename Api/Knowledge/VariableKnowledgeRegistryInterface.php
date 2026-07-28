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
 * The one way in to the variable knowledge base.
 *
 * Where a provider may say "I do not know", the registry never does: it asks each provider in turn
 * and, if none of them is the authority on a reference, answers with an entry that reports plainly
 * that the base has no record of it. A caller therefore always gets an entry back and never has to
 * decide what to show when there is nothing - which is how the base avoids the one failure mode
 * that would make it untrustworthy, a confident description nobody wrote.
 */
interface VariableKnowledgeRegistryInterface
{
    /**
     * Describe one reference, always
     *
     * @param DirectiveReferenceInterface $reference Reference to describe
     * @param int $storeId Store view the description is asked for
     * @return VariableKnowledgeInterface Carrying an affordance, and reporting whether it is a real
     *                                    entry or the not-documented one
     */
    public function describe(DirectiveReferenceInterface $reference, int $storeId): VariableKnowledgeInterface;

    /**
     * Every reference any provider knows about, keyed by canonical reference string
     *
     * Where two providers describe the same reference, the higher-priority one wins, exactly as it
     * would when the reference is asked about on its own.
     *
     * @param int $storeId Store view the descriptions are asked for
     * @return array<string, VariableKnowledgeInterface>
     */
    public function listAll(int $storeId): array;
}
