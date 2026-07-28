<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueWriteStrategyInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueWriterInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\WriteAuthorizationInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use InvalidArgumentException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The single way in for changing the value a directive reads.
 *
 * It owns the questions that are the same whatever kind of value is being changed, and asks them in
 * an order chosen so that the cheapest and most conclusive refusals come first, and so that nothing
 * is looked up on behalf of a request that was never going to be allowed.
 *
 * May this value be changed at all - answered by the entry, which the server built from the
 * reference a moment earlier, never by the browser. Then: may this administrator change a value
 * stored there. Then: is the store view they named one that exists. Only then is a strategy asked to
 * write, and only if one claims the origin: the write pool ends in nothing, so an origin nothing
 * claims is refused rather than quietly passed over, which would look from the browser exactly like
 * a save that worked.
 *
 * Checking the store view is not ceremony. A store identifier that names no store reaches the
 * configuration writer perfectly happily and leaves a row scoped to a store that does not exist -
 * invisible on the configuration page, read by no message, removable by nobody. From the
 * administrator's side that is indistinguishable from a save that did nothing, which is the one
 * outcome this whole path is built to avoid.
 *
 * What comes back is the value as it now stands, carrying the scope the change was written in. The
 * value is re-read through the same reader the inspector's panel uses, so what the panel shows after
 * a change is produced the same way as what it showed before one. The scope is stated by this class
 * from the store view it checked, rather than taken from that reading, because the scope is the part
 * the administrator is being invited to verify and it should be the writer's own account of where it
 * put the value - said plainly even in the case where the value could not be read back.
 */
class ReferenceValueWriter implements ReferenceValueWriterInterface
{
    /**
     * @param ReferenceValueWriteStrategyInterface[] $strategies Writers, in the order they are
     *        asked; the first claiming the entry's origin writes it. Deliberately without a member
     *        claiming every origin
     * @param WriteAuthorizationInterface $writeAuthorization Whether the administrator may change a
     *        value stored where this one is stored
     * @param StoreManagerInterface $storeManager Checks the store view named by the request against
     *        the store views that exist, and names the one that was written
     * @param ReferenceValueResolverInterface $valueResolver Reads the value back after the change,
     *        the same way the inspector reads it in the first place
     * @throws InvalidArgumentException When the pool is empty or holds something that cannot write
     */
    public function __construct(
        private readonly array $strategies,
        private readonly WriteAuthorizationInterface $writeAuthorization,
        private readonly StoreManagerInterface $storeManager,
        private readonly ReferenceValueResolverInterface $valueResolver
    ) {
        $this->assertPool($strategies);
    }

    /**
     * @inheritDoc
     */
    public function write(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $value
    ): ResolvedValueInterface {
        if (!$entry->isValueWritable()) {
            throw new LocalizedException(
                __(
                    'The value behind "%1" is not one this editor changes. Whatever the panel offered, '
                    . 'this is decided here rather than in the browser.',
                    $entry->getReference()->toCanonicalString()
                )
            );
        }

        $this->writeAuthorization->assertAllowed($entry->getOrigin());

        $store = $this->existingStore($storeId);

        foreach ($this->strategies as $strategy) {
            if (!$strategy->supports($entry->getOrigin())) {
                continue;
            }

            $strategy->write($entry, $storeId, $value);

            return $this->written($entry, $storeId, $store);
        }

        throw new LocalizedException(
            __(
                'Values of the "%1" kind cannot be changed from the email template editor.',
                $entry->getOrigin()->getKind()
            )
        );
    }

    /**
     * The store view a change is meant for, or null when it is the default scope
     *
     * @param int $storeId Store view named by the request; zero is the default scope
     * @return StoreInterface|null Null for the default scope, which names no store view at all
     * @throws LocalizedException When the identifier names no store view that exists
     */
    private function existingStore(int $storeId): ?StoreInterface
    {
        if ($storeId === 0) {
            return null;
        }

        try {
            return $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException $exception) {
            throw new LocalizedException(
                __('There is no store view with the identifier %1, so nothing was changed.', $storeId),
                $exception
            );
        }
    }

    /**
     * The value as it stands after the change, naming the scope the change was written in
     *
     * @param VariableKnowledgeInterface $entry Entry for the reference that was changed
     * @param int $storeId Store view the change was written for; zero is the default scope
     * @param StoreInterface|null $store That store view, or null for the default scope
     * @return ResolvedValueInterface
     */
    private function written(
        VariableKnowledgeInterface $entry,
        int $storeId,
        ?StoreInterface $store
    ): ResolvedValueInterface {
        // No template is in play: a change arrives as a reference and a scope, not as a document,
        // and the only origins that can be written from here read nothing from a template anyway.
        $refreshed = $this->valueResolver->resolve($entry, $storeId, '');

        return new ResolvedValue(
            $refreshed->isAvailable(),
            $refreshed->isExact(),
            $refreshed->getPreview(),
            $refreshed->isTruncated(),
            $store === null ? ResolvedValueInterface::SCOPE_DEFAULT : ResolvedValueInterface::SCOPE_STORE,
            $store === null ? 0 : $storeId,
            // Named the way the editor's own store switcher names it, so that the scope the
            // administrator chose and the scope reported back read as the same thing.
            $store === null ? (string)__('Default Config') : (string)$store->getName()
        );
    }

    /**
     * Refuse a pool that is empty or holds something that cannot write a value
     *
     * A pool arrives from merged configuration, and a merge that dropped or mistyped an entry would
     * otherwise turn every attempt to change a value into the refusal meant for origins nothing
     * understands - which reads as a decision somebody took rather than as wiring that went missing.
     *
     * @param array<array-key, mixed> $pool Pool as wired
     * @return void
     * @throws InvalidArgumentException When the pool is empty or a member is of the wrong type
     */
    private function assertPool(array $pool): void
    {
        if ($pool === []) {
            throw new InvalidArgumentException(
                sprintf(
                    'The strategy pool of the reference value writer is empty; it must hold at least '
                    . 'one %s.',
                    ReferenceValueWriteStrategyInterface::class
                )
            );
        }

        foreach ($pool as $key => $member) {
            if (!$member instanceof ReferenceValueWriteStrategyInterface) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Entry "%s" of the strategy pool of the reference value writer must implement '
                        . '%s, got %s.',
                        (string)$key,
                        ReferenceValueWriteStrategyInterface::class,
                        get_debug_type($member)
                    )
                );
            }
        }
    }
}
