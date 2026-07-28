<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVariableDeclarationsInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DescribeContext;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\TemplateVarsKnowledgeProvider;
use Hryvinskyi\EmailTemplateEditor\Model\TemplateVariableDeclarations;
use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TemplateVarsKnowledgeProviderTest extends TestCase
{
    private const TEMPLATE_ID = 'sales_email_order_template';
    private const STORE_ID = 3;

    private TemplateVariableDeclarationsInterface&MockObject $declarations;
    private DirectiveReferenceParser $referenceParser;
    private DescribeContext $describeContext;
    private TemplateVarsKnowledgeProvider $provider;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->declarations = $this->createMock(TemplateVariableDeclarationsInterface::class);
        $this->referenceParser = new DirectiveReferenceParser();
        $this->describeContext = new DescribeContext();

        $this->provider = new TemplateVarsKnowledgeProvider(
            $this->declarations,
            $this->referenceParser,
            $this->describeContext
        );
    }

    public function testADeclaredVariableIsDescribedWithItsDeclaredLabel(): void
    {
        $this->declare(['var order.increment_id' => 'Order Id']);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        $entry = $this->provider->describe(
            $this->referenceParser->parse('var:order.increment_id'),
            self::STORE_ID
        );

        self::assertInstanceOf(VariableKnowledgeInterface::class, $entry);
        self::assertTrue($entry->isKnown());
        self::assertSame('Order Id', $entry->getTitle());
        self::assertSame(VariableKnowledgeInterface::OUTPUT_TEXT, $entry->getOutputKind());
        self::assertFalse($entry->isValueWritable());
    }

    /**
     * The template that declared the variable is what identifies it: the same name in another
     * template family is a different value, or no value at all.
     *
     * @return void
     */
    public function testTheOriginNamesTheTemplateThatDeclaredTheVariable(): void
    {
        $this->declare(['var order.increment_id' => 'Order Id']);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        $entry = $this->provider->describe(
            $this->referenceParser->parse('var:order.increment_id'),
            self::STORE_ID
        );

        self::assertNotNull($entry);
        self::assertSame(OriginInterface::KIND_TEMPLATE_VAR, $entry->getOrigin()->getKind());
        self::assertSame(self::TEMPLATE_ID, $entry->getOrigin()->getLocator());
    }

    /**
     * An email template's variable directive with no chain at all is escaped, so presenting it as
     * unformatted would invite an administrator to strip protection that is already there.
     *
     * @return void
     */
    public function testAVariableWithNoChainIsReportedAsEscaped(): void
    {
        $this->declare(['var order.increment_id' => 'Order Id']);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        $entry = $this->provider->describe(
            $this->referenceParser->parse('var:order.increment_id'),
            self::STORE_ID
        );

        self::assertNotNull($entry);
        self::assertSame('escape', $entry->getDefaultModifier());
    }

    /**
     * A template writes a declaration key with braces or without, and the two spell one directive.
     *
     * Read through the real declaration reader, because that is where the two spellings are brought
     * together and a test that stubbed it would only be checking its own fixture.
     *
     * @return void
     */
    public function testADeclarationWrittenWithBracesAndOneWithoutReachTheSameEntry(): void
    {
        $reference = $this->referenceParser->parse('var:order.increment_id');

        $withoutBraces = $this->providerReadingRealDeclarations('{"var order.increment_id":"Order Id"}')
            ->describe($reference, self::STORE_ID);
        $withBraces = $this->providerReadingRealDeclarations('{"{{var order.increment_id}}":"Order Id"}')
            ->describe($reference, self::STORE_ID);

        self::assertNotNull($withoutBraces);
        self::assertNotNull($withBraces);
        self::assertSame(
            $withoutBraces->getReference()->toCanonicalString(),
            $withBraces->getReference()->toCanonicalString()
        );
        self::assertSame($withBraces->getTitle(), $withoutBraces->getTitle());
    }

    /**
     * A template declares its variables with the formatting they are normally written with, and the
     * label it gives them has to be found by the directive an author actually typed.
     *
     * The sales templates declare an address as `var formattedShippingAddress|raw`, while the same
     * variable in the content is read up to that pipe and no further - that is where the filter
     * stops reading what a directive points at. Keyed with the chain attached, the label would be
     * lost for exactly the variables a template family bothered to format.
     *
     * @return void
     */
    public function testTheFormattingADeclarationCarriesIsNotPartOfWhatItPointsAt(): void
    {
        $this->declare(['var formattedShippingAddress|raw' => 'Shipping Address']);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        $entry = $this->provider->describe(
            $this->referenceParser->parse('var:formattedShippingAddress'),
            self::STORE_ID
        );

        self::assertNotNull($entry);
        self::assertSame('Shipping Address', $entry->getTitle());
    }

    /**
     * Whitespace inside a declared variable path is not significant to what it points at, so a
     * declaration and the directive an author typed reach one entry.
     *
     * @return void
     */
    public function testWhitespaceInsideADeclarationDoesNotMakeItADifferentVariable(): void
    {
        $this->declare(['var  order.increment_id' => 'Order Id']);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        self::assertNotNull(
            $this->provider->describe($this->referenceParser->parse('var:order.increment_id'), self::STORE_ID)
        );
    }

    /**
     * @dataProvider otherKindProvider
     *
     * @param string $canonical Canonical reference of another kind
     * @return void
     */
    public function testReferencesOfAnotherKindAreLeftAlone(string $canonical): void
    {
        $this->declarations->expects(self::never())->method('getDeclarations');
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        self::assertNull($this->provider->describe($this->referenceParser->parse($canonical), self::STORE_ID));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function otherKindProvider(): array
    {
        return [
            'a custom variable' => ['customVar:support_hours'],
            'a configuration path' => ['config:general/store_information/name'],
            'a store url' => ['store:'],
        ];
    }

    public function testAVariableTheTemplateDoesNotDeclareIsNotDescribed(): void
    {
        $this->declare(['var order.increment_id' => 'Order Id']);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        self::assertNull(
            $this->provider->describe($this->referenceParser->parse('var:customer.name'), self::STORE_ID)
        );
    }

    /**
     * With no template being described there is nothing this source is the authority on, and
     * answering out of whichever template was described last would be a wrong answer that looks
     * like a right one.
     *
     * @return void
     */
    public function testNothingIsDescribedWhileNoTemplateIsBeingDescribed(): void
    {
        $this->declarations->expects(self::never())->method('getDeclarations');

        self::assertNull(
            $this->provider->describe($this->referenceParser->parse('var:order.increment_id'), self::STORE_ID)
        );
        self::assertSame([], $this->provider->listAll(self::STORE_ID));
    }

    /**
     * Turning a declaration of another kind into a reference would mean guessing which of its
     * parameters identifies it, and a guess that is wrong produces a key nothing ever asks about.
     *
     * @return void
     */
    public function testDeclarationsOfOtherKindsAreNotListed(): void
    {
        $this->declare([
            'var order.increment_id' => 'Order Id',
            'store url=\'\'' => 'Store Url',
            'trans "Thank you"' => 'Thanks',
            'not a published kind' => 'Nonsense',
            'var' => 'A variable with no path at all',
        ]);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        self::assertSame(['var:order.increment_id'], $this->listedReferences());
    }

    public function testEveryDeclaredVariableIsListed(): void
    {
        $this->declare([
            'var order.increment_id' => 'Order Id',
            'var order.customer_name' => 'Customer Name',
        ]);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        self::assertSame(
            ['var:order.increment_id', 'var:order.customer_name'],
            $this->listedReferences()
        );
    }

    /**
     * A description request asks about many directives, and each would otherwise re-derive the same
     * references from the same declarations.
     *
     * @return void
     */
    public function testTheDeclarationsAreReadOnceForADescriptionOfManyReferences(): void
    {
        $this->declarations->expects(self::once())
            ->method('getDeclarations')
            ->with(self::TEMPLATE_ID)
            ->willReturn(['var order.increment_id' => 'Order Id']);
        $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);

        foreach (['order.increment_id', 'customer.name', 'order.increment_id'] as $path) {
            $this->provider->describe($this->referenceParser->create('var', $path), self::STORE_ID);
        }

        $this->provider->listAll(self::STORE_ID);
    }

    /**
     * The discipline the caller owes this context: set it, and clear it in a finally. A template
     * identifier left behind by a failed description would be answered out of on the next one.
     *
     * @return void
     */
    public function testAFailedDescriptionLeavesNoTemplateBehindWhenTheCallerClearsInAFinally(): void
    {
        $this->declarations->method('getDeclarations')
            ->willThrowException(new \RuntimeException('the template is unreadable'));

        try {
            $this->describeContext->set(self::TEMPLATE_ID, self::STORE_ID);
            $this->provider->describe($this->referenceParser->parse('var:order.increment_id'), self::STORE_ID);
            self::fail('The description was expected to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('the template is unreadable', $e->getMessage());
        } finally {
            $this->describeContext->clear();
        }

        self::assertSame('', $this->describeContext->getTemplateId());
        self::assertSame(0, $this->describeContext->getStoreId());
    }

    /**
     * Answer every declaration request with the given declarations
     *
     * @param array<string, string> $declarations Directive without its braces, mapped to its label
     * @return void
     */
    private function declare(array $declarations): void
    {
        $this->declarations->method('getDeclarations')->willReturn($declarations);
    }

    /**
     * A provider reading a template's raw declaration annotation through the real reader
     *
     * @param string $rawDeclarations Value of the template's declaration annotation
     * @return TemplateVarsKnowledgeProvider
     */
    private function providerReadingRealDeclarations(string $rawDeclarations): TemplateVarsKnowledgeProvider
    {
        $template = $this->createMock(Template::class);
        $template->method('loadDefault')->willReturn($template);
        $template->method('getData')->with('orig_template_variables')->willReturn($rawDeclarations);

        $templateFactoryBuilder = $this->getMockBuilder(TemplateFactory::class)->disableOriginalConstructor();
        $templateFactory = method_exists(TemplateFactory::class, 'create')
            ? $templateFactoryBuilder->onlyMethods(['create'])->getMock()
            : $templateFactoryBuilder->addMethods(['create'])->getMock();
        $templateFactory->method('create')->willReturn($template);

        $context = new DescribeContext();
        $context->set(self::TEMPLATE_ID, self::STORE_ID);

        return new TemplateVarsKnowledgeProvider(
            new TemplateVariableDeclarations(
                $templateFactory,
                $this->createMock(PluginBypassFlagInterface::class),
                $this->createMock(LoggerInterface::class)
            ),
            $this->referenceParser,
            $context
        );
    }

    /**
     * The canonical references of everything the provider lists
     *
     * @return array<int, string>
     */
    private function listedReferences(): array
    {
        return array_map(
            static fn (VariableKnowledgeInterface $entry): string => $entry->getReference()->toCanonicalString(),
            $this->provider->listAll(self::STORE_ID)
        );
    }
}
