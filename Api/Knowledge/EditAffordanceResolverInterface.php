<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;

/**
 * Decides what an administrator can do about the value behind one kind of origin.
 *
 * Resolvers are pooled and asked in a fixed order; the first one that supports the entry's origin
 * answers. There is no dispatch on the origin's kind anywhere - each resolver decides for itself
 * what it can handle - so an origin kind another module invents is served by that module shipping a
 * resolver and one wiring entry, with nothing here to change.
 *
 * The pool always ends in a resolver that supports everything, so an entry never comes back without
 * an affordance.
 */
interface EditAffordanceResolverInterface
{
    /**
     * Whether this resolver is the one that answers for the given origin
     *
     * @param OriginInterface $origin Origin of the entry being resolved
     * @return bool
     */
    public function supports(OriginInterface $origin): bool;

    /**
     * The affordance for an entry this resolver supports
     *
     * @param VariableKnowledgeInterface $entry Entry whose origin this resolver supports
     * @param int $storeId Store view the affordance is asked for, since a deep link and a write both
     *                     belong to a scope
     * @return EditAffordanceInterface
     */
    public function resolve(VariableKnowledgeInterface $entry, int $storeId): EditAffordanceInterface;
}
