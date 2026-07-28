<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\CustomVariableIndexInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVariableDeclarationsInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Hryvinskyi\EmailTemplateEditor\Model\VariableChooserProvider;
use Magento\Email\Model\Template\Config as EmailConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Magento\Variable\Model\Source\Variables as ConfigVariables;
use Psr\Log\LoggerInterface;

class VariableChooserProviderTest extends TestCase
{
    private const TEMPLATE_ID = 'sales_email_order_template';

    private ConfigVariables&MockObject $configVariables;
    private CustomVariableIndexInterface&MockObject $customVariableIndex;
    private TemplateVariableDeclarationsInterface&MockObject $templateVariableDeclarations;
    private LoggerInterface&MockObject $logger;
    private DirectiveReferenceParser $referenceParser;
    private VariableChooserProvider $provider;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('toOptionArray')->willReturn([]);

        $this->customVariableIndex = $this->createMock(CustomVariableIndexInterface::class);
        $this->customVariableIndex->method('getAll')->willReturn([]);

        $this->templateVariableDeclarations = $this->createMock(TemplateVariableDeclarationsInterface::class);
        $this->templateVariableDeclarations->method('getDeclarations')->willReturn([]);

        $this->logger = $this->createMock(LoggerInterface::class);

        // The real parser, not a stand-in. The claim made throughout is that a chooser row and the
        // identical directive in the content arrive at one reference, and a stubbed parser would
        // prove only that this class calls something.
        $this->referenceParser = new DirectiveReferenceParser();

        $this->rebuildProvider();
    }

    public function testTheTemplateIsAskedForItsOwnDeclarations(): void
    {
        $this->templateVariableDeclarations = $this->createMock(TemplateVariableDeclarationsInterface::class);
        $this->templateVariableDeclarations->expects(self::once())
            ->method('getDeclarations')
            ->with(self::TEMPLATE_ID)
            ->willReturn([]);

        $this->rebuildProvider();

        $this->provider->getVariableGroups(self::TEMPLATE_ID);
    }

    /**
     * A declaration comes back without its braces, whichever way the template wrote it. Putting
     * them back on is what turns it into something an author can insert as it stands.
     *
     * @return void
     */
    public function testDeclaredTemplateVariablesAreOfferedAsDirectives(): void
    {
        $this->templateVariableDeclarations = $this->createMock(TemplateVariableDeclarationsInterface::class);
        $this->templateVariableDeclarations->method('getDeclarations')->willReturn([
            'var order.increment_id' => 'Order Id',
            'store url=\'\'' => 'Store Url',
        ]);

        $this->rebuildProvider();

        self::assertSame(
            [
                [
                    'code' => 'template',
                    'label' => 'Template Variables',
                    'variables' => [
                        [
                            'label' => 'Order Id',
                            'value' => '{{var order.increment_id}}',
                            'reference' => 'var:order.increment_id',
                        ],
                        [
                            'label' => 'Store Url',
                            'value' => '{{store url=\'\'}}',
                            'reference' => '',
                        ],
                    ],
                ],
            ],
            $this->provider->getVariableGroups(self::TEMPLATE_ID)
        );
    }

    public function testATemplateWithoutDeclaredVariablesYieldsNoGroup(): void
    {
        self::assertSame([], $this->provider->getVariableGroups(self::TEMPLATE_ID));
    }

    /**
     * The index behind this is read once per request and shared with everything else that turns a
     * variable code into a name, so opening the chooser does not repeat a load somebody else has
     * already paid for.
     *
     * @return void
     */
    public function testCustomVariablesAreOfferedAsDirectivesCarryingTheirCode(): void
    {
        $this->customVariableIndex = $this->createMock(CustomVariableIndexInterface::class);
        $this->customVariableIndex->method('getAll')->willReturn([
            'support_hours' => ['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'],
            'returns_policy' => ['id' => 8, 'code' => 'returns_policy', 'name' => 'Returns policy'],
        ]);

        $this->rebuildProvider();

        self::assertSame(
            [
                [
                    'code' => 'custom',
                    'label' => 'Custom Variables',
                    'variables' => [
                        [
                            'label' => 'Support hours',
                            'value' => '{{customVar code=support_hours}}',
                            'reference' => 'customVar:support_hours',
                        ],
                        [
                            'label' => 'Returns policy',
                            'value' => '{{customVar code=returns_policy}}',
                            'reference' => 'customVar:returns_policy',
                        ],
                    ],
                ],
            ],
            $this->provider->getVariableGroups(self::TEMPLATE_ID)
        );
    }

    public function testSystemVariablesThatCannotBeReadDoNotBringTheChooserDown(): void
    {
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('toOptionArray')
            ->willThrowException(new \RuntimeException('the configuration source is broken'));
        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('the configuration source is broken'));

        $this->rebuildProvider();

        self::assertSame([], $this->provider->getVariableGroups(self::TEMPLATE_ID));
    }

    public function testEveryGroupIsOfferedTogether(): void
    {
        $this->givenEveryGroupHasSomethingInIt();

        self::assertSame(
            ['system', 'custom', 'template'],
            array_column($this->provider->getVariableGroups(self::TEMPLATE_ID), 'code')
        );
    }

    /**
     * A group is keyed by something that survives translation and read by something that does not.
     *
     * The panel remembers which groups an author collapsed, and it remembers them by the code. Were
     * the label the key, every collapsed group would be forgotten the moment the admin locale
     * changed, and two groups translated alike would become one.
     *
     * @return void
     */
    public function testAGroupCarriesACodeAndALabelAsTwoSeparateThings(): void
    {
        $this->givenEveryGroupHasSomethingInIt();

        $groups = $this->provider->getVariableGroups(self::TEMPLATE_ID);

        self::assertSame(
            ['System Variables', 'Custom Variables', 'Template Variables'],
            array_column($groups, 'label')
        );

        foreach ($groups as $group) {
            self::assertNotSame($group['code'], $group['label'], 'the code is not the label');
        }
    }

    /**
     * Magento writes a configuration directive with its path quoted, and the reference a row carries
     * has to be the one that quoted directive points at.
     *
     * @return void
     */
    public function testAConfigRowCarriesTheReferenceItsQuotedDirectivePointsAt(): void
    {
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('toOptionArray')->willReturn([
            ['value' => [
                ['label' => 'Store Name', 'value' => '{{config path="general/store_information/name"}}'],
            ]],
        ]);

        $this->rebuildProvider();

        $row = $this->provider->getVariableGroups(self::TEMPLATE_ID)[0]['variables'][0];

        self::assertSame('config:general/store_information/name', $row['reference']);
        self::assertSame(
            $this->referenceParser->create('config', 'general/store_information/name')->toCanonicalString(),
            $row['reference'],
            'the quoted directive and the bare path name one reference'
        );
        self::assertSame(
            $row['reference'],
            $this->referenceParser->parse($row['reference'])->toCanonicalString(),
            'and what the row carries is a reference that can be sent back and understood'
        );
    }

    /**
     * This module writes a custom variable directive with its code bare, and that spelling has to
     * reach the same reference the quoted one would.
     *
     * The two sources merged into this panel disagree about quoting and always have. Quoting is not
     * part of what a directive points at, so both spellings are one reference - otherwise the panel
     * offers rows whose explanations nobody can look up.
     *
     * @return void
     */
    public function testACustomVariableRowCarriesTheReferenceItsUnquotedDirectivePointsAt(): void
    {
        $this->customVariableIndex = $this->createMock(CustomVariableIndexInterface::class);
        $this->customVariableIndex->method('getAll')->willReturn([
            'support_hours' => ['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'],
        ]);

        $this->rebuildProvider();

        $row = $this->provider->getVariableGroups(self::TEMPLATE_ID)[0]['variables'][0];

        self::assertSame('customVar:support_hours', $row['reference']);
        self::assertSame(
            $this->referenceParser->create('customVar', '"support_hours"')->toCanonicalString(),
            $row['reference'],
            'the bare code and the quoted one name one reference'
        );
        self::assertSame(
            $row['reference'],
            $this->referenceParser->parse($row['reference'])->toCanonicalString(),
            'and what the row carries is a reference that can be sent back and understood'
        );
    }

    /**
     * A template declares its variables with the formatting they are normally written with, and the
     * formatting is not part of what they point at.
     *
     * The inserted text keeps it, because that is what the template family meant an author to write.
     * The reference does not, because the renderer stops reading the expression at the first pipe -
     * so the same variable typed into the content resolves to the reference without it.
     *
     * @return void
     */
    public function testTheFormattingOfADeclarationIsNotPartOfWhatItPointsAt(): void
    {
        $this->templateVariableDeclarations = $this->createMock(TemplateVariableDeclarationsInterface::class);
        $this->templateVariableDeclarations->method('getDeclarations')->willReturn([
            'var formattedShippingAddress|raw' => 'Shipping Address',
            'var comment|escape|nl2br' => 'Comment',
        ]);

        $this->rebuildProvider();

        self::assertSame(
            [
                [
                    'label' => 'Shipping Address',
                    'value' => '{{var formattedShippingAddress|raw}}',
                    'reference' => 'var:formattedShippingAddress',
                ],
                [
                    'label' => 'Comment',
                    'value' => '{{var comment|escape|nl2br}}',
                    'reference' => 'var:comment',
                ],
            ],
            $this->provider->getVariableGroups(self::TEMPLATE_ID)[0]['variables']
        );
    }

    /**
     * A row whose reference cannot be named without guessing is offered without one.
     *
     * Which parameter identifies a directive of a given kind has been settled in the one place that
     * reads directives out of a document. Deciding it a second time here is how two spellings of one
     * grammar drift apart, and a row carrying a reference nothing else produces would offer an
     * explanation of something the author never asked about.
     *
     * @return void
     */
    public function testARowWhoseDirectiveCannotBeNamedIsOfferedWithoutAReference(): void
    {
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('toOptionArray')->willReturn([
            ['value' => [['label' => 'Something else', 'value' => '{{var something}}']]],
        ]);
        $this->templateVariableDeclarations = $this->createMock(TemplateVariableDeclarationsInterface::class);
        $this->templateVariableDeclarations->method('getDeclarations')->willReturn([
            'layout handle=\'sales_email_order_items\'' => 'Order Items',
            'var' => 'Nothing at all',
        ]);

        $this->rebuildProvider();

        $groups = $this->provider->getVariableGroups(self::TEMPLATE_ID);

        self::assertSame('', $groups[0]['variables'][0]['reference'], 'not a configuration option');
        self::assertSame('', $groups[1]['variables'][0]['reference'], 'a kind named by a parameter');
        self::assertSame('', $groups[1]['variables'][1]['reference'], 'a declaration naming nothing');
    }

    /**
     * Give every group something to offer
     *
     * @return void
     */
    private function givenEveryGroupHasSomethingInIt(): void
    {
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('toOptionArray')->willReturn([
            ['value' => [['label' => 'Store Name', 'value' => '{{config path="general/store_information/name"}}']]],
        ]);
        $this->customVariableIndex = $this->createMock(CustomVariableIndexInterface::class);
        $this->customVariableIndex->method('getAll')->willReturn([
            'support_hours' => ['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'],
        ]);
        $this->templateVariableDeclarations = $this->createMock(TemplateVariableDeclarationsInterface::class);
        $this->templateVariableDeclarations->method('getDeclarations')
            ->willReturn(['var order.increment_id' => 'Order Id']);

        $this->rebuildProvider();
    }

    /**
     * Rebuild the provider over whichever collaborators the test has replaced
     *
     * @return void
     */
    private function rebuildProvider(): void
    {
        $this->provider = new VariableChooserProvider(
            $this->configVariables,
            $this->customVariableIndex,
            $this->createMock(EmailConfig::class),
            $this->templateVariableDeclarations,
            $this->referenceParser,
            $this->logger
        );
    }
}
