<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\KnowledgeSerializerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueWriterInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeRegistryInterface;
use InvalidArgumentException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Changes the value a directive reads, at the place it is read from.
 *
 * Nothing that arrives here is taken on trust and nothing is decided here either. The reference is
 * read again from the string that was sent, the entry behind it is built again on the server, and
 * whether that value may be changed at all - by anybody, by this administrator, in this scope - is
 * settled by the writer. What is left for this endpoint is to hand those answers on: a refusal
 * becomes a message naming what was missing, and a change becomes the value as it now stands.
 *
 * That returned value is re-read after the change rather than echoed from what was typed. An echo
 * would confirm the administrator's own input back to them and would look identical whether the write
 * landed, landed somewhere else, or was quietly reshaped on the way in. The scope travels with it for
 * the same reason: naming the scope before a change is a promise, naming it afterwards is something
 * that can be checked against the configuration page.
 *
 * Every refusal is answered with a successful HTTP response carrying `success: false`, which is how
 * the rest of this module answers, and how its failure reporting in the browser expects to be told.
 */
class SaveValue extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::editor';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param DirectiveReferenceParserInterface $referenceParser
     * @param VariableKnowledgeRegistryInterface $knowledgeRegistry
     * @param ReferenceValueWriterInterface $valueWriter
     * @param KnowledgeSerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly DirectiveReferenceParserInterface $referenceParser,
        private readonly VariableKnowledgeRegistryInterface $knowledgeRegistry,
        private readonly ReferenceValueWriterInterface $valueWriter,
        private readonly KnowledgeSerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Write the value behind a reference and report what it now is, and where
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        // Left exactly as it arrived, including a store view that does not exist. Casting it into
        // something plausible here would hide the one case the writer has to refuse, and a change
        // written against a store view nobody can see reads, from the admin, as a save that did
        // nothing at all.
        $storeId = (int)$this->getRequest()->getParam('store_id', 0);
        $value = (string)$this->getRequest()->getParam('value', '');

        try {
            $reference = $this->referenceParser->parse(
                (string)$this->getRequest()->getParam('reference', '')
            );
        } catch (InvalidArgumentException) {
            return $resultJson->setData([
                'success' => false,
                'message' => (string)__('That directive could not be read, so nothing was changed.'),
            ]);
        }

        try {
            $entry = $this->knowledgeRegistry->describe($reference, $storeId);

            return $resultJson->setData([
                'success' => true,
                'value' => $this->serializer->serializeValue(
                    $this->valueWriter->write($entry, $storeId, $value)
                ),
            ]);
        } catch (LocalizedException $exception) {
            // Every refusal the writer makes arrives here, the missing-permission one included: it
            // is worded to name what was missing, and that wording is the whole point of it, so it
            // is passed on unchanged.
            return $resultJson->setData([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Changing the value behind "' . $reference->toCanonicalString() . '" failed: '
                . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $resultJson->setData([
                'success' => false,
                'message' => (string)__('The value could not be changed. See the log for details.'),
            ]);
        }
    }
}
