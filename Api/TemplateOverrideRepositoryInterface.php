<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface TemplateOverrideRepositoryInterface
{
    /**
     * Get template override by ID
     *
     * @param int $entityId
     * @return TemplateOverrideInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): TemplateOverrideInterface;

    /**
     * Save a template override
     *
     * @param TemplateOverrideInterface $override
     * @return TemplateOverrideInterface
     * @throws CouldNotSaveException
     */
    public function save(TemplateOverrideInterface $override): TemplateOverrideInterface;

    /**
     * Delete a template override
     *
     * @param TemplateOverrideInterface $override
     * @return bool
     * @throws CouldNotDeleteException
     */
    public function delete(TemplateOverrideInterface $override): bool;

    /**
     * Get override by template identifier, store ID, and status
     *
     * @param string $identifier
     * @param int $storeId
     * @param string $status
     * @return TemplateOverrideInterface|null
     */
    public function getByIdentifier(string $identifier, int $storeId, string $status): ?TemplateOverrideInterface;

    /**
     * Get draft override for a template identifier and store ID
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    public function getDraft(string $identifier, int $storeId): ?TemplateOverrideInterface;

    /**
     * Get published override for a template identifier and store ID
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    public function getPublished(string $identifier, int $storeId): ?TemplateOverrideInterface;

    /**
     * Get scheduled override for a template identifier and store ID
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    public function getScheduled(string $identifier, int $storeId): ?TemplateOverrideInterface;

    /**
     * Get all draft overrides for a template identifier and store ID
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface[]
     */
    public function getDrafts(string $identifier, int $storeId): array;

    /**
     * Get all scheduled overrides for a template identifier and store ID
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface[]
     */
    public function getScheduledOverrides(string $identifier, int $storeId): array;

    /**
     * Get all published overrides for a template identifier and store ID
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface[]
     */
    public function getPublishedList(string $identifier, int $storeId): array;

    /**
     * Get overrides for many template identifiers and store scopes in a single round trip
     *
     * This is the plural form of getPublishedList()/getScheduledOverrides()/getDrafts(): a caller
     * that needs the overrides of a whole template list pays for one lookup instead of one per
     * identifier per scope.
     *
     * An identifier with no matching rows is absent from the result rather than mapped to an empty
     * array, so callers must default with `?? []`.
     *
     * The rows within an identifier come back **unordered**. The single-identifier methods above do
     * not agree on an order — two of them impose none at all — so there is no single order this
     * method could preserve. Ordering is the caller's policy and belongs in the caller.
     *
     * A non-empty $fields list yields **partially hydrated** entities: only the named fields, plus
     * entity_id, template_identifier, store_id, status and is_active which are always included, are
     * populated. Every other getter then reads as though its column were empty — notably
     * getIsActive() would report false — so name every field you intend to read.
     *
     * Passing an empty $identifiers or $storeIds list returns an empty result without querying.
     *
     * @param string[] $identifiers Template identifiers to match, e.g. "sales_email_order_template"
     * @param int[] $storeIds Store scopes to match; 0 is the default ("All Store Views") scope
     * @param string[] $statuses TemplateOverrideInterface::STATUS_* values; empty means every status
     * @param string[] $fields TemplateOverrideInterface::* field names; empty means every field
     * @return array<string, TemplateOverrideInterface[]> Overrides grouped by template identifier
     */
    public function getOverridesForIdentifiers(
        array $identifiers,
        array $storeIds,
        array $statuses = [],
        array $fields = []
    ): array;

    /**
     * Get the live published override that carries no availability window at all
     *
     * A published override's availability window has two independently optional bounds. Neither
     * bound set — the shape this method answers for — means the override applies from the moment
     * it is published until it is replaced. The other three shapes belong to
     * getActiveScheduledPublished(), so the two methods can never name the same row and at most
     * one of them names a row for a given identifier and store at a given moment.
     *
     * A row that has been switched off is not returned: switching an override off means it is not
     * there, so the answer to "which undated override applies" has to skip it. A caller asking the
     * different question of whether the undated slot is occupied — which a switched-off row still
     * does — wants getUndatedPublishedRegardlessOfState() instead.
     *
     * When more than one row would qualify the one with the lowest entity id is returned, so
     * repeated calls agree on which row that is.
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    public function getImmediatePublished(string $identifier, int $storeId): ?TemplateOverrideInterface;

    /**
     * Get the published override occupying the undated slot, switched on or not
     *
     * The occupancy question, as opposed to the liveness question getImmediatePublished() answers.
     * A template and store may hold only one undated published override, and a row that has been
     * switched off still holds that place — it can be switched back on, and a second undated row
     * published behind it would leave two rows competing for one slot with nothing to separate
     * them. Whoever creates or unschedules an undated override must ask this before doing so.
     *
     * When more than one row holds the slot the lowest entity id is returned, so that a caller
     * removing what it finds converges on a single row rather than oscillating between two.
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    public function getUndatedPublishedRegardlessOfState(
        string $identifier,
        int $storeId
    ): ?TemplateOverrideInterface;

    /**
     * Get the active published override whose availability window is open at the current moment
     *
     * A bound that is not set is an open end, not a missing window: active_from alone means the
     * override applies from that moment onward, active_to alone means it applies until then, and
     * both together mean it applies between them. Both bounds are inclusive. A row carrying
     * neither bound has no window and is answered by getImmediatePublished() instead, never here.
     *
     * Only rows with is_active set are considered. If two windows are open at once — which the
     * schedule conflict check confines to the single instant where one window ends and the next
     * begins — the lowest entity id wins.
     *
     * A row returned here outranks the undated row from getImmediatePublished(). The window is the
     * exception an admin schedules to displace the standing override for a period, so for as long
     * as it is open it is the override that applies.
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    public function getActiveScheduledPublished(string $identifier, int $storeId): ?TemplateOverrideInterface;
}
