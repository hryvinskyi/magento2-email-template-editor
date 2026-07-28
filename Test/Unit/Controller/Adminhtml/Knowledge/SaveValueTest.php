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
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueWriterInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeRegistryInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Knowledge\SaveValue;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\KnowledgeSerializer;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The endpoint that changes the value a directive reads.
 *
 * Built by hand, like the endpoint beside it: the backend action base reads its context entirely
 * through getters, so a mocked context with only the request stubbed is enough.
 *
 * What the refusals are asserted on is not that an exception came out but that no change was made and
 * that the answer says why. A refusal that still wrote, or one whose message says nothing, is worse
 * than no refusal at all, because both read from the browser as a guard that works.
 */
class SaveValueTest extends TestCase
{
    private const STORE_ID = 3;

    private const REFERENCE = 'config:general/store_information/name';

    private RequestInterface&MockObject $request;

    private Json&MockObject $resultJson;

    private VariableKnowledgeRegistryInterface&MockObject $knowledgeRegistry;

    private ReferenceValueWriterInterface&MockObject $valueWriter;

    private LoggerInterface&MockObject $logger;

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
            'reference' => self::REFERENCE,
            'value' => 'Acme Ltd',
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

        $this->valueWriter = $this->createMock(ReferenceValueWriterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @return void
     */
    public function testTheAnswerCarriesTheValueTheWriterReadBackRatherThanTheOneThatWasTyped(): void
    {
        $this->params['value'] = '  Acme Ltd  ';

        $this->valueWriter->method('write')->willReturn(new ResolvedValue(
            true,
            true,
            'Acme Ltd',
            false,
            ResolvedValueInterface::SCOPE_STORE,
            self::STORE_ID,
            'Theitbay Store View'
        ));

        $this->controller()->execute();

        self::assertTrue($this->payload['success']);
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
            $this->payload['value']
        );
        self::assertNotSame('  Acme Ltd  ', $this->payload['value']['preview']);
    }

    /**
     * @return void
     */
    public function testTheScopeReportedIsTheOneTheWriterWroteNotTheOneTheRequestAskedFor(): void
    {
        $this->valueWriter->method('write')->willReturn(new ResolvedValue(
            true,
            true,
            'Acme Ltd',
            false,
            ResolvedValueInterface::SCOPE_DEFAULT,
            0,
            'Default Config'
        ));

        $this->controller()->execute();

        self::assertSame('default', $this->payload['value']['scope']);
        self::assertSame(0, $this->payload['value']['scopeId']);
        self::assertSame('Default Config', $this->payload['value']['scopeLabel']);
    }

    /**
     * @return void
     */
    public function testTheStoreViewReachesTheWriterExactlyAsItWasSubmitted(): void
    {
        $this->params['store_id'] = 999;

        $this->valueWriter->expects(self::once())
            ->method('write')
            ->with(self::anything(), 999, 'Acme Ltd')
            ->willReturn(new ResolvedValue());

        $this->controller()->execute();

        self::assertTrue($this->payload['success']);
    }

    /**
     * @return void
     */
    public function testARefusalBecomesAnUnsuccessfulAnswerCarryingTheReasonItNamed(): void
    {
        $this->valueWriter->method('write')->willThrowException(
            new LocalizedException(
                new Phrase('The value behind "config:web/secure/base_url" is not one this editor changes.')
            )
        );

        $this->logger->expects(self::never())->method('error');

        $this->controller()->execute();

        self::assertFalse($this->payload['success']);
        self::assertSame(
            'The value behind "config:web/secure/base_url" is not one this editor changes.',
            $this->payload['message']
        );
        self::assertArrayNotHasKey('value', $this->payload);
    }

    /**
     * @return void
     */
    public function testAMissingPermissionIsReportedWithTheResourceItNamed(): void
    {
        $this->valueWriter->method('write')->willThrowException(
            new AuthorizationException(new Phrase('Changing this value requires Magento_Config::config.'))
        );

        $this->controller()->execute();

        self::assertFalse($this->payload['success']);
        self::assertSame('Changing this value requires Magento_Config::config.', $this->payload['message']);
    }

    /**
     * @return void
     */
    public function testAnUnexpectedFailureIsLoggedAndAnsweredWithoutRepeatingWhatItSaid(): void
    {
        $failure = new RuntimeException('SQLSTATE[42S02]: Base table core_config_data is missing.');

        $this->valueWriter->method('write')->willThrowException($failure);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains(self::REFERENCE), ['exception' => $failure]);

        $this->controller()->execute();

        self::assertFalse($this->payload['success']);
        self::assertStringNotContainsString('SQLSTATE', $this->payload['message']);
        self::assertStringNotContainsString('core_config_data', $this->payload['message']);
    }

    /**
     * @return void
     */
    public function testAReferenceThatCannotBeReadChangesNothing(): void
    {
        $this->params['reference'] = 'not a reference at all';

        $this->knowledgeRegistry->expects(self::never())->method('describe');
        $this->valueWriter->expects(self::never())->method('write');

        $this->controller()->execute();

        self::assertFalse($this->payload['success']);
        self::assertNotSame('', $this->payload['message']);
    }

    /**
     * The controller under test, wired to whatever the current test has arranged
     *
     * @return SaveValue
     */
    private function controller(): SaveValue
    {
        $resultJsonFactory = $this->createMock(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($this->resultJson);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        return new SaveValue(
            $context,
            $resultJsonFactory,
            new DirectiveReferenceParser(),
            $this->knowledgeRegistry,
            $this->valueWriter,
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
            true,
            EditAffordance::inline('Store name', 'text')
        );
    }
}
