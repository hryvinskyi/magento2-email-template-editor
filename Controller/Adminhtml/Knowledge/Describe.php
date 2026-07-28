<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DescribeContextInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\KnowledgeSerializerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ModifierRegistryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueResolverInterface;
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
 * Answers a batch of "what is this directive, and what does it render as right now".
 *
 * The editor asks about every directive in a document at once, which makes three things matter here.
 *
 * The batch is bounded. A document is scanned in the browser and the list of keys arrives from it, so
 * its length is whatever the browser decided; an unbounded list would let one request describe an
 * arbitrary number of references, each of which reads configuration. The overflow is reported rather
 * than dropped, so a browser can tell a short answer from a complete one and ask again for the rest.
 *
 * One bad key does not cost the others. A key the grammar refuses is answered with a block saying so
 * and the rest of the batch is described as usual: a single unreadable directive in a template must
 * not leave the panel with nothing to say about the ninety-nine beside it.
 *
 * And the context that says which template is being described is cleared whatever happens. It is
 * request-scoped state read by a source of knowledge that cannot answer without it, and a template
 * identifier left behind by a failed batch would not make the next one fail - it would make it answer
 * confidently about the wrong template.
 */
class Describe extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_EmailTemplateEditor::editor';

    /**
     * How many references one request may ask about
     *
     * The browser batches to this figure. Anything beyond it is reported as left out rather than
     * described, because describing an unbounded list would read configuration once per reference on
     * behalf of whatever the browser happened to send.
     */
    private const MAX_REFERENCES = 200;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param DirectiveReferenceParserInterface $referenceParser
     * @param VariableKnowledgeRegistryInterface $knowledgeRegistry
     * @param ReferenceValueResolverInterface $valueResolver
     * @param ModifierRegistryInterface $modifierRegistry
     * @param DescribeContextInterface $describeContext
     * @param KnowledgeSerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly DirectiveReferenceParserInterface $referenceParser,
        private readonly VariableKnowledgeRegistryInterface $knowledgeRegistry,
        private readonly ReferenceValueResolverInterface $valueResolver,
        private readonly ModifierRegistryInterface $modifierRegistry,
        private readonly DescribeContextInterface $describeContext,
        private readonly KnowledgeSerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Describe every reference the request asks about, and publish the modifier vocabulary
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        $storeId = (int)$this->getRequest()->getParam('store_id', 0);
        $templateId = (string)$this->getRequest()->getParam('template_id', '');
        $requested = $this->requestedReferences();
        $described = array_slice($requested, 0, self::MAX_REFERENCES);

        try {
            // Stated inside the guarded block, not before it, so that the clearing below covers it
            // however it went. A template identifier left standing is not a failure that shows: the
            // next batch would be described against it and answer confidently about another template.
            $this->describeContext->set($templateId, $storeId);

            $entries = [];

            foreach ($described as $reference) {
                $entries[$reference] = $this->describe($reference, $storeId, $templateId);
            }

            return $resultJson->setData([
                'success' => true,
                'truncated' => count($requested) > self::MAX_REFERENCES,
                'entries' => $entries,
                // The same for every request. It is sent with each answer rather than from an
                // endpoint of its own so that a panel opened on the first directive an administrator
                // clicks already knows the vocabulary, without a second round trip.
                'modifiers' => $this->serializer->serializeModifiers($this->modifierRegistry->getAll()),
            ]);
        } catch (LocalizedException $exception) {
            return $resultJson->setData([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Describing directive references failed: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $resultJson->setData([
                'success' => false,
                'message' => (string)__('The directives in this template could not be described.'),
            ]);
        } finally {
            $this->describeContext->clear();
        }
    }

    /**
     * Describe one reference, or say plainly that its key could not be read
     *
     * @param string $reference Canonical reference string as the browser sent it
     * @param int $storeId Store view the description is asked for
     * @param string $templateId Template the reference was found in
     * @return array<string, mixed>
     */
    private function describe(string $reference, int $storeId, string $templateId): array
    {
        try {
            $parsed = $this->referenceParser->parse($reference);
        } catch (InvalidArgumentException) {
            // Not logged. The keys arrive from a scan of a document an administrator is editing, so a
            // half-typed directive produces one of these on every keystroke; recording each would bury
            // the log without telling anybody anything the answer does not already say.
            return $this->serializer->serializeUnreadableReference($reference);
        }

        $entry = $this->knowledgeRegistry->describe($parsed, $storeId);

        return $this->serializer->serializeEntry(
            $entry,
            $this->valueResolver->resolve($entry, $storeId, $templateId)
        );
    }

    /**
     * The reference keys the request asks about
     *
     * Anything that is not a string is not a reference and is dropped here rather than being coerced
     * into one: a coerced value would be answered as an unreadable key, which says the browser sent
     * something malformed when in truth it sent something that is not a key at all.
     *
     * @return string[]
     */
    private function requestedReferences(): array
    {
        $requested = $this->getRequest()->getParam('references', []);

        if (is_string($requested)) {
            $requested = [$requested];
        }

        if (!is_array($requested)) {
            return [];
        }

        return array_values(array_filter($requested, 'is_string'));
    }
}
