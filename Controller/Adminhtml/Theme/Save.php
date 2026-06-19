<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Theme;

use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterfaceFactory;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeJsonValidatorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::themes';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param ThemeRepositoryInterface $themeRepository
     * @param ThemeInterfaceFactory $themeFactory
     * @param ThemeJsonValidatorInterface $themeJsonValidator
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ThemeRepositoryInterface $themeRepository,
        private readonly ThemeInterfaceFactory $themeFactory,
        private readonly ThemeJsonValidatorInterface $themeJsonValidator
    ) {
        parent::__construct($context);
    }

    /**
     * Save or update a theme with validated JSON configuration
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

            if ($themeId && $name === '') {
                $theme = $this->themeRepository->getById($themeId);
                $name = $theme->getName();
            }

            if ($name === '') {
                return $resultJson->setData([
                    'success' => false,
                    'message' => (string)__('Theme name is required.'),
                ]);
            }

            if ($themeCss === '') {
                return $resultJson->setData([
                    'success' => false,
                    'message' => (string)__('Theme CSS is required.'),
                ]);
            }

            if (!$this->themeJsonValidator->validate($themeCss)) {
                $errors = $this->themeJsonValidator->getErrors();

                return $resultJson->setData([
                    'success' => false,
                    'message' => (string)__('Invalid theme CSS: %1', implode(', ', $errors)),
                ]);
            }

            if ($themeId) {
                if (!isset($theme)) {
                    $theme = $this->themeRepository->getById($themeId);
                }
            } else {
                $theme = $this->themeFactory->create();
            }

            $theme->setName($name);
            $theme->setThemeCss($themeCss);
            $theme->setStoreId($storeId);
            $this->themeRepository->save($theme);

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
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
