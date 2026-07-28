<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ModifierDescriptorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ModifierRegistryInterface;
use InvalidArgumentException;

/**
 * The published modifier vocabulary, assembled from whatever descriptors are wired into it.
 *
 * The set is configuration rather than a constant list, because whether a modifier exists is a
 * property of how the template filter is wired on an install: a module that registers a real filter
 * can publish a descriptor for it beside these without touching this class. The order the pool
 * arrives in is the order the vocabulary is offered in, and it is carried by the `sortOrder`
 * attribute of each wired item rather than by where that item sits in a file - array arguments are
 * sorted by `sortOrder` before they reach a constructor, and once modules are merged file order is
 * module load order rather than anyone's intent.
 *
 * Lookup is by exact name, because that is how the filter itself looks a modifier up: it holds its
 * modifiers in an array keyed by name and skips, in silence, any name that is not a key. There is
 * therefore nothing to gain by being lenient here - a name this registry did not find is a name the
 * filter will not find either, and answering with a descriptor anyway would describe formatting that
 * is not going to happen.
 */
class ModifierRegistry implements ModifierRegistryInterface
{
    /**
     * @var array<string, ModifierDescriptorInterface>
     */
    private readonly array $byName;

    /**
     * @param ModifierDescriptorInterface[] $descriptors Published modifiers, in the order they are offered
     * @throws InvalidArgumentException When the pool is empty, holds something of the wrong type, or
     *                                 publishes one name twice
     */
    public function __construct(
        private readonly array $descriptors
    ) {
        $this->byName = $this->indexByName($descriptors);
    }

    /**
     * @inheritDoc
     */
    public function getAll(): array
    {
        // Reindexed, so that the published order is positional and survives being serialised: the
        // pool arrives keyed by the names its items were wired under, and those keys carry nothing
        // the vocabulary needs - each descriptor already names itself.
        return array_values($this->descriptors);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): ?ModifierDescriptorInterface
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * Check the pool and key it by the name each descriptor describes
     *
     * A pool arrives from merged configuration. An empty one would otherwise show up as an editor
     * that offers no formatting at all, and a duplicated name as a lookup that answers with whichever
     * of the two happened to be wired later - neither says anything about the merge that caused it.
     *
     * @param array<array-key, mixed> $descriptors Pool as wired
     * @return array<string, ModifierDescriptorInterface>
     * @throws InvalidArgumentException When the pool is empty, a member is of the wrong type, or two
     *                                 members publish the same name
     */
    private function indexByName(array $descriptors): array
    {
        if ($descriptors === []) {
            throw new InvalidArgumentException(
                sprintf(
                    'The modifier registry was wired with no descriptors; it must hold at least one %s.',
                    ModifierDescriptorInterface::class
                )
            );
        }

        $byName = [];

        foreach ($descriptors as $key => $descriptor) {
            if (!$descriptor instanceof ModifierDescriptorInterface) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Entry "%s" of the modifier registry must implement %s, got %s.',
                        (string)$key,
                        ModifierDescriptorInterface::class,
                        get_debug_type($descriptor)
                    )
                );
            }

            $name = $descriptor->getName();

            if (isset($byName[$name])) {
                throw new InvalidArgumentException(
                    sprintf('The modifier registry publishes the name "%s" more than once.', $name)
                );
            }

            $byName[$name] = $descriptor;
        }

        return $byName;
    }
}
