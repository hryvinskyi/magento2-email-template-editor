<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\XmlKnowledgeProvider;
use Magento\Framework\Config\DataInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class XmlKnowledgeProviderTest extends TestCase
{
    private const DEFAULT_STORE_ID = 0;
    private const OTHER_STORE_ID = 3;

    private UrlInterface&MockObject $url;

    protected function setUp(): void
    {
        $this->url = $this->createMock(UrlInterface::class);
    }

    public function testAReferenceNobodyWroteDownIsLeftToTheProvidersBehindThisOne(): void
    {
        $provider = $this->provider([]);

        self::assertNull(
            $provider->describe(new DirectiveReference('var', 'order.increment_id'), self::DEFAULT_STORE_ID)
        );
    }

    public function testAWrittenEntryIsAnsweredWithWhatWasWritten(): void
    {
        $provider = $this->provider([
            'var:order.increment_id' => $this->definition([
                'title' => 'Order number',
                'summary' => 'The number the customer sees.',
                'outputKind' => VariableKnowledgeInterface::OUTPUT_TEXT,
                'caveats' => ['It is empty outside the order templates.'],
            ]),
        ]);

        $entry = $provider->describe(
            new DirectiveReference('var', 'order.increment_id'),
            self::DEFAULT_STORE_ID
        );

        self::assertNotNull($entry);
        self::assertTrue($entry->isKnown());
        self::assertSame('Order number', $entry->getTitle());
        self::assertSame('The number the customer sees.', $entry->getSummary());
        self::assertSame(VariableKnowledgeInterface::OUTPUT_TEXT, $entry->getOutputKind());
        self::assertSame(['It is empty outside the order templates.'], $entry->getCaveats());
        self::assertSame(OriginInterface::KIND_TEMPLATE_VAR, $entry->getOrigin()->getKind());
        self::assertSame('order', $entry->getOrigin()->getLocator());
        self::assertSame('Assigned by the sender.', $entry->getOrigin()->getExplanation());
    }

    /**
     * The reference that was asked about is the one the entry carries, so what the entry reports
     * about the lookup - that its key was shortened, above all - stays true of the lookup that
     * actually happened.
     *
     * @return void
     */
    public function testTheEntryCarriesTheReferenceItWasAskedAbout(): void
    {
        $reference = new DirectiveReference('var', 'order.increment_id', true);
        $provider = $this->provider(['var:order.increment_id' => $this->definition()]);

        $entry = $provider->describe($reference, self::DEFAULT_STORE_ID);

        self::assertNotNull($entry);
        self::assertSame($reference, $entry->getReference());
    }

    /**
     * A link is written as a route and its parameters, never as a finished URL: an admin URL carries
     * a key belonging to the session it was built in, so a stored one is dead before anybody follows
     * it.
     *
     * @return void
     */
    public function testALinkIsBuiltFromItsRouteItsParametersAndItsFragment(): void
    {
        $this->url->expects(self::once())
            ->method('getUrl')
            ->with(
                'adminhtml/system_config/edit',
                ['section' => 'general', '_fragment' => 'general_store_information-link']
            )
            ->willReturn('https://example.test/admin/system_config/edit/section/general/');

        $provider = $this->provider([
            'config:general/store_information/name' => $this->definition([
                'affordance' => $this->linkAffordance(),
            ]),
        ]);

        $affordance = $provider->describe(
            new DirectiveReference('config', 'general/store_information/name'),
            self::DEFAULT_STORE_ID
        )?->getAffordance();

        self::assertNotNull($affordance);
        self::assertSame(EditAffordanceInterface::KIND_LINK, $affordance->getKind());
        self::assertSame('Open Store Information', $affordance->getLabel());
        self::assertSame('https://example.test/admin/system_config/edit/section/general/', $affordance->getUrl());
    }

    /**
     * A page that shows a different value per store view has to open on the store view the message is
     * sent in; landing on the default one would show a value the message never uses.
     *
     * @return void
     */
    public function testAScopeAwareLinkCarriesTheStoreViewTheEditorIsWorkingIn(): void
    {
        $this->url->expects(self::once())
            ->method('getUrl')
            ->with(
                'adminhtml/system_config/edit',
                [
                    'section' => 'general',
                    'store' => (string)self::OTHER_STORE_ID,
                    '_fragment' => 'general_store_information-link',
                ]
            )
            ->willReturn('https://example.test/admin/system_config/edit/section/general/store/3/');

        $provider = $this->provider([
            'config:general/store_information/name' => $this->definition([
                'affordance' => $this->linkAffordance(),
            ]),
        ]);

        $provider->describe(
            new DirectiveReference('config', 'general/store_information/name'),
            self::OTHER_STORE_ID
        );
    }

    /**
     * "All Store Views" is the scope an admin config page opens on without being told to, so naming
     * it would add a parameter that changes nothing.
     *
     * @return void
     */
    public function testAScopeAwareLinkSaysNothingAboutScopeForAllStoreViews(): void
    {
        $this->url->expects(self::once())
            ->method('getUrl')
            ->with(
                'adminhtml/system_config/edit',
                ['section' => 'general', '_fragment' => 'general_store_information-link']
            )
            ->willReturn('https://example.test/admin/system_config/edit/section/general/');

        $provider = $this->provider([
            'config:general/store_information/name' => $this->definition([
                'affordance' => $this->linkAffordance(),
            ]),
        ]);

        $provider->describe(
            new DirectiveReference('config', 'general/store_information/name'),
            self::DEFAULT_STORE_ID
        );
    }

    public function testALinkThatIsNotScopeAwareIsTheSameLinkInEveryStoreView(): void
    {
        $this->url->expects(self::once())
            ->method('getUrl')
            ->with('adminhtml/system_variable', [])
            ->willReturn('https://example.test/admin/system_variable/');

        $provider = $this->provider([
            'customVar:my_code' => $this->definition([
                'affordance' => [
                    'kind' => EditAffordanceInterface::KIND_LINK,
                    'label' => 'Open Custom Variables',
                    'route' => 'adminhtml/system_variable',
                    'params' => [],
                    'fragment' => '',
                    'scopeAware' => false,
                    'steps' => [],
                    'editorType' => '',
                    'options' => [],
                ],
            ]),
        ]);

        $provider->describe(new DirectiveReference('customVar', 'my_code'), self::OTHER_STORE_ID);
    }

    public function testAnAffordanceThatOffersNothingHasNoUrlAndNoSteps(): void
    {
        $this->url->expects(self::never())->method('getUrl');

        $provider = $this->provider([
            'var:store.getId()' => $this->definition([
                'affordance' => [
                    'kind' => EditAffordanceInterface::KIND_NONE,
                    'label' => 'A store view keeps the id it was given.',
                    'route' => '',
                    'params' => [],
                    'fragment' => '',
                    'scopeAware' => false,
                    'steps' => [],
                    'editorType' => '',
                    'options' => [],
                ],
            ]),
        ]);

        $affordance = $provider->describe(
            new DirectiveReference('var', 'store.getId()'),
            self::DEFAULT_STORE_ID
        )?->getAffordance();

        self::assertNotNull($affordance);
        self::assertSame(EditAffordanceInterface::KIND_NONE, $affordance->getKind());
        self::assertNull($affordance->getUrl());
        self::assertSame([], $affordance->getSteps());
        self::assertNull($affordance->getEditorType());
    }

    public function testInstructionsBecomeAnAffordanceCarryingTheirSteps(): void
    {
        $provider = $this->provider([
            'var:order.increment_id' => $this->definition([
                'affordance' => [
                    'kind' => EditAffordanceInterface::KIND_INSTRUCTION,
                    'label' => 'How to change this',
                    'route' => '',
                    'params' => [],
                    'fragment' => '',
                    'scopeAware' => false,
                    'steps' => ['Open the order.', 'Change it there.'],
                    'editorType' => '',
                    'options' => [],
                ],
            ]),
        ]);

        $affordance = $provider->describe(
            new DirectiveReference('var', 'order.increment_id'),
            self::DEFAULT_STORE_ID
        )?->getAffordance();

        self::assertNotNull($affordance);
        self::assertSame(['Open the order.', 'Change it there.'], $affordance->getSteps());
    }

    /**
     * Editing one field from a popover cannot show the fields beside it, the scopes it is not being
     * written at, or what the value used to be. An editor that names a route offers the page as well.
     *
     * @return void
     */
    public function testAnEditorMayAlsoOfferThePageThatOwnsTheValue(): void
    {
        $this->url->expects(self::once())
            ->method('getUrl')
            ->with('adminhtml/system_config/edit', ['section' => 'trans_email', 'store' => '3'])
            ->willReturn('https://example.test/admin/system_config/edit/section/trans_email/store/3/');

        $provider = $this->provider([
            'config:trans_email/ident_general/email' => $this->definition([
                'affordance' => $this->inlineAffordance([
                    'route' => 'adminhtml/system_config/edit',
                    'params' => ['section' => 'trans_email'],
                    'scopeAware' => true,
                ]),
            ]),
        ]);

        $affordance = $provider->describe(
            new DirectiveReference('config', 'trans_email/ident_general/email'),
            self::OTHER_STORE_ID
        )?->getAffordance();

        self::assertNotNull($affordance);
        self::assertSame(EditAffordanceInterface::KIND_INLINE, $affordance->getKind());
        self::assertSame(EditAffordanceInterface::EDITOR_TEXT, $affordance->getEditorType());
        self::assertSame(
            'https://example.test/admin/system_config/edit/section/trans_email/store/3/',
            $affordance->getUrl()
        );
    }

    public function testAnEditorThatNamesNoRouteOffersNoPage(): void
    {
        $this->url->expects(self::never())->method('getUrl');

        $provider = $this->provider([
            'config:trans_email/ident_general/email' => $this->definition([
                'affordance' => $this->inlineAffordance(),
            ]),
        ]);

        $affordance = $provider->describe(
            new DirectiveReference('config', 'trans_email/ident_general/email'),
            self::DEFAULT_STORE_ID
        )?->getAffordance();

        self::assertNotNull($affordance);
        self::assertNull($affordance->getUrl());
    }

    /**
     * Writability is claimed only where an editor was offered, and even then it is a hint: the server
     * decides every write again from the reference alone.
     *
     * @return void
     */
    public function testOnlyAnEntryOfferingAnEditorReportsItsValueAsWritable(): void
    {
        $provider = $this->provider([
            'config:trans_email/ident_general/email' => $this->definition([
                'affordance' => $this->inlineAffordance(),
            ]),
            'config:general/store_information/name' => $this->definition([
                'affordance' => $this->linkAffordance(),
            ]),
            'var:order.increment_id' => $this->definition(),
        ]);

        $this->url->method('getUrl')->willReturn('https://example.test/admin/');

        self::assertTrue(
            $provider->describe(
                new DirectiveReference('config', 'trans_email/ident_general/email'),
                self::DEFAULT_STORE_ID
            )?->isValueWritable()
        );
        self::assertFalse(
            $provider->describe(
                new DirectiveReference('config', 'general/store_information/name'),
                self::DEFAULT_STORE_ID
            )?->isValueWritable()
        );
        self::assertFalse(
            $provider->describe(
                new DirectiveReference('var', 'order.increment_id'),
                self::DEFAULT_STORE_ID
            )?->isValueWritable()
        );
    }

    /**
     * An absent modifier chain is not the absence of formatting. An entry has to say which modifier a
     * directive of its kind gets anyway, whichever part of the base built the entry, or a chain
     * editor would offer to remove protection nobody knew was there.
     *
     * @return void
     */
    public function testAnEntryNamesTheModifierThatAppliesWhenThereIsNoChain(): void
    {
        $provider = $this->provider(
            [
                'var:order.increment_id' => $this->definition(),
                'config:general/store_information/name' => $this->definition(),
            ],
            ['var' => 'escape']
        );

        self::assertSame(
            'escape',
            $provider->describe(
                new DirectiveReference('var', 'order.increment_id'),
                self::DEFAULT_STORE_ID
            )?->getDefaultModifier()
        );
        self::assertNull(
            $provider->describe(
                new DirectiveReference('config', 'general/store_information/name'),
                self::DEFAULT_STORE_ID
            )?->getDefaultModifier()
        );
    }

    public function testEverythingWrittenDownIsListed(): void
    {
        $provider = $this->provider([
            'var:order.increment_id' => $this->definition(['kind' => 'var', 'expression' => 'order.increment_id']),
            'customVar:my_code' => $this->definition(['kind' => 'customVar', 'expression' => 'my_code']),
        ]);

        $listed = [];

        foreach ($provider->listAll(self::DEFAULT_STORE_ID) as $entry) {
            $listed[] = $entry->getReference()->toCanonicalString();
        }

        self::assertSame(['var:order.increment_id', 'customVar:my_code'], $listed);
    }

    public function testNothingWrittenDownIsAnEmptyList(): void
    {
        self::assertSame([], $this->provider([])->listAll(self::DEFAULT_STORE_ID));
    }

    /**
     * @param array<string, array<string, mixed>> $definitions Entries as the converter produces them
     * @param array<string, string> $defaultModifiers Directive kind to its no-chain modifier
     * @return XmlKnowledgeProvider
     */
    private function provider(array $definitions, array $defaultModifiers = []): XmlKnowledgeProvider
    {
        $config = $this->createMock(DataInterface::class);
        $config->method('get')->willReturn($definitions);

        return new XmlKnowledgeProvider($config, $this->url, $defaultModifiers);
    }

    /**
     * @param array<string, mixed> $overrides Parts of the definition this test cares about
     * @return array<string, mixed>
     */
    private function definition(array $overrides = []): array
    {
        return $overrides + [
            'reference' => 'var:order.increment_id',
            'kind' => 'var',
            'expression' => 'order.increment_id',
            'outputKind' => VariableKnowledgeInterface::OUTPUT_TEXT,
            'title' => 'Order number',
            'summary' => 'The number the customer sees.',
            'origin' => [
                'kind' => OriginInterface::KIND_TEMPLATE_VAR,
                'locator' => 'order',
                'explanation' => 'Assigned by the sender.',
            ],
            'affordance' => null,
            'caveats' => [],
        ];
    }

    /**
     * @param array<string, mixed> $overrides Parts of the affordance this test cares about
     * @return array<string, mixed>
     */
    private function inlineAffordance(array $overrides = []): array
    {
        return $overrides + [
            'kind' => EditAffordanceInterface::KIND_INLINE,
            'label' => 'Sender address',
            'route' => '',
            'params' => [],
            'fragment' => '',
            'scopeAware' => false,
            'steps' => [],
            'editorType' => EditAffordanceInterface::EDITOR_TEXT,
            'options' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkAffordance(): array
    {
        return [
            'kind' => EditAffordanceInterface::KIND_LINK,
            'label' => 'Open Store Information',
            'route' => 'adminhtml/system_config/edit',
            'params' => ['section' => 'general'],
            'fragment' => 'general_store_information-link',
            'scopeAware' => true,
            'steps' => [],
            'editorType' => '',
            'options' => [],
        ];
    }
}
