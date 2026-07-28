<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\LegacyTemplateRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Magento\Email\Model\BackendTemplate;
use Magento\Email\Model\BackendTemplateFactory;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class LegacyTemplateRepository implements LegacyTemplateRepositoryInterface, ResetAfterRequestInterface
{
    /**
     * Resolved scope bindings, keyed by legacy template id
     *
     * A binding is a scan of core_config_data and cannot change while a request is in flight, so
     * the answer is remembered for the life of the request. Only successful resolutions are kept:
     * a failure is logged and left unremembered so it is not turned into a permanent empty answer.
     *
     * @var array<int, int[]>
     */
    private array $scopeBindings = [];

    /**
     * @param BackendTemplateFactory $backendTemplateFactory
     * @param CollectionFactory $collectionFactory
     * @param WebsiteRepositoryInterface $websiteRepository
     * @param StoreManagerInterface $storeManager
     * @param PluginBypassFlagInterface $pluginBypassFlag
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly BackendTemplateFactory $backendTemplateFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly PluginBypassFlagInterface $pluginBypassFlag,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getByOrigCode(string $origCode): array
    {
        return $this->getByOrigCodes([$origCode])[$origCode] ?? [];
    }

    /**
     * @inheritDoc
     */
    public function getByOrigCodes(array $origCodes): array
    {
        $codes = array_values(
            array_unique(
                array_filter(array_map('strval', $origCodes), static fn (string $code): bool => $code !== '')
            )
        );

        // An empty IN list is not an error — the adapter rewrites it to IN(NULL), which is valid
        // SQL that matches nothing. The guard exists to skip a round trip whose answer is known.
        if ($codes === []) {
            return [];
        }

        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('orig_template_code', ['in' => $codes]);
            $collection->setOrder('template_id', 'ASC');

            $grouped = [];
            foreach ($collection as $row) {
                $grouped[(string)$row->getData('orig_template_code')][] = $this->hydrate($row);
            }

            return $grouped;
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to load legacy email_template rows for orig_template_code "'
                . implode('", "', $codes) . '": ' . $e->getMessage()
            );

            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getById(int $templateId): ?BackendTemplate
    {
        if ($templateId <= 0) {
            return null;
        }

        return $this->loadById($templateId);
    }

    /**
     * @inheritDoc
     */
    public function getScopeBindings(int $templateId): array
    {
        if ($templateId <= 0) {
            return [];
        }

        if (isset($this->scopeBindings[$templateId])) {
            return $this->scopeBindings[$templateId];
        }

        try {
            $template = $this->loadById($templateId);
            if ($template === null || !$template->getId()) {
                return [];
            }

            $this->scopeBindings[$templateId] = $this->resolveScopeBindings($template);

            return $this->scopeBindings[$templateId];
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to resolve scope bindings for legacy template ID ' . $templateId . ': ' . $e->getMessage()
            );

            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getScopeBindingsForTemplate(BackendTemplate $template): array
    {
        $templateId = (int)$template->getId();
        if ($templateId <= 0) {
            return [];
        }

        if (isset($this->scopeBindings[$templateId])) {
            return $this->scopeBindings[$templateId];
        }

        try {
            $this->scopeBindings[$templateId] = $this->resolveScopeBindings($template);

            return $this->scopeBindings[$templateId];
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to resolve scope bindings for legacy template ID ' . $templateId . ': ' . $e->getMessage()
            );

            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->scopeBindings = [];
    }

    /**
     * Translate a legacy template's core_config_data references into store ids
     *
     * Cannot be answered for several templates at once: the underlying lookup issues one query
     * per model, and batching it would mean reimplementing that query against the resource model.
     * Remembering the answer per template id is the useful stopping point.
     *
     * @param BackendTemplate $template
     * @return int[]
     */
    private function resolveScopeBindings(BackendTemplate $template): array
    {
        $bindings = $template->getSystemConfigPathsWhereCurrentlyUsed();
        $storeIds = [];

        foreach ($bindings as $binding) {
            $scope = $binding['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $scopeId = isset($binding['scope_id']) ? (int)$binding['scope_id'] : 0;

            $storeIds = array_merge($storeIds, $this->resolveScopeToStoreIds($scope, $scopeId));
        }

        return array_values(array_unique($storeIds));
    }

    /**
     * Build a backend template model from a row the collection already returned
     *
     * The collection is initialised with Magento\Email\Model\Template, but the config-binding
     * lookup this repository exists to serve is declared on BackendTemplate only. Copying the
     * row's data into a BackendTemplate gives the right type with no second round trip: the
     * lookup needs the id plus the model's own injected collaborators, and neither the model nor
     * its resource model defines an after-load hook, so nothing is skipped but the generic
     * abstract-load event — which the bypass flag around a real load() exists to neutralise
     * anyway.
     *
     * @param DataObject $row
     * @return BackendTemplate
     */
    private function hydrate(DataObject $row): BackendTemplate
    {
        $template = $this->backendTemplateFactory->create();
        $template->setData($row->getData());

        return $template;
    }

    /**
     * Load a legacy template row by ID with the plugin bypass flag enabled
     *
     * The bypass keeps the runtime overlay from substituting a managed override's
     * content onto the result, so callers that need to inspect the genuine legacy
     * row (sidebar listing, seed-load) see the actual stored values.
     *
     * @param int $templateId
     * @return BackendTemplate|null
     */
    private function loadById(int $templateId): ?BackendTemplate
    {
        $template = $this->backendTemplateFactory->create();

        $this->pluginBypassFlag->enable();
        try {
            $template->load($templateId);
        } finally {
            $this->pluginBypassFlag->disable();
        }

        if (!$template->getId()) {
            return null;
        }

        return $template;
    }

    /**
     * Translate a (scope, scope_id) pair from core_config_data into store ids
     *
     * - default scope returns [0] meaning "applies to all stores"
     * - websites scope expands to the website's stores
     * - stores scope returns the scope_id verbatim
     *
     * @param string $scope
     * @param int $scopeId
     * @return int[]
     */
    private function resolveScopeToStoreIds(string $scope, int $scopeId): array
    {
        switch ($scope) {
            case 'stores':
                return $scopeId > 0 ? [$scopeId] : [];
            case 'websites':
                if ($scopeId <= 0) {
                    return [];
                }
                try {
                    $website = $this->websiteRepository->getById($scopeId);
                    $ids = array_map('intval', array_values($website->getStoreIds()));

                    return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
                } catch (\Exception $e) {
                    $this->logger->warning(
                        'Could not expand website ' . $scopeId . ' to store ids: ' . $e->getMessage()
                    );

                    return [];
                }
            case ScopeConfigInterface::SCOPE_TYPE_DEFAULT:
            default:
                return [0];
        }
    }
}
