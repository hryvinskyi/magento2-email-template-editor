<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Model\TemplateVariableDeclarations;
use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TemplateVariableDeclarationsTest extends TestCase
{
    private const TEMPLATE_ID = 'sales_email_order_template';

    private TemplateFactory&MockObject $templateFactory;
    private Template&MockObject $template;
    private PluginBypassFlagInterface&MockObject $pluginBypassFlag;
    private LoggerInterface&MockObject $logger;
    private TemplateVariableDeclarations $declarations;

    /**
     * Bypass-flag and template-load events in the order they happened
     *
     * @var array<int, string>
     */
    private array $events = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->template = $this->createMock(Template::class);
        $this->templateFactory = $this->createFactoryMock(TemplateFactory::class);
        $this->templateFactory->method('create')->willReturn($this->template);

        $this->pluginBypassFlag = $this->createMock(PluginBypassFlagInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->events = [];
        $this->pluginBypassFlag->method('enable')->willReturnCallback(function (): void {
            $this->events[] = 'enable';
        });
        $this->pluginBypassFlag->method('disable')->willReturnCallback(function (): void {
            $this->events[] = 'disable';
        });

        $this->declarations = new TemplateVariableDeclarations(
            $this->templateFactory,
            $this->pluginBypassFlag,
            $this->logger
        );
    }

    /**
     * Only the template's own declarations are wanted, so the override overlay is switched off for
     * the load.
     *
     * @return void
     */
    public function testTheOverrideOverlayIsBypassedAroundTheLoad(): void
    {
        $this->stubTemplateLoad('{"var order.increment_id":"Order Id"}');

        $this->declarations->getDeclarations(self::TEMPLATE_ID);

        self::assertSame(['enable', 'loadDefault', 'disable'], $this->events);
    }

    /**
     * Left raised, the flag would silently suppress overrides for the rest of the request - which is
     * worse than the failed load it followed.
     *
     * @return void
     */
    public function testTheBypassFlagIsLoweredWhenTheLoadThrows(): void
    {
        $this->template->method('loadDefault')->willReturnCallback(function (): void {
            $this->events[] = 'loadDefault';

            throw new \RuntimeException('template is broken');
        });
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('template is broken'));

        self::assertSame([], $this->declarations->getDeclarations(self::TEMPLATE_ID));
        self::assertSame(['enable', 'loadDefault', 'disable'], $this->events);
    }

    public function testTheTemplateIsLoadedForItsOwnAreaAndIdentifier(): void
    {
        $this->stubTemplateLoad('{"var order.increment_id":"Order Id"}');

        $this->template->expects(self::once())->method('setForcedArea')->with(self::TEMPLATE_ID);
        $this->template->expects(self::once())->method('loadDefault')->with(self::TEMPLATE_ID);

        $this->declarations->getDeclarations(self::TEMPLATE_ID);
    }

    /**
     * A declaration key is written with braces by some templates and without by others, and the two
     * spell one directive. Unwrapping here is what stops every caller from having to know which.
     *
     * @return void
     */
    public function testDeclarationsComeBackWithoutBracesHoweverTheyWereWritten(): void
    {
        $this->stubTemplateLoad(
            '{"var order.increment_id":"Order Id","{{store url=\'\'}}":"Store Url"}'
        );

        self::assertSame(
            [
                'var order.increment_id' => 'Order Id',
                'store url=\'\'' => 'Store Url',
            ],
            $this->declarations->getDeclarations(self::TEMPLATE_ID)
        );
    }

    public function testASurroundingWhitespaceOnlyDifferenceIsNotTwoDeclarations(): void
    {
        $this->stubTemplateLoad('{"  var order.increment_id  ":"Order Id","{{ var order.increment_id }}":"Again"}');

        self::assertSame(
            ['var order.increment_id' => 'Again'],
            $this->declarations->getDeclarations(self::TEMPLATE_ID)
        );
    }

    /**
     * @dataProvider nothingDeclaredProvider
     *
     * @param string $rawDeclarations Value of the template's declaration annotation
     * @return void
     */
    public function testAnnotationsThatDeclareNothingUsableYieldNoDeclarations(string $rawDeclarations): void
    {
        $this->stubTemplateLoad($rawDeclarations);

        self::assertSame([], $this->declarations->getDeclarations(self::TEMPLATE_ID));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function nothingDeclaredProvider(): array
    {
        return [
            'no annotation at all' => [''],
            'not valid json' => ['{not json'],
            'json that is not a map' => ['"a string"'],
            'a key that is nothing but braces' => ['{"{{}}":"Empty"}'],
        ];
    }

    /**
     * A description request asks about many directives at once, and every one of them would
     * otherwise repeat the same file load.
     *
     * @return void
     */
    public function testTheTemplateIsReadOnceHoweverOftenItIsAskedFor(): void
    {
        $this->stubTemplateLoad('{"var order.increment_id":"Order Id"}');

        $this->templateFactory->expects(self::once())->method('create')->willReturn($this->template);

        $this->declarations->getDeclarations(self::TEMPLATE_ID);
        $this->declarations->getDeclarations(self::TEMPLATE_ID);
        $this->declarations->getDeclarations(self::TEMPLATE_ID);
    }

    /**
     * Retrying a load that failed would not make it succeed; it would repeat the same log line for
     * every directive asked about afterwards.
     *
     * @return void
     */
    public function testALoadThatFailedIsNotRetriedWithinTheRequest(): void
    {
        $this->template->method('loadDefault')->willThrowException(new \RuntimeException('template is broken'));
        $this->logger->expects(self::once())->method('error');

        $this->declarations->getDeclarations(self::TEMPLATE_ID);
        $this->declarations->getDeclarations(self::TEMPLATE_ID);
    }

    public function testTwoTemplatesAreReadSeparately(): void
    {
        $this->template->method('loadDefault')->willReturn($this->template);
        $this->template->method('getData')->with('orig_template_variables')->willReturnOnConsecutiveCalls(
            '{"var order.increment_id":"Order Id"}',
            '{"var customer.name":"Customer Name"}'
        );

        self::assertSame(
            ['var order.increment_id' => 'Order Id'],
            $this->declarations->getDeclarations('sales_email_order_template')
        );
        self::assertSame(
            ['var customer.name' => 'Customer Name'],
            $this->declarations->getDeclarations('customer_create_account_email_template')
        );
    }

    /**
     * Record the load and answer it with the given raw declarations
     *
     * @param string $rawDeclarations Value of the template's declaration annotation
     * @return void
     */
    private function stubTemplateLoad(string $rawDeclarations): void
    {
        $this->template->method('loadDefault')->willReturnCallback(function () {
            $this->events[] = 'loadDefault';

            return $this->template;
        });
        $this->template->method('getData')->with('orig_template_variables')->willReturn($rawDeclarations);
    }

    /**
     * Build a mock for a factory that may only exist as a DI-generated class
     *
     * Such a factory is autoloadable on an installation where it has been generated and absent on
     * one where it has not, and PHPUnit needs a different builder call for each case: onlyMethods()
     * for the real class, addMethods() for the empty stand-in it declares in place of the missing
     * one. Deciding here keeps the suite runnable either way.
     *
     * @param class-string $factoryClass
     * @return MockObject
     */
    private function createFactoryMock(string $factoryClass): MockObject
    {
        $builder = $this->getMockBuilder($factoryClass)->disableOriginalConstructor();

        return method_exists($factoryClass, 'create')
            ? $builder->onlyMethods(['create'])->getMock()
            : $builder->addMethods(['create'])->getMock();
    }
}
