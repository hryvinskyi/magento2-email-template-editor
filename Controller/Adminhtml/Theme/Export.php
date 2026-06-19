<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Theme;

use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::themes';

    /**
     * @param Context $context
     * @param RawFactory $resultRawFactory
     * @param ThemeRepositoryInterface $themeRepository
     */
    public function __construct(
        Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly ThemeRepositoryInterface $themeRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Export a theme as a downloadable JSON file
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $themeId = (int)$this->getRequest()->getParam('theme_id', 0);

        if (!$themeId) {
            $resultRaw = $this->resultRawFactory->create();
            $resultRaw->setHttpResponseCode(400);
            $resultRaw->setContents((string)__('Theme ID is required.'));

            return $resultRaw;
        }

        try {
            $theme = $this->themeRepository->getById($themeId);
            $fileName = preg_replace('/[^a-z0-9_\-]/i', '_', (string)$theme->getName()) . '_theme.css';
            $content = (string)$theme->getThemeCss();

            $resultRaw = $this->resultRawFactory->create();
            $resultRaw->setHttpResponseCode(200);
            $resultRaw->setHeader('Content-Type', 'text/css', true);
            $resultRaw->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"', true);
            $resultRaw->setContents($content);

            return $resultRaw;
        } catch (\Exception $e) {
            $resultRaw = $this->resultRawFactory->create();
            $resultRaw->setHttpResponseCode(404);
            $resultRaw->setContents($e->getMessage());

            return $resultRaw;
        }
    }
}
