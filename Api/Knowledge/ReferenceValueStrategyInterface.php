<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;

/**
 * Reads what one kind of origin currently holds.
 *
 * Strategies are pooled and asked in a fixed order; the first one that supports the entry's origin
 * answers. There is no dispatch on the origin's kind anywhere - each strategy decides for itself
 * what it can read - so an origin kind another module invents is served by that module shipping a
 * strategy and one wiring entry, with nothing here to change.
 *
 * The pool always ends in a strategy that supports everything, so a value always comes back, even if
 * only to report that nothing could be read.
 *
 * A strategy answers with the value the message would really carry, not with the value that happens
 * to be stored. Where the two differ - because the renderer rewrites the stored value on its way
 * into the message - it is the rendered one that has to be shown, or an administrator tunes a
 * template against something no message will ever contain.
 */
interface ReferenceValueStrategyInterface
{
    /**
     * Whether this strategy is the one that reads for the given origin
     *
     * @param OriginInterface $origin Origin of the entry being read
     * @return bool
     */
    public function supports(OriginInterface $origin): bool;

    /**
     * The current value behind an entry this strategy supports
     *
     * The preview it carries is the full value; shortening it for display belongs to the facade, so
     * that every answer is cut at the same length whichever strategy produced it.
     *
     * @param VariableKnowledgeInterface $entry Entry whose origin this strategy supports
     * @param int $storeId Store view the value is asked for, since the same reference resolves
     *                     differently in different scopes
     * @param string $templateId Template the reference was found in, which is what decides the shape
     *                           of the sample data standing in for a record nobody has sent about yet
     * @return ResolvedValueInterface
     */
    public function resolve(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $templateId
    ): ResolvedValueInterface;
}
