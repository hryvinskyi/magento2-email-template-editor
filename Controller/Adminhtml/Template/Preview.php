<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Template;

use Hryvinskyi\EmailTemplateEditor\Api\CustomVariableMergerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\SampleDataProviderPoolInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateRendererInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

class Preview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::editor';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param RawFactory $resultRawFactory
     * @param TemplateRendererInterface $templateRenderer
     * @param SampleDataProviderPoolInterface $sampleDataProviderPool
     * @param CustomVariableMergerInterface $customVariableMerger
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly RawFactory $resultRawFactory,
        private readonly TemplateRendererInterface $templateRenderer,
        private readonly SampleDataProviderPoolInterface $sampleDataProviderPool,
        private readonly CustomVariableMergerInterface $customVariableMerger
    ) {
        parent::__construct($context);
    }

    /**
     * Render a live preview of the email template with sample data
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $isRaw = (bool)$this->getRequest()->getParam('raw', false);

        try {
            $templateContent = (string)$this->getRequest()->getParam('template_content', '');
            $templateIdentifier = (string)$this->getRequest()->getParam('template_identifier', '');
            $storeId = (int)$this->getRequest()->getParam('store_id', 0);
            $customCss = $this->getRequest()->getParam('custom_css');
            $tailwindCss = $this->getRequest()->getParam('tailwind_css');
            $themeCss = $this->getRequest()->getParam('theme_css');
            $providerCode = (string)$this->getRequest()->getParam('provider_code', 'mock');
            $entityId = $this->getRequest()->getParam('entity_id');

            if ($templateContent === '') {
                if ($isRaw) {
                    return $this->resultRawFactory->create()
                        ->setHeader('Content-Type', 'text/html')
                        ->setContents('<p style="color:#eb5202;padding:20px;">Template content is required.</p>');
                }

                return $this->resultJsonFactory->create()->setData([
                    'success' => false,
                    'message' => (string)__('Template content is required.'),
                ]);
            }

            $provider = $this->sampleDataProviderPool->getProvider($providerCode);
            $entityIdValue = $entityId !== null && $entityId !== '' ? (string)$entityId : null;
            $variables = $provider->getVariables($templateIdentifier, $storeId, $entityIdValue);

            $customVariables = $this->getRequest()->getParam('custom_variables');
            $variables = $this->customVariableMerger->merge(
                $variables,
                $customVariables !== null && $customVariables !== '' ? (string)$customVariables : null
            );

            $isMockData = $providerCode === 'mock';

            $html = $this->templateRenderer->render(
                $templateContent,
                $variables,
                $storeId,
                $customCss !== null && $customCss !== '' ? (string)$customCss : null,
                $tailwindCss !== null && $tailwindCss !== '' ? (string)$tailwindCss : null,
                $templateIdentifier !== '' ? $templateIdentifier : null,
                $isMockData,
                $themeCss !== null && $themeCss !== '' ? (string)$themeCss : null
            );

            if ($isRaw) {
                return $this->resultRawFactory->create()
                    ->setHeader('Content-Type', 'text/html')
                    ->setContents($html);
            }

            return $this->resultJsonFactory->create()->setData([
                'success' => true,
                'html' => $html,
            ]);
        } catch (\Exception $e) {
            if ($isRaw) {
                return $this->resultRawFactory->create()
                    ->setHeader('Content-Type', 'text/html')
                    ->setContents('<p style="color:#eb5202;padding:20px;">Error: '
                        . htmlspecialchars($e->getMessage()) . '</p>');
            }

            return $this->resultJsonFactory->create()->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
