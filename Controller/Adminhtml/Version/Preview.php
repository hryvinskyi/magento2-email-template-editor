<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Version;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateVersionInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateRendererInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVersionRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\ReadsRequestParameters;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class Preview extends Action implements HttpPostActionInterface
{
    use ReadsRequestParameters;

    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::history';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param TemplateRendererInterface $templateRenderer
     * @param TemplateVersionRepositoryInterface $versionRepository
     * @param ThemeRepositoryInterface $themeRepository
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TemplateRendererInterface $templateRenderer,
        private readonly TemplateVersionRepositoryInterface $versionRepository,
        private readonly ThemeRepositoryInterface $themeRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Render a preview of a specific version's template content
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();
        $versionId = $this->intParam('version_id');

        if (!$versionId) {
            return $resultJson->setData([
                'success' => false,
                'message' => (string)__('Version ID is required.'),
            ]);
        }

        try {
            $version = $this->versionRepository->getById($versionId);
            $content = $version->getTemplateContent();

            if ($content === null || $content === '') {
                return $resultJson->setData([
                    'success' => false,
                    'message' => (string)__('Version has no template content.'),
                ]);
            }

            $html = $this->templateRenderer->render(
                $content,
                [],
                $version->getStoreId(),
                $version->getCustomCss(),
                $version->getTailwindCss(),
                $version->getTemplateIdentifier(),
                false,
                $this->themeOf($version)
            );

            return $resultJson->setData([
                'success' => true,
                'html' => $html,
            ]);
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The theme the version was saved with, so it is previewed the way it was sent
     *
     * Null - the store's default theme - when the version names none, or names one that has since
     * been deleted.
     *
     * @param TemplateVersionInterface $version
     * @return string|null
     */
    private function themeOf(TemplateVersionInterface $version): ?string
    {
        $themeId = $version->getThemeId();

        if ($themeId === null) {
            return null;
        }

        try {
            return $this->themeRepository->getById($themeId)->getThemeCss();
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
