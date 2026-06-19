<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Plugin;

use Hryvinskyi\EmailTemplateEditor\Api\ConfigInterface;
use Magento\Email\Controller\Adminhtml\Email\Template\Edit;
use Magento\Email\Model\BackendTemplate;
use Magento\Email\Model\BackendTemplateFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

class AdminEditControllerPlugin
{
    /**
     * @param ConfigInterface $config
     * @param RedirectFactory $redirectFactory
     * @param BackendTemplateFactory $templateFactory
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly RedirectFactory $redirectFactory,
        private readonly BackendTemplateFactory $templateFactory
    ) {
    }

    /**
     * Redirect template edit to our editor when enabled
     *
     * @param Edit $subject
     * @param callable $proceed
     * @return ResultInterface
     */
    public function aroundExecute(Edit $subject, callable $proceed)
    {
        if (!$this->config->isEnabled()) {
            return $proceed();
        }

        $templateId = (int)$subject->getRequest()->getParam('id');
        if (!$templateId) {
            return $proceed();
        }

        try {
            /** @var BackendTemplate $template */
            $template = $this->templateFactory->create();
            $template->load($templateId);

            $templateCode = $template->getOrigTemplateCode();
            if (empty($templateCode)) {
                $templateCode = $template->getTemplateCode();
            }

            if (empty($templateCode)) {
                return $proceed();
            }

            $redirect = $this->redirectFactory->create();
            $redirect->setPath(
                'emaileditor/editor/index',
                [
                    'template' => $templateCode,
                    'legacy_id' => $templateId,
                ]
            );

            return $redirect;
        } catch (\Exception $e) {
            return $proceed();
        }
    }
}
