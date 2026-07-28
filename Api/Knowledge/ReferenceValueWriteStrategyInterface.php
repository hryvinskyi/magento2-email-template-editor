<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Changes the value behind one kind of origin, at its origin.
 *
 * A member of the write pool claims the origins it understands and is asked to write only for those.
 * Unlike every other pool in this context, the write pool ends in nothing: no member claims every
 * origin, so an origin nothing claims is refused. A catch-all here would have to do something with a
 * value it does not understand, and the only two candidates are writing it somewhere plausible and
 * quietly doing nothing - the first is a corruption and the second is a save the administrator
 * believes happened.
 *
 * A strategy is asked only after the writing has been authorised and the store view has been checked
 * against the ones that exist. What it still owns is everything specific to its own kind of origin:
 * whether this particular value may be changed at all, whether the new value is acceptable, and what
 * changing it means for the other values stored beside it.
 */
interface ReferenceValueWriteStrategyInterface
{
    /**
     * Whether this strategy writes values of this kind of origin
     *
     * @param OriginInterface $origin Where the value being changed is stored
     * @return bool
     */
    public function supports(OriginInterface $origin): bool;

    /**
     * Change the value at its origin
     *
     * @param VariableKnowledgeInterface $entry Entry describing the reference, resolved on the server
     * @param int $storeId Store view to write for; zero is the default scope. Already checked
     * @param string $value Value as the administrator typed it
     * @return void
     * @throws LocalizedException When this value may not be changed, or when the new value is not
     *         one the place it is stored will accept. The message says which of the two it is
     */
    public function write(VariableKnowledgeInterface $entry, int $storeId, string $value): void;
}
