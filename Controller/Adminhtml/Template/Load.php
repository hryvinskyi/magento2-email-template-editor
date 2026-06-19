<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Template;

use Hryvinskyi\EmailTemplateEditor\Api\TemplateLoaderInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class Load extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::editor';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param TemplateLoaderInterface $templateLoader
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TemplateLoaderInterface $templateLoader
    ) {
        parent::__construct($context);
    }

    /**
     * Load a specific email template by identifier, returning default, published, and draft data
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();
        $identifier = (string)$this->getRequest()->getParam('template_identifier', '');
        $storeId = (int)$this->getRequest()->getParam('store_id', 0);
        $entityId = $this->getRequest()->getParam('entity_id')
            ?? $this->getRequest()->getParam('draft_entity_id');
        $defaultOnly = (bool)$this->getRequest()->getParam('default_only', false);
        $legacyIdRaw = $this->getRequest()->getParam('legacy_id');
        $legacyId = $legacyIdRaw !== null && $legacyIdRaw !== '' ? (int)$legacyIdRaw : null;

        if ($identifier === '') {
            return $resultJson->setData([
                'success' => false,
                'message' => (string)__('Template identifier is required.'),
            ]);
        }

        try {
            $templateData = $this->templateLoader->loadTemplate(
                $identifier,
                $storeId,
                $defaultOnly ? null : ($entityId !== null && $entityId !== '' ? (int)$entityId : null),
                $defaultOnly,
                $defaultOnly ? null : $legacyId
            );

            return $resultJson->setData([
                'success' => true,
                'template' => $templateData,
            ]);
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
