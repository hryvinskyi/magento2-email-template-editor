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
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class SendTestEmail extends Action implements HttpPostActionInterface
{
    use ReadsRequestParameters;

    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::editor';

    /**
     * Wrapper email template that simply outputs the pre-rendered HTML body
     */
    private const TEST_TEMPLATE_ID = 'hryvinskyi_email_template_editor_test';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param TemplateRendererInterface $templateRenderer
     * @param SampleDataProviderPoolInterface $sampleDataProviderPool
     * @param CustomVariableMergerInterface $customVariableMerger
     * @param TransportBuilder $transportBuilder
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TemplateRendererInterface $templateRenderer,
        private readonly SampleDataProviderPoolInterface $sampleDataProviderPool,
        private readonly CustomVariableMergerInterface $customVariableMerger,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Send a test email with the current template content
     *
     * The pre-rendered HTML is sent through the standard TransportBuilder pipeline
     * (rather than a manually constructed transport) so that store-scoped SMTP
     * extensions, which hook into TransportBuilder, are applied to the message.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $recipientEmail = trim($this->stringParam('recipient_email'));
            $templateContent = $this->stringParam('template_content');
            $templateSubject = $this->stringParam('template_subject');
            $templateIdentifier = $this->stringParam('template_identifier');
            $storeId = $this->intParam('store_id');
            $providerCode = $this->stringParam('provider_code', 'mock');

            if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => (string)__('Please enter a valid email address.'),
                ]);
            }

            if ($templateContent === '') {
                return $resultJson->setData([
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

            $emailStoreId = $storeId > 0
                ? $storeId
                : (int)($this->storeManager->getDefaultStoreView()?->getId() ?? 0);

            $html = $this->templateRenderer->render(
                $templateContent,
                $variables,
                $storeId,
                $this->optionalStringParam('custom_css'),
                $this->optionalStringParam('tailwind_css'),
                $templateIdentifier !== '' ? $templateIdentifier : null,
                $providerCode === 'mock',
                $this->optionalStringParam('theme_css')
            );

            $processedSubject = $templateSubject !== ''
                ? $this->templateRenderer->renderPlain($templateSubject, $variables, $storeId, $templateIdentifier)
                : '';
            $subject = '[TEST] ' . ($processedSubject !== '' ? $processedSubject : 'Email Template Preview');

            $senderEmail = $this->storeText('trans_email/ident_general/email', $emailStoreId, 'no-reply@example.com');
            $senderName = $this->storeText('trans_email/ident_general/name', $emailStoreId, 'Store');

            $transport = $this->transportBuilder
                ->setTemplateIdentifier(self::TEST_TEMPLATE_ID)
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $emailStoreId,
                ])
                ->setTemplateVars([
                    'body' => $html,
                    'subject' => $subject,
                ])
                ->setFromByScope(['email' => $senderEmail, 'name' => $senderName], $emailStoreId)
                ->addTo($recipientEmail)
                ->getTransport();

            $transport->sendMessage();

            return $resultJson->setData([
                'success' => true,
                'message' => (string)__('Test email sent to %1', $recipientEmail),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('SendTestEmail failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $resultJson->setData([
                'success' => false,
                'message' => (string)__('Failed to send test email: %1', $e->getMessage()),
            ]);
        }
    }

    /**
     * A store's text configuration value, or the fallback when it has none
     *
     * @param string $path
     * @param int $storeId
     * @param string $fallback
     * @return string
     */
    private function storeText(string $path, int $storeId, string $fallback): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
