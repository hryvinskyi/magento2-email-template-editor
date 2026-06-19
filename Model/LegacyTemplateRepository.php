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
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class LegacyTemplateRepository implements LegacyTemplateRepositoryInterface
{
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
        if ($origCode === '') {
            return [];
        }

        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('orig_template_code', $origCode);
            $collection->setOrder('template_id', 'ASC');

            $rows = [];
            foreach ($collection as $row) {
                $template = $this->loadById((int)$row->getId());
                if ($template !== null) {
                    $rows[] = $template;
                }
            }

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to load legacy email_template rows for orig_template_code "' . $origCode . '": ' . $e->getMessage()
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

        try {
            $template = $this->loadById($templateId);
            if ($template === null || !$template->getId()) {
                return [];
            }

            $bindings = $template->getSystemConfigPathsWhereCurrentlyUsed();
            $storeIds = [];

            foreach ($bindings as $binding) {
                $scope = $binding['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
                $scopeId = isset($binding['scope_id']) ? (int)$binding['scope_id'] : 0;

                $storeIds = array_merge($storeIds, $this->resolveScopeToStoreIds($scope, $scopeId));
            }

            return array_values(array_unique($storeIds));
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to resolve scope bindings for legacy template ID ' . $templateId . ': ' . $e->getMessage()
            );

            return [];
        }
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
