<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Theme;

use Hryvinskyi\EmailTemplateEditor\Api\ThemeWriterInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::themes';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param ThemeWriterInterface $themeWriter
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ThemeWriterInterface $themeWriter
    ) {
        parent::__construct($context);
    }

    /**
     * Create a theme, or update the content of an existing one
     *
     * A submitted `store_id` is honoured only when a theme is being created; it is ignored on an
     * update, because the scope a theme is resolved for is not part of its authored content.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $themeId = (int)$this->getRequest()->getParam('theme_id', 0);
            $name = (string)$this->getRequest()->getParam('name', '');
            $themeCss = (string)$this->getRequest()->getParam('theme_css', '');
            $storeId = (int)$this->getRequest()->getParam('store_id', 0);

            $theme = $themeId
                ? $this->themeWriter->updateContent($themeId, $themeCss, $name)
                : $this->themeWriter->create($name, $themeCss, $storeId);

            return $resultJson->setData([
                'success' => true,
                'theme' => [
                    'theme_id' => $theme->getThemeId(),
                    'name' => $theme->getName(),
                    'theme_css' => $theme->getThemeCss(),
                    'is_default' => $theme->getIsDefault(),
                    'store_id' => $theme->getStoreId(),
                ],
                'message' => (string)__('Theme saved successfully.'),
            ]);
        } catch (LocalizedException $e) {
            // Rejected input, an unknown theme id and a failed save all arrive here already phrased
            // for the admin.
            return $this->errorResponse($resultJson, $e->getMessage());
        } catch (\Exception $e) {
            return $this->errorResponse($resultJson, $e->getMessage());
        }
    }

    /**
     * Build the failure payload the theme editor expects
     *
     * @param Json $resultJson Result object created for this request.
     * @param string $message Message to surface to the admin.
     * @return Json
     */
    private function errorResponse(Json $resultJson, string $message): Json
    {
        return $resultJson->setData([
            'success' => false,
            'message' => $message,
        ]);
    }
}
