<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Controller\Adminhtml\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ModifierRegistryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeRegistryInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Knowledge\Describe;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ModifierDescriptor;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DescribeContext;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\KnowledgeSerializer;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The endpoint that describes a document's directives.
 *
 * There is no Magento boot behind these tests, so the controller is built by hand. The backend action
 * base takes a context carrying a large collaborator graph and reads it entirely through getters, so
 * a mocked context with only the request stubbed is enough for a controller to be constructed and
 * exercised; nothing else on the base is touched by an endpoint that only reads parameters and
 * returns a JSON result.
 *
 * The parser, the serializer and the describe context are used for real rather than mocked. All three
 * are dependency-free, and the questions worth asking here - is an unreadable key really unreadable,
 * is the answer really the shape the browser reads, was the template really forgotten - stop meaning
 * anything when the thing under test is a double that was told to say yes.
 */
class DescribeTest extends TestCase
{
    private const STORE_ID = 3;

    private const TEMPLATE_ID = 'order_new';

    private RequestInterface&MockObject $request;

    private Json&MockObject $resultJson;

    private VariableKnowledgeRegistryInterface&MockObject $knowledgeRegistry;

    private ReferenceValueResolverInterface&MockObject $valueResolver;

    private ModifierRegistryInterface&MockObject $modifierRegistry;

    private LoggerInterface&MockObject $logger;

    private DescribeContext $describeContext;

    /**
     * Request parameters the controller reads
     *
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * What the controller last handed to the JSON result
     *
     * @var array<string, mixed>|null
     */
    private ?array $payload = null;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->params = [
            'store_id' => self::STORE_ID,
            'template_id' => self::TEMPLATE_ID,
            'references' => [],
        ];

        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default
        );

        $this->resultJson = $this->createMock(Json::class);
        $this->resultJson->method('setData')->willReturnCallback(
            function (mixed $data): Json {
                $this->payload = $data;

                return $this->resultJson;
            }
        );

        $this->knowledgeRegistry = $this->createMock(VariableKnowledgeRegistryInterface::class);
        $this->knowledgeRegistry->method('describe')->willReturnCallback(
            fn (DirectiveReferenceInterface $reference): VariableKnowledgeInterface => $this->entry($reference)
        );

        $this->valueResolver = $this->createMock(ReferenceValueResolverInterface::class);
        $this->valueResolver->method('resolve')->willReturn(new ResolvedValue());

        $this->modifierRegistry = $this->createMock(ModifierRegistryInterface::class);
        $this->modifierRegistry->method('getAll')->willReturn([
            new ModifierDescriptor('escape', 'Escape', 'Escapes the value.'),
        ]);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->describeContext = new DescribeContext();
    }

    /**
     * @return void
     */
    public function testEveryRequestedReferenceIsAnsweredIncludingOneWhoseKeyCannotBeRead(): void
    {
        $this->params['references'] = [
            'config:general/store_information/name',
            'not a reference at all',
            'var:order.increment_id',
        ];

        $this->controller()->execute();

        self::assertTrue($this->payload['success']);
        self::assertFalse($this->payload['truncated']);
        self::assertSame(
            [
                'config:general/store_information/name',
                'not a reference at all',
                'var:order.increment_id',
            ],
            array_keys($this->payload['entries'])
        );
        self::assertTrue($this->payload['entries']['config:general/store_information/name']['known']);
        self::assertFalse($this->payload['entries']['not a reference at all']['known']);
        self::assertSame(
            'not a reference at all',
            $this->payload['entries']['not a reference at all']['reference']
        );
    }

    /**
     * @return void
     */
    public function testTheBatchIsCappedAndTheOverflowIsReportedRatherThanDropped(): void
    {
        $references = [];

        for ($index = 1; $index <= 201; $index++) {
            $references[] = 'var:field' . $index;
        }

        $this->params['references'] = $references;

        $this->controller()->execute();

        self::assertTrue($this->payload['truncated']);
        self::assertCount(200, $this->payload['entries']);
        self::assertArrayHasKey('var:field200', $this->payload['entries']);
        self::assertArrayNotHasKey('var:field201', $this->payload['entries']);
    }

    /**
     * @return void
     */
    public function testTheModifierVocabularyTravelsWithEveryAnswer(): void
    {
        $this->controller()->execute();

        self::assertSame(
            [['name' => 'escape', 'label' => 'Escape', 'description' => 'Escapes the value.',
                'implemented' => true, 'arguments' => []]],
            $this->payload['modifiers']
        );
    }

    /**
     * @return void
     */
    public function testTheTemplateBeingDescribedIsStatedWhileDescribingAndForgottenAfterwards(): void
    {
        $seen = [];

        $registry = $this->createMock(VariableKnowledgeRegistryInterface::class);
        $registry->method('describe')->willReturnCallback(
            function (DirectiveReferenceInterface $reference) use (&$seen): VariableKnowledgeInterface {
                $seen[] = [$this->describeContext->getTemplateId(), $this->describeContext->getStoreId()];

                return $this->entry($reference);
            }
        );
        $this->knowledgeRegistry = $registry;
        $this->params['references'] = ['var:order.increment_id'];

        $this->controller()->execute();

        self::assertSame([[self::TEMPLATE_ID, self::STORE_ID]], $seen);
        self::assertSame('', $this->describeContext->getTemplateId());
        self::assertSame(0, $this->describeContext->getStoreId());
    }

    /**
     * @return void
     */
    public function testTheTemplateIsForgottenEvenWhenDescribingFails(): void
    {
        $this->knowledgeRegistry = $this->createMock(VariableKnowledgeRegistryInterface::class);
        $this->knowledgeRegistry->method('describe')->willThrowException(
            new RuntimeException('No edit affordance resolver claims an origin of kind "config".')
        );
        $this->params['references'] = ['var:order.increment_id'];

        $this->controller()->execute();

        self::assertSame('', $this->describeContext->getTemplateId());
        self::assertSame(0, $this->describeContext->getStoreId());
    }

    /**
     * @return void
     */
    public function testAnUnexpectedFailureIsLoggedAndAnsweredWithoutRepeatingWhatItSaid(): void
    {
        $failure = new RuntimeException('Table hryvinskyi_email_template_override does not exist.');

        $this->knowledgeRegistry = $this->createMock(VariableKnowledgeRegistryInterface::class);
        $this->knowledgeRegistry->method('describe')->willThrowException($failure);
        $this->params['references'] = ['var:order.increment_id'];

        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('Table hryvinskyi_email_template_override'), ['exception' => $failure]);

        $this->controller()->execute();

        self::assertFalse($this->payload['success']);
        self::assertStringNotContainsString(
            'hryvinskyi_email_template_override',
            $this->payload['message']
        );
        self::assertArrayNotHasKey('entries', $this->payload);
    }

    /**
     * @return void
     */
    public function testARefusalThatWordsItselfIsPassedOnUnchangedAndNotLogged(): void
    {
        $this->knowledgeRegistry = $this->createMock(VariableKnowledgeRegistryInterface::class);
        $this->knowledgeRegistry->method('describe')->willThrowException(
            new LocalizedException(new Phrase('This store view no longer exists.'))
        );
        $this->params['references'] = ['var:order.increment_id'];

        $this->logger->expects(self::never())->method('error');

        $this->controller()->execute();

        self::assertFalse($this->payload['success']);
        self::assertSame('This store view no longer exists.', $this->payload['message']);
    }

    /**
     * @return void
     */
    public function testTheValueBlockIsTheOneTheValueReaderProduced(): void
    {
        $this->valueResolver = $this->createMock(ReferenceValueResolverInterface::class);
        $this->valueResolver->expects(self::once())
            ->method('resolve')
            ->with(self::anything(), self::STORE_ID, self::TEMPLATE_ID)
            ->willReturn(new ResolvedValue(
                true,
                true,
                'Acme Ltd',
                false,
                ResolvedValueInterface::SCOPE_STORE,
                self::STORE_ID,
                'Theitbay Store View'
            ));
        $this->params['references'] = ['config:general/store_information/name'];

        $this->controller()->execute();

        self::assertSame(
            [
                'available' => true,
                'exact' => true,
                'truncated' => false,
                'scope' => 'stores',
                'scopeId' => self::STORE_ID,
                'scopeLabel' => 'Theitbay Store View',
                'preview' => 'Acme Ltd',
            ],
            $this->payload['entries']['config:general/store_information/name']['value']
        );
    }

    /**
     * @return void
     */
    public function testAReferenceListThatIsNotAListOfStringsIsIgnoredRatherThanCoerced(): void
    {
        $this->params['references'] = ['var:order.increment_id', ['nested'], 17];

        $this->controller()->execute();

        self::assertSame(['var:order.increment_id'], array_keys($this->payload['entries']));
    }

    /**
     * The controller under test, wired to whatever the current test has arranged
     *
     * @return Describe
     */
    private function controller(): Describe
    {
        $resultJsonFactory = $this->createMock(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($this->resultJson);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        return new Describe(
            $context,
            $resultJsonFactory,
            new DirectiveReferenceParser(),
            $this->knowledgeRegistry,
            $this->valueResolver,
            $this->modifierRegistry,
            $this->describeContext,
            new KnowledgeSerializer(),
            $this->logger
        );
    }

    /**
     * A knowledge entry standing in for whatever the base would have said
     *
     * @param DirectiveReferenceInterface $reference Reference the entry describes
     * @return VariableKnowledgeInterface
     */
    private function entry(DirectiveReferenceInterface $reference): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            $reference,
            true,
            'Store name',
            'The name of the store the message is sent from.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, $reference->getExpression(), 'Read from configuration.'),
            [],
            null,
            false,
            EditAffordance::link('Open Store Information', 'https://admin/config')
        );
    }
}
