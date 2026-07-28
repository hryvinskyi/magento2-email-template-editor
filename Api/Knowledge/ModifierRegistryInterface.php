<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ModifierDescriptorInterface;

/**
 * The modifier vocabulary the editor may offer.
 *
 * It publishes descriptors and nothing else: it does not read a chain, does not apply a modifier and
 * does not decide how any of this is worded on screen. What it is for is that a single place answers
 * "which modifiers exist, and what is true about them", so that the set on offer can be checked
 * against the template filter rather than being re-derived wherever a chain is edited.
 */
interface ModifierRegistryInterface
{
    /**
     * Every published modifier, in the order it is offered
     *
     * @return ModifierDescriptorInterface[]
     */
    public function getAll(): array;

    /**
     * The descriptor for one name, or null when nothing publishes it
     *
     * The name is matched exactly, as the template filter matches it. Null is the honest answer for
     * a name found in a template that nothing describes - and, since the filter skips a name it does
     * not hold, it is also the answer for a name that does nothing at all when the message is
     * rendered.
     *
     * @param string $name Modifier name as written in the chain
     * @return ModifierDescriptorInterface|null
     */
    public function get(string $name): ?ModifierDescriptorInterface;
}
