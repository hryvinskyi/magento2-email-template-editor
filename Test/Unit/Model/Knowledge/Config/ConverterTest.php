<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Config;

use DOMDocument;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Config\Converter;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConverterTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    private Converter $converter;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->converter = new Converter(new DirectiveReferenceParser(), $this->logger);
    }

    public function testAnEntryIsKeyedByItsCanonicalReference(): void
    {
        $entries = $this->convert(
            <<<'XML'
            <variable kind="config" expression="general/store_information/name" outputKind="text">
                <title>Store name</title>
                <summary>The name of the store.</summary>
                <origin kind="config" locator="general/store_information/name">Read from the configuration.</origin>
            </variable>
            XML
        );

        self::assertSame(['config:general/store_information/name'], array_keys($entries));
        self::assertSame('config', $entries['config:general/store_information/name']['kind']);
        self::assertSame(
            'general/store_information/name',
            $entries['config:general/store_information/name']['expression']
        );
    }

    /**
     * The key is produced by the parser the inspector uses, so an entry written with quotes and a
     * directive written without them land on one key. Without that, a knowledge entry and the
     * directive it describes would be two different things to the base.
     *
     * @return void
     */
    public function testTheKeyIsNormalisedHoweverTheExpressionWasSpelled(): void
    {
        $entries = $this->convert(
            <<<'XML'
            <variable kind="customVar" expression="&quot;my_code&quot;">
                <title>My variable</title>
                <summary>A custom variable.</summary>
                <origin kind="custom_variable" locator="my_code">Held by a custom variable.</origin>
            </variable>
            XML
        );

        self::assertSame(['customVar:my_code'], array_keys($entries));
    }

    public function testAnEntryThatNamesNoOutputKindIsReadAsPlainText(): void
    {
        $entries = $this->convert(
            <<<'XML'
            <variable kind="var" expression="order.increment_id">
                <title>Order number</title>
                <summary>The number the customer sees.</summary>
                <origin kind="template_var" locator="order">Assigned by the sending code.</origin>
            </variable>
            XML
        );

        self::assertSame(
            VariableKnowledgeInterface::OUTPUT_TEXT,
            $entries['var:order.increment_id']['outputKind']
        );
    }

    public function testTheOriginIsReadWholeFromItsAttributesAndItsText(): void
    {
        $entries = $this->convert(
            <<<'XML'
            <variable kind="var" expression="order.increment_id">
                <title>Order number</title>
                <summary>The number the customer sees.</summary>
                <origin kind="template_var" locator="order">Assigned by the sender.</origin>
            </variable>
            XML
        );

        self::assertSame(
            [
                'kind' => OriginInterface::KIND_TEMPLATE_VAR,
                'locator' => 'order',
                'explanation' => 'Assigned by the sender.',
            ],
            $entries['var:order.increment_id']['origin']
        );
    }

    /**
     * Prose is wrapped and indented in the file. What an administrator reads has to be a sentence,
     * not the file's layout.
     *
     * @return void
     */
    public function testProseWrittenOverSeveralLinesIsReadAsOneSentence(): void
    {
        $entries = $this->convert(
            <<<'XML'
            <variable kind="var" expression="order.increment_id">
                <title>Order number</title>
                <summary>
                    The number the customer sees,
                    written over two lines.
                </summary>
                <origin kind="template_var" locator="order">Assigned by the sender.</origin>
            </variable>
            XML
        );

        self::assertSame(
            'The number the customer sees, written over two lines.',
            $entries['var:order.increment_id']['summary']
        );
    }

    public function testALinkAffordanceKeepsItsRouteParametersAndFragment(): void
    {
        $affordance = $this->convertAffordance(
            <<<'XML'
            <affordance kind="link" label="Open Store Information" route="adminhtml/system_config/edit"
                        scopeAware="true">
                <param name="section">general</param>
                <fragment>general_store_information-link</fragment>
            </affordance>
            XML
        );

        self::assertSame(EditAffordanceInterface::KIND_LINK, $affordance['kind']);
        self::assertSame('Open Store Information', $affordance['label']);
        self::assertSame('adminhtml/system_config/edit', $affordance['route']);
        self::assertSame(['section' => 'general'], $affordance['params']);
        self::assertSame('general_store_information-link', $affordance['fragment']);
        self::assertTrue($affordance['scopeAware']);
    }

    public function testALinkThatSaysNothingAboutScopeIsNotScopeAware(): void
    {
        $affordance = $this->convertAffordance(
            <<<'XML'
            <affordance kind="link" label="Open Custom Variables" route="adminhtml/system_variable"/>
            XML
        );

        self::assertFalse($affordance['scopeAware']);
        self::assertSame([], $affordance['params']);
        self::assertSame('', $affordance['fragment']);
    }

    public function testAnInlineAffordanceKeepsItsInputTypeAndItsChoices(): void
    {
        $affordance = $this->convertAffordance(
            <<<'XML'
            <affordance kind="inline" label="Sender name" editorType="select">
                <option value="html">Escape as HTML</option>
                <option value="url">Escape for a URL</option>
            </affordance>
            XML
        );

        self::assertSame(EditAffordanceInterface::KIND_INLINE, $affordance['kind']);
        self::assertSame(EditAffordanceInterface::EDITOR_SELECT, $affordance['editorType']);
        self::assertSame(
            [
                ['value' => 'html', 'label' => 'Escape as HTML'],
                ['value' => 'url', 'label' => 'Escape for a URL'],
            ],
            $affordance['options']
        );
    }

    public function testAnInstructionAffordanceKeepsItsStepsInOrder(): void
    {
        $affordance = $this->convertAffordance(
            <<<'XML'
            <affordance kind="instruction" label="How to change this">
                <step>Open the configuration.</step>
                <step>Change the value.</step>
                <step>Save and flush the cache.</step>
            </affordance>
            XML
        );

        self::assertSame(EditAffordanceInterface::KIND_INSTRUCTION, $affordance['kind']);
        self::assertSame(
            ['Open the configuration.', 'Change the value.', 'Save and flush the cache.'],
            $affordance['steps']
        );
    }

    public function testAnAffordanceOfferingNothingStillCarriesItsLabel(): void
    {
        $affordance = $this->convertAffordance(
            <<<'XML'
            <affordance kind="none" label="This value is fixed by the code that sends the message."/>
            XML
        );

        self::assertSame(EditAffordanceInterface::KIND_NONE, $affordance['kind']);
        self::assertSame('This value is fixed by the code that sends the message.', $affordance['label']);
    }

    public function testEveryCaveatSurvivesInOrder(): void
    {
        $entries = $this->convert(
            <<<'XML'
            <variable kind="var" expression="order.increment_id">
                <title>Order number</title>
                <summary>The number the customer sees.</summary>
                <origin kind="template_var" locator="order">Assigned by the sender.</origin>
                <caveat id="first">It is empty outside the order templates.</caveat>
                <caveat id="second">It is escaped unless the chain says otherwise.</caveat>
            </variable>
            XML
        );

        self::assertSame(
            [
                'It is empty outside the order templates.',
                'It is escaped unless the chain says otherwise.',
            ],
            $entries['var:order.increment_id']['caveats']
        );
    }

    /**
     * A contribution that names something the base could never look up has no key to be filed under,
     * so it is dropped whole - but a third party's mistake must not be invisible either.
     *
     * @dataProvider unusableReferenceProvider
     * @param string $kind Kind as written in the contribution
     * @param string $expression Expression as written in the contribution
     * @return void
     */
    public function testAnEntryThatCannotBeLookedUpIsSkippedAndReported(string $kind, string $expression): void
    {
        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains($kind));

        $entries = $this->convert(
            sprintf(
                '<variable kind="%s" expression="%s">'
                . '<title>Something</title><summary>Something.</summary>'
                . '<origin kind="computed">Worked out in PHP.</origin>'
                . '</variable>',
                $kind,
                htmlspecialchars($expression, ENT_XML1 | ENT_QUOTES)
            )
        );

        self::assertSame([], $entries);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unusableReferenceProvider(): array
    {
        return [
            'a kind nothing publishes' => ['banana', 'whatever'],
            'an expression carrying braces' => ['var', '{{var order.increment_id}}'],
        ];
    }

    /**
     * A description without an edit route is still worth reading; a control that does nothing when it
     * is pressed is not. So a broken affordance costs the entry its edit route and nothing else.
     *
     * @dataProvider incompleteAffordanceProvider
     * @param string $affordance The affordance element as contributed
     * @return void
     */
    public function testAnAffordanceMissingWhatItsKindNeedsIsDroppedAndTheEntryKept(string $affordance): void
    {
        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('var:order.increment_id'));

        $entries = $this->convert(
            <<<XML
            <variable kind="var" expression="order.increment_id">
                <title>Order number</title>
                <summary>The number the customer sees.</summary>
                <origin kind="template_var" locator="order">Assigned by the sender.</origin>
                {$affordance}
            </variable>
            XML
        );

        self::assertArrayHasKey('var:order.increment_id', $entries);
        self::assertNull($entries['var:order.increment_id']['affordance']);
        self::assertSame('Order number', $entries['var:order.increment_id']['title']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function incompleteAffordanceProvider(): array
    {
        return [
            'a link with no route' => ['<affordance kind="link" label="Open it"/>'],
            'instructions with no step' => ['<affordance kind="instruction" label="How to change this"/>'],
            'an editor with no input type' => ['<affordance kind="inline" label="The value"/>'],
            'a choice with nothing to choose from' => [
                '<affordance kind="inline" label="The value" editorType="select"/>',
            ],
            'nothing written on it' => ['<affordance kind="none" label=" "/>'],
            'a kind nothing publishes' => ['<affordance kind="banana" label="Do something"/>'],
        ];
    }

    /**
     * The schema refuses the same pair twice in one file and the merge folds two files' entries for
     * one reference together, so this is the answer to a case those two have already narrowed. It is
     * pinned all the same, because "undefined" is not an answer anybody can act on.
     *
     * @return void
     */
    public function testTheLastEntryForOneReferenceIsTheOneThatSurvives(): void
    {
        $entries = $this->convert(
            <<<'XML'
            <variable kind="var" expression="order.increment_id">
                <title>Written first</title>
                <summary>The first description.</summary>
                <origin kind="template_var" locator="order">Assigned by the sender.</origin>
            </variable>
            <variable kind="var" expression="order.increment_id">
                <title>Written last</title>
                <summary>The last description.</summary>
                <origin kind="template_var" locator="order">Assigned by the sender.</origin>
            </variable>
            XML
        );

        self::assertCount(1, $entries);
        self::assertSame('Written last', $entries['var:order.increment_id']['title']);
    }

    public function testADocumentWithNoVariablesConvertsToNothing(): void
    {
        self::assertSame([], $this->convert(''));
    }

    /**
     * A step inside an affordance is not a caveat of the variable around it. Reading descendants
     * rather than direct children is the classic way that stops being true.
     *
     * @return void
     */
    public function testNestedElementsAreNotMistakenForTheVariablesOwnChildren(): void
    {
        $entries = $this->convert(
            <<<'XML'
            <variable kind="var" expression="order.increment_id">
                <title>Order number</title>
                <summary>The number the customer sees.</summary>
                <origin kind="template_var" locator="order">Assigned by the sender.</origin>
                <affordance kind="instruction" label="How to change this">
                    <step>Change the order.</step>
                </affordance>
                <caveat>It is empty outside the order templates.</caveat>
            </variable>
            XML
        );

        self::assertSame(
            ['It is empty outside the order templates.'],
            $entries['var:order.increment_id']['caveats']
        );
        self::assertSame(['Change the order.'], $entries['var:order.increment_id']['affordance']['steps']);
    }

    /**
     * @param string $affordance The affordance element as contributed
     * @return array<string, mixed>
     */
    private function convertAffordance(string $affordance): array
    {
        $entries = $this->convert(
            <<<XML
            <variable kind="var" expression="order.increment_id">
                <title>Order number</title>
                <summary>The number the customer sees.</summary>
                <origin kind="template_var" locator="order">Assigned by the sender.</origin>
                {$affordance}
            </variable>
            XML
        );

        $affordance = $entries['var:order.increment_id']['affordance'];
        self::assertIsArray($affordance);

        return $affordance;
    }

    /**
     * @param string $variables The variable elements, without the document element around them
     * @return array<string, array<string, mixed>>
     */
    private function convert(string $variables): array
    {
        $document = new DOMDocument();
        $document->loadXML('<variables>' . $variables . '</variables>');

        return $this->converter->convert($document);
    }
}
