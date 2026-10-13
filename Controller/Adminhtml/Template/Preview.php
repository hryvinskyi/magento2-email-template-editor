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
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\ReadsRequestParameters;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Escaper;

class Preview extends Action implements HttpPostActionInterface
{
    use ReadsRequestParameters;

    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::editor';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param RawFactory $resultRawFactory
     * @param TemplateRendererInterface $templateRenderer
     * @param SampleDataProviderPoolInterface $sampleDataProviderPool
     * @param CustomVariableMergerInterface $customVariableMerger
     * @param Escaper $escaper
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly RawFactory $resultRawFactory,
        private readonly TemplateRendererInterface $templateRenderer,
        private readonly SampleDataProviderPoolInterface $sampleDataProviderPool,
        private readonly CustomVariableMergerInterface $customVariableMerger,
        private readonly Escaper $escaper
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
            $templateContent = $this->stringParam('template_content');
            $templateIdentifier = $this->stringParam('template_identifier');
            $storeId = $this->intParam('store_id');
            $providerCode = $this->stringParam('provider_code', 'mock');

            if ($templateContent === '') {
                if ($isRaw) {
                    return $this->rawResult('<p style="color:#eb5202;padding:20px;">Template content is required.</p>');
                }

                return $this->resultJsonFactory->create()->setData([
                    'success' => false,
                    'message' => (string)__('Template content is required.'),
                ]);
            }

            $provider = $this->sampleDataProviderPool->getProvider($providerCode);
            $variables = $provider->getVariables(
                $templateIdentifier,
                $storeId,
                $this->optionalStringParam('entity_id')
            );
            $variables = $this->customVariableMerger->merge(
                $variables,
                $this->optionalStringParam('custom_variables')
            );

            $isMockData = $providerCode === 'mock';

            $html = $this->templateRenderer->render(
                $templateContent,
                $variables,
                $storeId,
                $this->optionalStringParam('custom_css'),
                $this->optionalStringParam('tailwind_css'),
                $templateIdentifier !== '' ? $templateIdentifier : null,
                $isMockData,
                $this->optionalStringParam('theme_css')
            );

            if ($isRaw) {
                return $this->rawResult($html);
            }

            return $this->resultJsonFactory->create()->setData([
                'success' => true,
                'html' => $html,
            ]);
        } catch (\Exception $e) {
            if ($isRaw) {
                return $this->rawResult('<p style="color:#eb5202;padding:20px;">Error: '
                    . $this->escaper->escapeHtmlAttr($e->getMessage(), false) . '</p>');
            }

            return $this->resultJsonFactory->create()->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The preview as a page of its own, opened in a new tab
     *
     * The email is markup any administrator with editor access wrote, and here it is served from
     * the admin's own origin. The sandbox gives the page an origin of its own and runs no script,
     * so nothing in a template can act with the session of whoever opens the preview.
     *
     * @param string $html
     * @return ResultInterface
     */
    private function rawResult(string $html): ResultInterface
    {
        return $this->resultRawFactory->create()
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Content-Security-Policy', 'sandbox')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContents($html);
    }
}
