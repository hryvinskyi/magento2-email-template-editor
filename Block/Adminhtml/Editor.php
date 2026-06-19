<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Block\Adminhtml;

use Hryvinskyi\EmailTemplateEditor\Api\ConfigInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\System\Store as SystemStore;

class Editor extends Template
{
    /**
     * @param Context $context
     * @param ConfigInterface $config
     * @param ThemeRepositoryInterface $themeRepository
     * @param SystemStore $systemStore
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ConfigInterface $config,
        private readonly ThemeRepositoryInterface $themeRepository,
        private readonly SystemStore $systemStore,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get the JSON configuration for the JS email template editor
     *
     * @return string
     */
    public function getEditorConfig(): string
    {
        $storeId = (int)$this->getRequest()->getParam('store', 0);
        $legacyIdRaw = $this->getRequest()->getParam('legacy_id');
        $selectedLegacyId = $legacyIdRaw !== null && $legacyIdRaw !== ''
            ? (int)$legacyIdRaw
            : null;

        $config = [
            'urls' => [
                'loadList' => $this->getUrl('emaileditor/template/loadList'),
                'load' => $this->getUrl('emaileditor/template/load'),
                'saveDraft' => $this->getUrl('emaileditor/template/saveDraft'),
                'publish' => $this->getUrl('emaileditor/template/publish'),
                'deleteDraft' => $this->getUrl('emaileditor/template/deleteDraft'),
                'reset' => $this->getUrl('emaileditor/template/reset'),
                'preview' => $this->getUrl('emaileditor/template/preview'),
                'themeLoadList' => $this->getUrl('emaileditor/theme/loadList'),
                'themeLoad' => $this->getUrl('emaileditor/theme/load'),
                'themeSave' => $this->getUrl('emaileditor/theme/save'),
                'themeDelete' => $this->getUrl('emaileditor/theme/delete'),
                'themeExport' => $this->getUrl('emaileditor/theme/export'),
                'themeImport' => $this->getUrl('emaileditor/theme/import'),
                'versionLoadList' => $this->getUrl('emaileditor/version/loadList'),
                'versionPreview' => $this->getUrl('emaileditor/version/preview'),
                'versionDiff' => $this->getUrl('emaileditor/version/diff'),
                'versionRestore' => $this->getUrl('emaileditor/version/restore'),
                'sampleDataLoadList' => $this->getUrl('emaileditor/sampleData/loadList'),
                'sampleDataGetVariables' => $this->getUrl('emaileditor/sampleData/getVariables'),
                'sampleDataSearchEntities' => $this->getUrl('emaileditor/sampleData/searchEntities'),
                'variableLoadGroups' => $this->getUrl('emaileditor/variable/loadGroups'),
                'loadDrafts' => $this->getUrl('emaileditor/template/loadDrafts'),
                'duplicateDraft' => $this->getUrl('emaileditor/template/duplicateDraft'),
                'renameDraft' => $this->getUrl('emaileditor/template/renameDraft'),
                'checkScheduleConflict' => $this->getUrl('emaileditor/template/checkScheduleConflict'),
                'updateSchedule' => $this->getUrl('emaileditor/template/updateSchedule'),
                'sendTestEmail' => $this->getUrl('emaileditor/template/sendTestEmail'),
                'toggleActive' => $this->getUrl('emaileditor/template/toggleActive'),
            ],
            'storeId' => $storeId,
            'stores' => $this->getStoreList(),
            'formKey' => $this->getFormKey(),
            'selectedTemplate' => (string)$this->getRequest()->getParam('template', ''),
            'selectedLegacyId' => $selectedLegacyId,
            'isEnabled' => $this->config->isEnabled($storeId),
        ];

        return (string)json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    /**
     * Build the list of store views for the store switcher
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function getStoreList(): array
    {
        $stores = [];
        $this->collectStoreOptions($this->systemStore->getStoreValuesForForm(false, true), $stores);

        return $stores;
    }

    /**
     * Recursively collect store-view leaves from the nested option structure
     *
     * getStoreValuesForForm() returns a tree (website => group => store views) where the
     * individual store views are nested inside the parent entries' "value" arrays. Walk the
     * tree and keep only the leaves whose "value" is a numeric store id.
     *
     * @param array<int, array<string, mixed>> $options
     * @param array<int, array{id: int, name: string}> $stores
     * @return void
     */
    private function collectStoreOptions(array $options, array &$stores): void
    {
        foreach ($options as $item) {
            if (!isset($item['value'])) {
                continue;
            }

            if (is_array($item['value'])) {
                $this->collectStoreOptions($item['value'], $stores);
                continue;
            }

            if (is_numeric($item['value'])) {
                $stores[] = [
                    'id' => (int)$item['value'],
                    'name' => trim(str_replace("\xc2\xa0", ' ', (string)$item['label'])),
                ];
            }
        }
    }
}
