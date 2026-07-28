<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Api\LegacyTemplateRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateAreaResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateLoaderInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Magento\Email\Model\BackendTemplate;
use Magento\Email\Model\Template\Config as EmailConfig;
use Magento\Email\Model\TemplateFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

class TemplateLoader implements TemplateLoaderInterface, ResetAfterRequestInterface
{
    /**
     * Entity fields the sidebar summary reads
     *
     * Restricting the select keeps template_content, custom_css and tailwind_css — three
     * MEDIUMTEXT columns the summary never touches — out of a result set that spans every
     * override in the install.
     */
    private const SUMMARY_FIELDS = [
        TemplateOverrideInterface::ENTITY_ID,
        TemplateOverrideInterface::TEMPLATE_IDENTIFIER,
        TemplateOverrideInterface::STORE_ID,
        TemplateOverrideInterface::STATUS,
        TemplateOverrideInterface::DRAFT_NAME,
        TemplateOverrideInterface::VERSION_COMMENT,
        TemplateOverrideInterface::SCHEDULED_AT,
        TemplateOverrideInterface::ACTIVE_FROM,
        TemplateOverrideInterface::ACTIVE_TO,
        TemplateOverrideInterface::CREATED_BY_USERNAME,
        TemplateOverrideInterface::LAST_EDITED_BY_USERNAME,
        TemplateOverrideInterface::CREATED_AT,
        TemplateOverrideInterface::UPDATED_AT,
        TemplateOverrideInterface::IS_ACTIVE,
    ];

    /**
     * Order the sidebar groups override statuses in
     *
     * A status absent from this map is not shown at all, matching the fact that the tree only
     * ever asked for these three.
     *
     * @var array<string, int>
     */
    private const STATUS_ORDER = [
        TemplateOverrideInterface::STATUS_PUBLISHED => 0,
        TemplateOverrideInterface::STATUS_SCHEDULED => 1,
        TemplateOverrideInterface::STATUS_DRAFT => 2,
    ];

    /**
     * The email template list, resolved once per request
     *
     * The underlying call is uncached and costs a filesystem probe per template per installed
     * theme. Its result does not vary by store: the theme sweep walks every installed theme
     * package rather than the active design theme, so store emulation cannot change it.
     *
     * @var array<int, array{value: string, label: string, group?: string}>|null
     */
    private ?array $availableTemplates = null;

    /**
     * Identifier to human-readable label, built once from the template list
     *
     * @var array<string, string>|null
     */
    private ?array $templateLabels = null;

    /**
     * @param EmailConfig $emailConfig
     * @param TemplateOverrideRepositoryInterface $overrideRepository
     * @param TemplateAreaResolverInterface $areaResolver
     * @param TemplateFactory $templateFactory
     * @param Filesystem $filesystem
     * @param ReadFactory $readFactory
     * @param LoggerInterface $logger
     * @param PluginBypassFlagInterface $pluginBypassFlag
     * @param Emulation $appEmulation
     * @param LegacyTemplateRepositoryInterface $legacyRepository
     */
    public function __construct(
        private readonly EmailConfig $emailConfig,
        private readonly TemplateOverrideRepositoryInterface $overrideRepository,
        private readonly TemplateAreaResolverInterface $areaResolver,
        private readonly TemplateFactory $templateFactory,
        private readonly Filesystem $filesystem,
        private readonly ReadFactory $readFactory,
        private readonly LoggerInterface $logger,
        private readonly PluginBypassFlagInterface $pluginBypassFlag,
        private readonly Emulation $appEmulation,
        private readonly LegacyTemplateRepositoryInterface $legacyRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function loadTemplateList(int $storeId = 0): array
    {
        $grouped = [];

        try {
            $templates = $this->getAvailableTemplates();

            $identifiers = [];
            foreach ($templates as $template) {
                $identifiers[] = (string)$template['value'];
            }

            $scopes = $this->getStoreScopes($storeId);
            $overridesByIdentifier = $this->fetchOverrides($identifiers, $scopes);
            $legacyByOrigCode = $this->fetchLegacyRows($identifiers);

            foreach ($templates as $template) {
                $templateId = (string)$template['value'];
                $label = $template['label'];
                $group = $template['group'] ?? $this->deriveGroupFromId($templateId);

                $grouped[$group][] = [
                    'id' => $templateId,
                    'label' => (string)$label,
                    'module' => $this->extractModuleName($templateId),
                    'area' => $this->areaResolver->resolve($templateId),
                    'overrides' => $this->buildOverridesForTemplate(
                        $scopes,
                        $overridesByIdentifier[$templateId] ?? [],
                        $legacyByOrigCode[$templateId] ?? []
                    ),
                ];
            }

            ksort($grouped);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load available templates: ' . $e->getMessage());
        }

        return $grouped;
    }

    /**
     * Fetch the override rows of every listed template in one repository call
     *
     * A failure here costs the overrides and nothing else: the caller still renders the full
     * template tree with empty override lists, which is what the admin saw when the lookup was
     * per-template and failed. Letting the exception escape instead would empty the sidebar
     * entirely. One call site means one warning per request rather than one per template.
     *
     * @param string[] $identifiers
     * @param int[] $scopes
     * @return array<string, TemplateOverrideInterface[]>
     */
    private function fetchOverrides(array $identifiers, array $scopes): array
    {
        try {
            return $this->overrideRepository->getOverridesForIdentifiers(
                $identifiers,
                $scopes,
                [],
                self::SUMMARY_FIELDS
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to load template overrides for store scopes ' . implode(', ', $scopes)
                . '; the sidebar will list templates without them: ' . $e->getMessage()
            );

            return [];
        }
    }

    /**
     * Fetch the legacy email_template rows of every listed template in one repository call
     *
     * Degrades the same way the override fetch does: the tree still renders, without the legacy
     * entries. Only theme-free identifiers can match, since orig_template_code never carries a
     * theme suffix.
     *
     * @param string[] $identifiers
     * @return array<string, BackendTemplate[]>
     */
    private function fetchLegacyRows(array $identifiers): array
    {
        try {
            return $this->legacyRepository->getByOrigCodes($identifiers);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to load legacy email templates; the sidebar will list managed overrides '
                . 'only: ' . $e->getMessage()
            );

            return [];
        }
    }

    /**
     * Resolve the email template list, once per request
     *
     * @return array<int, array{value: string, label: string, group?: string}>
     */
    private function getAvailableTemplates(): array
    {
        if ($this->availableTemplates === null) {
            $this->availableTemplates = $this->emailConfig->getAvailableTemplates();
        }

        return $this->availableTemplates;
    }

    /**
     * @inheritDoc
     */
    public function loadTemplate(
        string $identifier,
        int $storeId = 0,
        ?int $overrideEntityId = null,
        bool $defaultOnly = false,
        ?int $legacyId = null
    ): array {
        $area = $this->areaResolver->resolve($identifier);
        $defaultData = $this->loadDefaultTemplate($identifier, $storeId, $area);
        $drafts = $this->getDraftsWithFallback($identifier, $storeId);
        $published = $this->getPublishedWithFallback($identifier, $storeId);

        if ($defaultOnly) {
            return $this->buildBasePayload(
                $identifier,
                $area,
                $defaultData,
                $defaultData['content'],
                $defaultData['subject'],
                ''
            ) + [
                'entity_id' => null,
                'store_id' => $storeId,
                'tailwind_css' => '',
                'is_override' => false,
                'is_legacy_seed' => false,
                'legacy_id' => null,
                'legacy_label' => '',
                'status' => '',
                'has_published' => $published !== null,
                'has_draft' => !empty($drafts),
                'published' => $published !== null ? $this->buildOverrideData($published) : null,
                'draft' => null,
                'drafts' => array_map([$this, 'buildOverrideData'], $drafts),
                'active_from' => '',
                'active_to' => '',
                'sample_provider_code' => '',
                'custom_variables' => '',
            ];
        }

        $activeOverride = null;

        if ($overrideEntityId !== null) {
            try {
                $activeOverride = $this->overrideRepository->getById($overrideEntityId);
            } catch (\Exception $e) {
                $this->logger->error('Failed to load override by ID: ' . $e->getMessage());
            }
        }

        if ($activeOverride === null && !empty($drafts)) {
            $activeOverride = reset($drafts);
        }

        if ($activeOverride !== null
            && $activeOverride->getStatus() === TemplateOverrideInterface::STATUS_PUBLISHED
        ) {
            $published = $activeOverride;
        }

        if ($activeOverride === null) {
            $activeOverride = $published;
        }

        // No managed override exists yet for this identifier+scope: when a legacy
        // email_template id was supplied, seed the editor from that row's content.
        // Saving the seeded edit creates a managed override via the existing
        // SaveDraft / Publish flow.
        if ($activeOverride === null && $legacyId !== null) {
            $legacyRow = $this->legacyRepository->getById($legacyId);
            if ($legacyRow !== null) {
                return $this->buildLegacySeedPayload(
                    $identifier,
                    $area,
                    $defaultData,
                    $drafts,
                    $published,
                    $legacyRow,
                    $legacyId
                );
            }
        }

        $isActiveDraft = $activeOverride !== null
            && $activeOverride->getStatus() === TemplateOverrideInterface::STATUS_DRAFT;
        $isActivePublished = $activeOverride !== null
            && $activeOverride->getStatus() === TemplateOverrideInterface::STATUS_PUBLISHED;

        $scheduleSource = $isActivePublished ? $activeOverride : $published;

        return [
            'identifier' => $identifier,
            'label' => $this->getTemplateLabel($identifier),
            'module' => $this->extractModuleName($identifier),
            'area' => $area,
            'entity_id' => $activeOverride !== null ? $activeOverride->getEntityId() : null,
            'store_id' => $activeOverride !== null ? (int)$activeOverride->getStoreId() : $storeId,
            'default_content' => $defaultData['content'],
            'default_subject' => $defaultData['subject'],
            'default_styles' => $defaultData['styles'],
            'content' => $activeOverride !== null && $activeOverride->getTemplateContent() !== null
                ? $activeOverride->getTemplateContent()
                : $defaultData['content'],
            'subject' => $activeOverride !== null && $activeOverride->getTemplateSubject() !== null
                ? $activeOverride->getTemplateSubject()
                : $defaultData['subject'],
            'custom_css' => $activeOverride !== null ? ($activeOverride->getCustomCss() ?? '') : '',
            'tailwind_css' => $activeOverride !== null ? ($activeOverride->getTailwindCss() ?? '') : '',
            'variables' => $defaultData['variables'],
            'type' => $defaultData['type'],
            'is_override' => $activeOverride !== null,
            'is_legacy_seed' => false,
            'legacy_id' => null,
            'legacy_label' => '',
            'status' => $activeOverride !== null ? $activeOverride->getStatus() : '',
            'has_published' => $published !== null,
            'has_draft' => !empty($drafts),
            'published' => $published !== null ? $this->buildOverrideData($published) : null,
            'draft' => $isActiveDraft ? $this->buildOverrideData($activeOverride) : null,
            'drafts' => array_map([$this, 'buildOverrideData'], $drafts),
            'active_from' => $scheduleSource !== null ? ($scheduleSource->getActiveFrom() ?? '') : '',
            'active_to' => $scheduleSource !== null ? ($scheduleSource->getActiveTo() ?? '') : '',
            'sample_provider_code' => $activeOverride !== null ? ($activeOverride->getSampleProviderCode() ?? '') : '',
            'custom_variables' => $activeOverride !== null ? ($activeOverride->getCustomVariables() ?? '') : '',
        ];
    }

    /**
     * Build the immutable identifier/label/area/default block shared by every payload
     *
     * @param string $identifier
     * @param string $area
     * @param array{content: string, subject: string, styles: string, variables: string, type: int} $defaultData
     * @param string $content
     * @param string $subject
     * @param string $customCss
     * @return array<string, mixed>
     */
    private function buildBasePayload(
        string $identifier,
        string $area,
        array $defaultData,
        string $content,
        string $subject,
        string $customCss
    ): array {
        return [
            'identifier' => $identifier,
            'label' => $this->getTemplateLabel($identifier),
            'module' => $this->extractModuleName($identifier),
            'area' => $area,
            'default_content' => $defaultData['content'],
            'default_subject' => $defaultData['subject'],
            'default_styles' => $defaultData['styles'],
            'content' => $content,
            'subject' => $subject,
            'custom_css' => $customCss,
            'variables' => $defaultData['variables'],
            'type' => $defaultData['type'],
        ];
    }

    /**
     * Build the response payload that seeds the editor from a legacy email_template row
     *
     * Maps the legacy row's template_text/subject/styles into the editor's content/
     * subject/custom_css fields, sets store_id to the legacy row's first config-bound
     * scope so the eventual managed-override save lands at the right scope, and marks
     * the response with is_legacy_seed = true so the UI shows the seed banner.
     *
     * @param string $identifier
     * @param string $area
     * @param array{content: string, subject: string, styles: string, variables: string, type: int} $defaultData
     * @param TemplateOverrideInterface[] $drafts
     * @param TemplateOverrideInterface|null $published
     * @param BackendTemplate $legacyRow
     * @param int $legacyId
     * @return array<string, mixed>
     */
    private function buildLegacySeedPayload(
        string $identifier,
        string $area,
        array $defaultData,
        array $drafts,
        ?TemplateOverrideInterface $published,
        BackendTemplate $legacyRow,
        int $legacyId
    ): array {
        $bindings = $this->legacyRepository->getScopeBindings($legacyId);
        $resolvedStoreId = !empty($bindings) ? (int)$bindings[0] : 0;
        $content = (string)($legacyRow->getTemplateText() ?? '');
        $subject = (string)($legacyRow->getTemplateSubject() ?? '');
        $customCss = (string)($legacyRow->getTemplateStyles() ?? '');

        return $this->buildBasePayload(
            $identifier,
            $area,
            $defaultData,
            $content,
            $subject,
            $customCss
        ) + [
            'entity_id' => null,
            'store_id' => $resolvedStoreId,
            'tailwind_css' => '',
            'is_override' => true,
            'is_legacy_seed' => true,
            'legacy_id' => $legacyId,
            'legacy_label' => (string)($legacyRow->getTemplateCode() ?? ''),
            'status' => '',
            'has_published' => $published !== null,
            'has_draft' => !empty($drafts),
            'published' => $published !== null ? $this->buildOverrideData($published) : null,
            'draft' => null,
            'drafts' => array_map([$this, 'buildOverrideData'], $drafts),
            'active_from' => '',
            'active_to' => '',
            'sample_provider_code' => '',
            'custom_variables' => '',
        ];
    }

    /**
     * Resolve the effective store scopes for override lookup
     *
     * Prefers the specific store and falls back to the default scope (store 0 = all
     * store views), mirroring how overrides are matched at send time. This ensures a
     * store-0 ("All Store Views") override is shown when a specific store is selected.
     *
     * @param int $storeId
     * @return int[]
     */
    private function getStoreScopes(int $storeId): array
    {
        return $storeId !== 0 ? [$storeId, 0] : [0];
    }

    /**
     * Get the published override for a store, falling back to the default scope
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface|null
     */
    private function getPublishedWithFallback(string $identifier, int $storeId): ?TemplateOverrideInterface
    {
        foreach ($this->getStoreScopes($storeId) as $scopeId) {
            $published = $this->overrideRepository->getPublished($identifier, $scopeId);
            if ($published !== null) {
                return $published;
            }
        }

        return null;
    }

    /**
     * Get drafts for a store, falling back to the default scope when none exist
     *
     * @param string $identifier
     * @param int $storeId
     * @return TemplateOverrideInterface[]
     */
    private function getDraftsWithFallback(string $identifier, int $storeId): array
    {
        foreach ($this->getStoreScopes($storeId) as $scopeId) {
            $drafts = $this->overrideRepository->getDrafts($identifier, $scopeId);
            if (!empty($drafts)) {
                return $drafts;
            }
        }

        return [];
    }

    /**
     * Load the default template file content and extract metadata
     *
     * When the identifier does not encode a specific theme, the file is resolved through
     * the selected store's configured design theme (via environment emulation), so the
     * editor shows the same template the store actually uses instead of the base module
     * default. Theme-encoded identifiers (e.g. "..._template/Vendor/theme") keep forcing
     * their own theme and need no emulation.
     *
     * @param string $identifier
     * @param int $storeId
     * @param string $area
     * @return array{content: string, subject: string, styles: string, variables: string, type: int}
     */
    private function loadDefaultTemplate(string $identifier, int $storeId = 0, string $area = 'frontend'): array
    {
        $result = [
            'content' => '',
            'subject' => '',
            'styles' => '',
            'variables' => '',
            'type' => 2,
        ];

        $parts = $this->emailConfig->parseTemplateIdParts($identifier);
        $baseTemplateId = $parts['templateId'];
        $theme = $parts['theme'] ?? null;
        $emulated = false;

        try {
            if ($theme === null && $storeId > 0) {
                $this->appEmulation->startEnvironmentEmulation($storeId, $area, true);
                $emulated = true;
            }

            $template = $this->templateFactory->create();
            $template->setForcedArea($baseTemplateId);

            if ($theme !== null) {
                $template->setForcedTheme($baseTemplateId, $theme);
            }

            $this->pluginBypassFlag->enable();

            try {
                $template->loadDefault($baseTemplateId);
            } finally {
                $this->pluginBypassFlag->disable();
            }

            $result['content'] = $template->getTemplateText() ?? '';
            $result['subject'] = $template->getTemplateSubject() ?? '';
            $result['styles'] = $template->getTemplateStyles() ?? '';
            $result['variables'] = $template->getData('orig_template_variables') ?? '';
            $result['type'] = (int)$template->getTemplateType();
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to load default template "' . $identifier . '": ' . $e->getMessage()
            );
        } finally {
            if ($emulated) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        }

        return $result;
    }

    /**
     * Get the human-readable label for a template
     *
     * @param string $identifier
     * @return string
     */
    private function getTemplateLabel(string $identifier): string
    {
        try {
            if ($this->templateLabels === null) {
                $labels = [];
                foreach ($this->getAvailableTemplates() as $template) {
                    $value = (string)$template['value'];
                    // First entry wins, as the scan this replaces returned the first match.
                    if (!isset($labels[$value])) {
                        $labels[$value] = (string)$template['label'];
                    }
                }

                $this->templateLabels = $labels;
            }

            return $this->templateLabels[$identifier] ?? $identifier;
        } catch (\Exception) {
            return $identifier;
        }
    }

    /**
     * Extract the module name from a template identifier
     *
     * @param string $templateId
     * @return string
     */
    private function extractModuleName(string $templateId): string
    {
        try {
            $parts = $this->emailConfig->parseTemplateIdParts($templateId);
            $baseId = $parts['templateId'];
            $filePath = $this->emailConfig->getTemplateFilename($baseId);
            if (preg_match('#/([A-Z][a-z]+_[A-Z]\w+)/#', $filePath, $matches)) {
                return $matches[1];
            }
        } catch (\Exception) {
            // Silently fall through
        }

        $idParts = explode('_', $templateId);
        if (count($idParts) >= 2) {
            return ucfirst($idParts[0]) . '_' . ucfirst($idParts[1]);
        }

        return 'Unknown';
    }

    /**
     * Derive a group name from the template identifier
     *
     * @param string $templateId
     * @return string
     */
    private function deriveGroupFromId(string $templateId): string
    {
        $module = $this->extractModuleName($templateId);
        $parts = explode('_', $module);

        return $parts[1] ?? $parts[0] ?? 'Other';
    }

    /**
     * Turn one template's fetched rows into its sidebar children
     *
     * Takes the rows already fetched for the whole list rather than querying per template.
     * Managed overrides (draft, published, scheduled) come first, ordered by sortOverrides().
     * Legacy Magento email_template rows whose orig_template_code matches the identifier are
     * appended as a separate "source = legacy" subset. A legacy row is hidden when a managed
     * override already exists for the same identifier in either the requested scope or the
     * default ("All Store Views") scope, since the runtime overlay represents the legacy row
     * through the managed one in that case.
     *
     * @param int[] $scopes
     * @param TemplateOverrideInterface[] $overrideRows
     * @param BackendTemplate[] $legacyRows
     * @return array<int, array<string, mixed>>
     */
    private function buildOverridesForTemplate(array $scopes, array $overrideRows, array $legacyRows): array
    {
        $overrides = [];
        $seenIds = [];

        foreach ($this->sortOverrides($overrideRows, $scopes) as $override) {
            $entityId = (int)$override->getEntityId();
            if (isset($seenIds[$entityId])) {
                continue;
            }

            $seenIds[$entityId] = true;
            $overrides[] = $this->buildOverrideSummary($override);
        }

        try {
            $managedScopes = [];
            foreach ($overrides as $entry) {
                $managedScopes[(int)$entry['store_id']] = true;
            }

            foreach ($legacyRows as $legacyRow) {
                $legacyEntityId = (int)$legacyRow->getId();
                if ($legacyEntityId <= 0) {
                    continue;
                }

                $bindings = $this->legacyRepository->getScopeBindingsForTemplate($legacyRow);
                if ($this->isLegacyCoveredByManaged($bindings, $managedScopes)) {
                    continue;
                }

                $overrides[] = $this->buildLegacyOverrideSummary($legacyRow, $bindings);
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to append legacy templates to the sidebar; managed overrides are still '
                . 'listed: ' . $e->getMessage()
            );
        }

        return $overrides;
    }

    /**
     * Put override rows into the order the sidebar has always presented them in
     *
     * The order is a policy of this class, not of the query that fetched the rows: the
     * per-identifier repository lookups it replaces disagreed among themselves, two of them
     * imposing no SQL order at all and simply returning what the composite index handed back.
     *
     * Published rows come first, then scheduled, then draft. Inside a status, the specific store
     * precedes the default scope, in the order the scope list gives. Inside a scope, published and
     * draft rows go by ascending entity id — the index order they arrived in before — while
     * scheduled rows go by ascending schedule date with undated rows first, matching how the
     * database sorts nulls ascending, and fall back to ascending entity id when two dates match.
     * That last tie-break is firmer than the database guaranteed; it makes the tree deterministic
     * rather than changing which rows appear.
     *
     * A row whose status or store scope is outside the requested set is dropped, since the
     * per-status, per-scope lookups could never have returned it.
     *
     * @param TemplateOverrideInterface[] $rows
     * @param int[] $scopes
     * @return TemplateOverrideInterface[]
     */
    private function sortOverrides(array $rows, array $scopes): array
    {
        $scopeOrder = array_flip(array_map('intval', array_values($scopes)));

        $ordered = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row->getStatus(), self::STATUS_ORDER)) {
                continue;
            }

            if (!isset($scopeOrder[(int)$row->getStoreId()])) {
                continue;
            }

            $ordered[] = $row;
        }

        usort(
            $ordered,
            function (TemplateOverrideInterface $left, TemplateOverrideInterface $right) use ($scopeOrder): int {
                $byStatus = self::STATUS_ORDER[$left->getStatus()] <=> self::STATUS_ORDER[$right->getStatus()];
                if ($byStatus !== 0) {
                    return $byStatus;
                }

                $byScope = $scopeOrder[(int)$left->getStoreId()] <=> $scopeOrder[(int)$right->getStoreId()];
                if ($byScope !== 0) {
                    return $byScope;
                }

                if ($left->getStatus() === TemplateOverrideInterface::STATUS_SCHEDULED) {
                    $bySchedule = $this->compareScheduledAt($left->getScheduledAt(), $right->getScheduledAt());
                    if ($bySchedule !== 0) {
                        return $bySchedule;
                    }
                }

                return (int)$left->getEntityId() <=> (int)$right->getEntityId();
            }
        );

        return $ordered;
    }

    /**
     * Compare two schedule dates the way an ascending database sort does, with nulls first
     *
     * @param string|null $left
     * @param string|null $right
     * @return int
     */
    private function compareScheduledAt(?string $left, ?string $right): int
    {
        if ($left === $right) {
            return 0;
        }

        if ($left === null) {
            return -1;
        }

        if ($right === null) {
            return 1;
        }

        return $left <=> $right;
    }

    /**
     * Decide whether existing managed overrides already cover all of a legacy row's bindings
     *
     * A managed override at store 0 covers every binding via the runtime overlay
     * fallback ladder; otherwise each binding is covered only by a managed
     * override that lives at that specific store id.
     *
     * @param int[] $bindings
     * @param array<int, bool> $managedScopes
     * @return bool
     */
    private function isLegacyCoveredByManaged(array $bindings, array $managedScopes): bool
    {
        if (empty($bindings)) {
            // No bindings means the legacy row is dangling — nothing managed can
            // currently cover it. Surface it so the admin can clean it up.
            return false;
        }

        if (isset($managedScopes[0])) {
            return true;
        }

        foreach ($bindings as $storeId) {
            if (!isset($managedScopes[(int)$storeId])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build a sidebar summary entry for a legacy email_template row
     *
     * @param BackendTemplate $legacyRow
     * @param int[] $storeIds
     * @return array<string, mixed>
     */
    private function buildLegacyOverrideSummary(BackendTemplate $legacyRow, array $storeIds): array
    {
        $primaryStoreId = !empty($storeIds) ? (int)$storeIds[0] : 0;
        $label = (string)($legacyRow->getTemplateCode() ?? '');
        if ($label === '') {
            $label = (string)__('Legacy template #%1', $legacyRow->getId());
        }

        $modifiedAt = (string)($legacyRow->getModifiedAt() ?? $legacyRow->getAddedAt() ?? '');

        return [
            'entity_id' => null,
            'legacy_id' => (int)$legacyRow->getId(),
            'source' => 'legacy',
            'store_id' => $primaryStoreId,
            'store_ids' => array_values(array_map('intval', $storeIds)),
            'label' => $label,
            'version_comment' => null,
            'draft_name' => null,
            'status' => 'legacy',
            'scheduled_at' => null,
            'active_from' => null,
            'active_to' => null,
            'created_by_username' => null,
            'last_edited_by' => null,
            'created_at' => (string)($legacyRow->getAddedAt() ?? ''),
            'updated_at' => $modifiedAt,
            'is_active' => true,
        ];
    }

    /**
     * Build a short summary of an override for the sidebar tree
     *
     * @param TemplateOverrideInterface $override
     * @return array{entity_id: int, store_id: int, label: string, draft_name: string|null, status: string, scheduled_at: string|null, active_from: string|null, active_to: string|null, last_edited_by: string|null, updated_at: string|null}
     */
    private function buildOverrideSummary(TemplateOverrideInterface $override): array
    {
        $draftName = $override->getDraftName();
        $comment = $override->getVersionComment();
        // The explicit name (set via Rename) takes precedence over the publish
        // comment, so renaming an override is reflected in the tree label.
        $label = $draftName !== null && $draftName !== ''
            ? $draftName
            : ($comment !== null && $comment !== ''
                ? $comment
                : 'Untitled');

        return [
            'entity_id' => $override->getEntityId(),
            'legacy_id' => null,
            'source' => 'managed',
            'store_id' => (int)$override->getStoreId(),
            'store_ids' => [(int)$override->getStoreId()],
            'label' => $label,
            'version_comment' => $comment,
            'draft_name' => $draftName,
            'status' => $override->getStatus(),
            'scheduled_at' => $override->getScheduledAt(),
            'active_from' => $override->getActiveFrom(),
            'active_to' => $override->getActiveTo(),
            'created_by_username' => $override->getCreatedByUsername(),
            'last_edited_by' => $override->getLastEditedByUsername(),
            'created_at' => $override->getCreatedAt(),
            'updated_at' => $override->getUpdatedAt(),
            'is_active' => $override->getIsActive(),
        ];
    }

    /**
     * Build a structured data array from a template override entity
     *
     * @param TemplateOverrideInterface $override
     * @return array<string, mixed>
     */
    private function buildOverrideData(TemplateOverrideInterface $override): array
    {
        return [
            'entity_id' => $override->getEntityId(),
            'template_identifier' => $override->getTemplateIdentifier(),
            'template_content' => $override->getTemplateContent(),
            'template_subject' => $override->getTemplateSubject(),
            'custom_css' => $override->getCustomCss(),
            'tailwind_css' => $override->getTailwindCss(),
            'theme_id' => $override->getThemeId(),
            'store_id' => $override->getStoreId(),
            'status' => $override->getStatus(),
            'draft_name' => $override->getDraftName(),
            'active_from' => $override->getActiveFrom(),
            'active_to' => $override->getActiveTo(),
            'created_at' => $override->getCreatedAt(),
            'updated_at' => $override->getUpdatedAt(),
            'created_by_username' => $override->getCreatedByUsername(),
            'last_edited_by' => $override->getLastEditedByUsername(),
            'is_active' => $override->getIsActive(),
            'sample_provider_code' => $override->getSampleProviderCode(),
            'custom_variables' => $override->getCustomVariables(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->availableTemplates = null;
        $this->templateLabels = null;
    }
}
