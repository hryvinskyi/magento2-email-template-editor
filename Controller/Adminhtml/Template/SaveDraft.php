<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Template;

use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface as Override;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateDraftSaverInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\ReadsRequestParameters;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DataObject;

class SaveDraft extends Action implements HttpPostActionInterface
{
    use ReadsRequestParameters;

    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::editor';

    /**
     * Request fields stored with a draft besides its body; one the request leaves out keeps its value
     */
    private const OPTIONAL_FIELDS = [
        Override::TEMPLATE_SUBJECT,
        Override::CUSTOM_CSS,
        Override::TAILWIND_CSS,
        Override::THEME_ID,
        Override::DRAFT_NAME,
        Override::ACTIVE_FROM,
        Override::ACTIVE_TO,
        'provider_code' => Override::SAMPLE_PROVIDER_CODE,
        Override::CUSTOM_VARIABLES,
    ];

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param TemplateDraftSaverInterface $draftSaver
     * @param AuthSession $authSession
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TemplateDraftSaverInterface $draftSaver,
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
            $templateIdentifier = $this->stringParam('template_identifier');
            $templateContent = $this->stringParam('template_content');
            $entityId = $this->optionalStringParam('entity_id');

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

            $saved = $this->draftSaver->save(
                $templateIdentifier,
                $this->intParam('store_id'),
                $templateContent,
                $this->presentFields(),
                $entityId !== null ? (int)$entityId : null,
                (bool)$this->getRequest()->getParam('force_new', false),
                $this->stringParam('intent') === 'publish',
                $this->author()
            );

            return $resultJson->setData([
                'success' => true,
                'entity_id' => $saved['entity_id'],
                'forked' => $saved['forked'],
                'message' => (string)__('Draft saved successfully.'),
            ]);
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The optional fields the request actually carries, keyed by column
     *
     * @return array<string, string>
     */
    private function presentFields(): array
    {
        $fields = [];

        foreach (self::OPTIONAL_FIELDS as $param => $column) {
            $value = $this->getRequest()->getParam(is_string($param) ? $param : $column);

            if (is_scalar($value)) {
                $fields[$column] = (string)$value;
            }
        }

        return $fields;
    }

    /**
     * The administrator making the change, if one is signed in
     *
     * @return array{id: int, name: string}|null
     */
    private function author(): ?array
    {
        $user = $this->authSession->getUser();

        if (!$user instanceof DataObject) {
            return null;
        }

        $id = $user->getData('user_id');
        $name = $user->getData('username');

        return is_numeric($id) && is_string($name) ? ['id' => (int)$id, 'name' => $name] : null;
    }
}
