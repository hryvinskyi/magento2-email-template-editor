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
     * Get the immediate (no date range) published override for a template identifier and store ID
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    public function getImmediatePublished(string $identifier, int $storeId): ?TemplateOverrideInterface;

    /**
     * Get the published override whose active_from/active_to range covers the current time
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    public function getActiveScheduledPublished(string $identifier, int $storeId): ?TemplateOverrideInterface;
}
