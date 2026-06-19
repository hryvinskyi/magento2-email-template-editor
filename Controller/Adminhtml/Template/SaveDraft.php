<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Template;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterfaceFactory;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class SaveDraft extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::editor';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param TemplateOverrideRepositoryInterface $overrideRepository
     * @param TemplateOverrideInterfaceFactory $overrideFactory
     * @param AuthSession $authSession
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TemplateOverrideRepositoryInterface $overrideRepository,
        private readonly TemplateOverrideInterfaceFactory $overrideFactory,
        private readonly AuthSession $authSession
    ) {
        parent::__construct($context);
    }

    /**
     * Save a template override as a draft
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $entityId = $this->getRequest()->getParam('entity_id');
            $templateIdentifier = (string)$this->getRequest()->getParam('template_identifier', '');
            $storeId = (int)$this->getRequest()->getParam('store_id', 0);
            $templateContent = (string)$this->getRequest()->getParam('template_content', '');
            $templateSubject = $this->getRequest()->getParam('template_subject');
            $customCss = $this->getRequest()->getParam('custom_css');
            $tailwindCss = $this->getRequest()->getParam('tailwind_css');
            $themeId = $this->getRequest()->getParam('theme_id');
            $draftName = $this->getRequest()->getParam('draft_name');
            $activeFrom = $this->getRequest()->getParam('active_from');
            $activeTo = $this->getRequest()->getParam('active_to');
            $providerCode = $this->getRequest()->getParam('provider_code');
            $customVariables = $this->getRequest()->getParam('custom_variables');

            if ($templateIdentifier === '') {
                return $resultJson->setData([
                    'success' => false,
                    'message' => (string)__('Template identifier is required.'),
                ]);
            }

            if ($templateContent === '') {
                return $resultJson->setData([
                    'success' => false,
                    'message' => (string)__('Template content is required.'),
                ]);
            }

            $forceNew = (bool)$this->getRequest()->getParam('force_new', false);
            $isNew = false;

            if (!$forceNew && $entityId !== null && $entityId !== '') {
                $draft = $this->overrideRepository->getById((int)$entityId);
            } elseif ($forceNew) {
                $isNew = true;
                $draft = $this->overrideFactory->create();
                $draft->setTemplateIdentifier($templateIdentifier);
                $draft->setStoreId($storeId);
                $draft->setStatus(TemplateOverrideInterface::STATUS_DRAFT);
            } else {
                $draft = $this->overrideRepository->getDraft($templateIdentifier, $storeId);

                if ($draft === null) {
                    $isNew = true;
                    $draft = $this->overrideFactory->create();
                    $draft->setTemplateIdentifier($templateIdentifier);
                    $draft->setStoreId($storeId);
                    $draft->setStatus(TemplateOverrideInterface::STATUS_DRAFT);
                }
            }

            $draft->setTemplateContent($templateContent);
            $draft->setTemplateSubject($templateSubject !== null && $templateSubject !== '' ? (string)$templateSubject : null);
            $draft->setCustomCss($customCss !== null && $customCss !== '' ? (string)$customCss : null);
            $draft->setTailwindCss($tailwindCss !== null && $tailwindCss !== '' ? (string)$tailwindCss : null);
            $draft->setThemeId($themeId !== null && $themeId !== '' ? (int)$themeId : null);
            // Only overwrite the draft name when the client actually provides it. Autosave
            // and publish omit draft_name, so treating an absent value as "clear" would wipe
            // a named draft's name on the next save (leaving it shown as "Untitled").
            if ($draftName !== null && $draftName !== '') {
                $draft->setDraftName((string)$draftName);
            }
            $draft->setActiveFrom($activeFrom !== null && $activeFrom !== '' ? (string)$activeFrom : null);
            $draft->setActiveTo($activeTo !== null && $activeTo !== '' ? (string)$activeTo : null);

            // Remember which preview data source this override was last edited with, so
            // re-opening it restores that selection instead of defaulting to the primary
            // provider. The custom sample-data JSON is only meaningful for the "custom"
            // provider, so it is stored only then and cleared otherwise to avoid stale data.
            $providerCode = $providerCode !== null && $providerCode !== '' ? (string)$providerCode : null;
            $draft->setSampleProviderCode($providerCode);
            $draft->setCustomVariables(
                $providerCode === 'custom' && $customVariables !== null && $customVariables !== ''
                    ? (string)$customVariables
                    : null
            );

            $adminUser = $this->authSession->getUser();

            if ($isNew && $adminUser !== null) {
                $draft->setCreatedByUserId((int)$adminUser->getId());
                $draft->setCreatedByUsername((string)$adminUser->getUserName());
            }

            if ($adminUser !== null) {
                $draft->setLastEditedByUserId((int)$adminUser->getId());
                $draft->setLastEditedByUsername((string)$adminUser->getUserName());
            }

            $draft->setLastEditedAt((new \DateTime())->format('Y-m-d H:i:s'));
            $this->overrideRepository->save($draft);

            return $resultJson->setData([
                'success' => true,
                'entity_id' => $draft->getEntityId(),
                'message' => (string)__('Draft saved successfully.'),
            ]);
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

}
