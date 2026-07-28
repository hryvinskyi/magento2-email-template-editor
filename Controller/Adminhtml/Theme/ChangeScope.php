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

class ChangeScope extends Action implements HttpPostActionInterface
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
     * Move a theme to another store scope
     *
     * The destination is read from `target_store_id`, not from `store_id`: every request the
     * editor sends already carries the store view currently chosen in its toolbar under
     * `store_id`, and that ambient value is a different thing from the scope this action moves
     * the theme to. Reading them from one parameter would make the two indistinguishable.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $themeId = (int)$this->getRequest()->getParam('theme_id', 0);
            $storeId = (int)$this->getRequest()->getParam('target_store_id', 0);

            $theme = $this->themeWriter->changeScope($themeId, $storeId);

            return $resultJson->setData([
                'success' => true,
                'theme' => [
                    'theme_id' => $theme->getThemeId(),
                    'name' => $theme->getName(),
                    'is_default' => $theme->getIsDefault(),
                    'store_id' => $theme->getStoreId(),
                ],
                'message' => (string)__('Theme scope updated.'),
            ]);
        } catch (LocalizedException $e) {
            // A refused move, an unknown theme id and a failed save all arrive here already
            // phrased for the admin.
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
