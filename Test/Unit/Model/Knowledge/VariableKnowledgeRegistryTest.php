<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\EditAffordanceResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Affordance\InstructionAffordanceResolver;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\VariableKnowledgeRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class VariableKnowledgeRegistryTest extends TestCase
{
    private const STORE_ID = 1;

    /**
     * The pool order is precedence, so an entry somebody wrote by hand has to beat a derived one.
     *
     * @return void
     */
    public function testTheFirstProviderThatAnswersWins(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');

        $registry = new VariableKnowledgeRegistry(
            [
                $this->providerAnswering($reference, $this->entry($reference, 'From the first provider')),
                $this->providerAnswering($reference, $this->entry($reference, 'From the second provider')),
            ],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        self::assertSame('From the first provider', $registry->describe($reference, self::STORE_ID)->getTitle());
    }

    public function testAProviderThatDoesNotKnowTheReferenceIsPassedOver(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');

        $registry = new VariableKnowledgeRegistry(
            [
                $this->providerAnswering($reference, null),
                $this->providerAnswering($reference, $this->entry($reference, 'From the second provider')),
            ],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        self::assertSame('From the second provider', $registry->describe($reference, self::STORE_ID)->getTitle());
    }

    public function testAnEntryComesBackCarryingAnAffordance(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($reference, $this->entry($reference, 'Store name'))],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        $affordance = $registry->describe($reference, self::STORE_ID)->getAffordance();

        self::assertNotNull($affordance);
        self::assertSame(EditAffordanceInterface::KIND_INSTRUCTION, $affordance->getKind());
    }

    /**
     * A provider that already decided has the last word. Resolving over the top of a stated
     * affordance would be silent rather than wrong-looking: the entry would still show a plausible
     * link, just not the one it was given, and nothing anywhere would say so.
     *
     * @return void
     */
    public function testAnEntryThatAlreadyStatesAnAffordanceKeepsIt(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');
        $stated = EditAffordance::link('Open Store Information', 'https://example.test/admin/stated');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($reference, $this->entry($reference, 'Store name')->withAffordance($stated))],
            [$this->resolverThatMustNotBeAsked()],
            new DirectiveReferenceParser()
        );

        self::assertSame($stated, $registry->describe($reference, self::STORE_ID)->getAffordance());
    }

    /**
     * Stating one is the exception, not the rule: an entry whose origin a resolver understands is
     * meant to get that resolver's answer without having to repeat it.
     *
     * @return void
     */
    public function testAnEntryThatStatesNoAffordanceGetsTheOneThePoolResolves(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');
        $pooled = EditAffordance::link('Worked out from the origin', 'https://example.test/admin/pooled');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($reference, $this->entry($reference, 'Store name'))],
            [$this->resolverClaimingEverything($pooled)],
            new DirectiveReferenceParser()
        );

        self::assertSame($pooled, $registry->describe($reference, self::STORE_ID)->getAffordance());
    }

    public function testAListedEntryKeepsTheAffordanceItAlreadyStates(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');
        $stated = EditAffordance::instruction('How to change this', ['Open the configuration.']);

        $registry = new VariableKnowledgeRegistry(
            [$this->providerListing([$this->entry($reference, 'Store name')->withAffordance($stated)])],
            [
                $this->resolverClaimingEverything(
                    EditAffordance::link('Worked out from the origin', 'https://example.test/admin/pooled')
                ),
            ],
            new DirectiveReferenceParser()
        );

        $entries = $registry->listAll(self::STORE_ID);

        self::assertSame($stated, $entries['config:general/store_information/name']->getAffordance());
    }

    public function testTheFirstResolverThatClaimsTheOriginDecidesTheAffordance(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');
        $link = EditAffordance::link('Open Store Information', 'https://example.test/admin');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($reference, $this->entry($reference, 'Store name'))],
            [$this->resolverClaimingEverything($link), new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        self::assertSame($link, $registry->describe($reference, self::STORE_ID)->getAffordance());
    }

    /**
     * A resolver that declines hands the entry on, and the pool ends in one that claims everything -
     * which is what makes "an entry always carries an affordance" true rather than hopeful.
     *
     * @return void
     */
    public function testAnUnclaimedOriginFallsThroughToTheCatchAll(): void
    {
        $reference = new DirectiveReference('var', 'order.increment_id');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($reference, $this->entry($reference, 'Order number'))],
            [$this->resolverClaimingNothing(), new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        $affordance = $registry->describe($reference, self::STORE_ID)->getAffordance();

        self::assertNotNull($affordance);
        self::assertSame(EditAffordanceInterface::KIND_INSTRUCTION, $affordance->getKind());
        self::assertNotEmpty($affordance->getSteps());
    }

    /**
     * A pool that lost its catch-all is a wiring defect. Answering with an affordance that says
     * nothing would hide it behind an entry that merely looks unhelpful.
     *
     * @return void
     */
    public function testAPoolWithNoCatchAllRefusesRatherThanFallingThrough(): void
    {
        $reference = new DirectiveReference('var', 'order.increment_id');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($reference, $this->entry($reference, 'Order number'))],
            [$this->resolverClaimingNothing()],
            new DirectiveReferenceParser()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No edit affordance resolver claims an origin of kind "template_var"');

        $registry->describe($reference, self::STORE_ID);
    }

    public function testAnUnknownReferenceGetsTheNotDocumentedEntry(): void
    {
        $reference = new DirectiveReference('trans', 'Thank you for your order');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($reference, null)],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        $entry = $registry->describe($reference, self::STORE_ID);

        self::assertFalse($entry->isKnown());
        self::assertSame($reference, $entry->getReference());
        self::assertStringContainsString('trans', $entry->getTitle());
        self::assertStringContainsString('no entry', $entry->getSummary());
        self::assertSame(OriginInterface::KIND_COMPUTED, $entry->getOrigin()->getKind());
        self::assertStringContainsString('trans', $entry->getOrigin()->getExplanation());
        self::assertFalse($entry->isValueWritable());
        self::assertNotNull($entry->getAffordance());
        self::assertSame(EditAffordanceInterface::KIND_INSTRUCTION, $entry->getAffordance()->getKind());
    }

    /**
     * Everything the not-documented entry says is about the directive kind or about the state of the
     * base itself. It must not read as a description of the reference, because an administrator
     * cannot tell an invented description from a researched one.
     *
     * @return void
     */
    public function testTheNotDocumentedEntryClaimsNoKnowledgeOfTheReference(): void
    {
        $reference = new DirectiveReference('var', 'some.undocumented.thing');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($reference, null)],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        $entry = $registry->describe($reference, self::STORE_ID);

        self::assertStringNotContainsString('some.undocumented.thing', $entry->getSummary());
        self::assertStringNotContainsString('some.undocumented.thing', $entry->getOrigin()->getExplanation());
        self::assertSame('', $entry->getOrigin()->getLocator());
        self::assertSame(VariableKnowledgeInterface::OUTPUT_TEXT, $entry->getOutputKind());
    }

    /**
     * An absent modifier chain is not the absence of formatting, so the entry says which modifier a
     * directive of that kind gets anyway. Without it a chain editor would offer to remove protection
     * an administrator never knew was there.
     *
     * @return void
     */
    public function testTheNotDocumentedEntryStillNamesTheModifierThatAppliesWithNoChain(): void
    {
        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering(null, null)],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser(),
            ['var' => 'escape'],
            new DirectiveReferenceParser()
        );

        self::assertSame(
            'escape',
            $registry->describe(new DirectiveReference('var', 'some.thing'), self::STORE_ID)->getDefaultModifier()
        );
        self::assertNull(
            $registry->describe(new DirectiveReference('config', 'a/b/c'), self::STORE_ID)->getDefaultModifier()
        );
    }

    /**
     * The truncation flag is set only while the reference is still the one built from the document; a
     * canonical string handed back by a browser is within the limit and arrives without it. So the
     * caveat appears exactly when it can be trusted.
     *
     * @return void
     */
    public function testATruncatedReferenceSaysThatItsLookupKeyWasShortened(): void
    {
        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering(null, null)],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        $truncated = $registry->describe(new DirectiveReference('trans', 'A long message', true), self::STORE_ID);
        $ordinary = $registry->describe(new DirectiveReference('trans', 'A long message'), self::STORE_ID);

        self::assertCount(1, $truncated->getCaveats());
        self::assertStringContainsString('shortened', $truncated->getCaveats()[0]);
        self::assertSame([], $ordinary->getCaveats());
    }

    public function testListAllIsKeyedByCanonicalReference(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerListing([$this->entry($reference, 'Store name')])],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        self::assertSame(
            ['config:general/store_information/name'],
            array_keys($registry->listAll(self::STORE_ID))
        );
    }

    /**
     * Precedence applies to the whole list exactly as it applies to a single reference: a provider
     * consulted later fills gaps and never replaces what an earlier one already said.
     *
     * @return void
     */
    public function testALaterProviderDoesNotShadowAnEarlierOneInTheList(): void
    {
        $shared = new DirectiveReference('config', 'general/store_information/name');
        $own = new DirectiveReference('customVar', 'my_code');

        $registry = new VariableKnowledgeRegistry(
            [
                $this->providerListing([$this->entry($shared, 'From the first provider')]),
                $this->providerListing([
                    $this->entry($shared, 'From the second provider'),
                    $this->entry($own, 'Only the second provider has this'),
                ]),
            ],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        $entries = $registry->listAll(self::STORE_ID);

        self::assertCount(2, $entries);
        self::assertSame('From the first provider', $entries['config:general/store_information/name']->getTitle());
        self::assertSame('Only the second provider has this', $entries['customVar:my_code']->getTitle());
    }

    public function testEveryListedEntryCarriesAnAffordance(): void
    {
        $reference = new DirectiveReference('config', 'general/store_information/name');

        $registry = new VariableKnowledgeRegistry(
            [$this->providerListing([$this->entry($reference, 'Store name')])],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        foreach ($registry->listAll(self::STORE_ID) as $entry) {
            self::assertNotNull($entry->getAffordance());
        }
    }

    /**
     * A merge that dropped every provider would otherwise show up as a base that quietly knows
     * nothing, which reads to an administrator exactly like a base that was never populated.
     *
     * @return void
     */
    public function testADirectiveNothingDescribesFallsBackToWhatItsKindMeans(): void
    {
        $kind = new DirectiveReference('trans', '');
        $asked = new DirectiveReference('trans', 'Thank you for your order');

        $described = $this->registryKnowingOnly($kind, 'The {{trans}} directive')
            ->describe($asked, self::STORE_ID);

        self::assertSame('The {{trans}} directive', $described->getTitle());
        self::assertTrue($described->isKnown());
    }

    public function testTheKindsDescriptionIsReturnedUnderTheReferenceThatWasAskedAbout(): void
    {
        $asked = new DirectiveReference('block', 'Magento\Cms\Block\Block');

        $described = $this->registryKnowingOnly(new DirectiveReference('block', ''), 'The {{block}} directive')
            ->describe($asked, self::STORE_ID);

        // The caller looks its answer up by the string it sent, so an answer filed under the kind
        // alone would arrive as a miss however good its prose is.
        self::assertSame('block:Magento\Cms\Block\Block', $described->getReference()->toCanonicalString());
    }

    public function testTheKindsDescriptionSaysItIsNotAboutThisParticularDirective(): void
    {
        $described = $this->registryKnowingOnly(new DirectiveReference('trans', ''), 'The {{trans}} directive')
            ->describe(new DirectiveReference('trans', 'Thank you'), self::STORE_ID);

        self::assertNotEmpty($described->getCaveats());
        self::assertStringContainsString(
            '{{trans}}',
            implode(' ', $described->getCaveats())
        );
    }

    public function testAValueIsNeverReportedWritableOnTheStrengthOfItsKind(): void
    {
        $kind = new DirectiveReference('config', '');
        $writableKindEntry = new VariableKnowledge(
            $kind,
            true,
            'The {{config}} directive',
            'Prints a configuration value.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, '', 'Read from the configuration.'),
            [],
            null,
            true
        );

        $registry = new VariableKnowledgeRegistry(
            [$this->providerAnswering($kind, $writableKindEntry)],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        // Writability is a decision about one named value at one scope. Carrying it down from the
        // kind would offer an editor for every path the base has never looked at.
        self::assertFalse(
            $registry->describe(new DirectiveReference('config', 'web/secure/base_url'), self::STORE_ID)
                ->isValueWritable()
        );
    }

    public function testAKindNothingDescribesStillLandsOnTheNotDocumentedAnswer(): void
    {
        $described = $this->registryKnowingOnly(new DirectiveReference('var', 'order.increment_id'), 'Order number')
            ->describe(new DirectiveReference('trans', 'Thank you'), self::STORE_ID);

        self::assertFalse($described->isKnown());
    }

    public function testAKindOnlyReferenceNothingDescribesIsNotLookedUpTwice(): void
    {
        $provider = $this->createMock(VariableKnowledgeProviderInterface::class);
        // A kind-only reference has nothing left to fall back to, so asking a second time would be
        // the same question and, for a provider that reads from storage, the same cost again.
        $provider->expects(self::once())->method('describe')->willReturn(null);
        $provider->method('listAll')->willReturn([]);

        $registry = new VariableKnowledgeRegistry(
            [$provider],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );

        self::assertFalse($registry->describe(new DirectiveReference('protocol', ''), self::STORE_ID)->isKnown());
    }

    public function testAnEmptyProviderPoolIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"providers" pool');

        new VariableKnowledgeRegistry(
            [],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );
    }

    public function testAnEmptyAffordanceResolverPoolIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"affordanceResolvers" pool');

        new VariableKnowledgeRegistry(
            [$this->providerAnswering(null, null)],
            [],
            new DirectiveReferenceParser()
        );
    }

    public function testAProviderPoolEntryOfTheWrongTypeIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(VariableKnowledgeProviderInterface::class);

        new VariableKnowledgeRegistry(
            ['broken' => new \stdClass()],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );
    }

    public function testAnAffordanceResolverPoolEntryOfTheWrongTypeIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(EditAffordanceResolverInterface::class);

        new VariableKnowledgeRegistry(
            [$this->providerAnswering(null, null)],
            ['broken' => $this->providerAnswering(null, null)],
            new DirectiveReferenceParser()
        );
    }

    /**
     * The message points at the wiring that has to be fixed, not just at the interface.
     *
     * @return void
     */
    public function testTheRefusalNamesTheOffendingPoolEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry "broken"');

        new VariableKnowledgeRegistry(
            ['broken' => new \stdClass()],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );
    }

    /**
     * @param DirectiveReferenceInterface|null $reference Reference the provider is asked about, or
     *                                                   null when the call is not being pinned down
     * @param VariableKnowledgeInterface|null $entry What the provider answers with
     * @return VariableKnowledgeProviderInterface
     */
    private function providerAnswering(
        ?DirectiveReferenceInterface $reference,
        ?VariableKnowledgeInterface $entry
    ): VariableKnowledgeProviderInterface {
        $provider = $this->createMock(VariableKnowledgeProviderInterface::class);
        $provider->method('describe')
            ->willReturnCallback(
                static function (DirectiveReferenceInterface $asked) use ($reference, $entry): ?VariableKnowledgeInterface {
                    if ($reference !== null && !$asked->equals($reference)) {
                        return null;
                    }

                    return $entry;
                }
            );
        $provider->method('listAll')->willReturn([]);

        return $provider;
    }

    /**
     * @param VariableKnowledgeInterface[] $entries Entries the provider lists
     * @return VariableKnowledgeProviderInterface
     */
    private function providerListing(array $entries): VariableKnowledgeProviderInterface
    {
        $provider = $this->createMock(VariableKnowledgeProviderInterface::class);
        $provider->method('describe')->willReturn(null);
        $provider->method('listAll')->willReturn($entries);

        return $provider;
    }

    /**
     * @param EditAffordanceInterface $affordance Affordance the resolver answers with
     * @return EditAffordanceResolverInterface
     */
    private function resolverClaimingEverything(EditAffordanceInterface $affordance): EditAffordanceResolverInterface
    {
        $resolver = $this->createMock(EditAffordanceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->method('resolve')->willReturn($affordance);

        return $resolver;
    }

    /**
     * A resolver that fails the test if the pool is consulted at all
     *
     * @return EditAffordanceResolverInterface
     */
    private function resolverThatMustNotBeAsked(): EditAffordanceResolverInterface
    {
        $resolver = $this->createMock(EditAffordanceResolverInterface::class);
        $resolver->expects(self::never())->method('supports');
        $resolver->expects(self::never())->method('resolve');

        return $resolver;
    }

    /**
     * @return EditAffordanceResolverInterface
     */
    private function resolverClaimingNothing(): EditAffordanceResolverInterface
    {
        $resolver = $this->createMock(EditAffordanceResolverInterface::class);
        $resolver->method('supports')->willReturn(false);
        $resolver->expects(self::never())->method('resolve');

        return $resolver;
    }

    /**
     * @param DirectiveReferenceInterface $reference Reference the entry describes
     * @param string $title Title, used to tell one provider's answer from another's
     * @return VariableKnowledgeInterface
     */
    /**
     * Build a registry over one provider that only ever answers about the given reference
     *
     * @param DirectiveReferenceInterface $known The one reference the provider is the authority on
     * @param string $title Title that provider's entry carries
     * @return VariableKnowledgeRegistry Registry wired with that single provider
     */
    private function registryKnowingOnly(
        DirectiveReferenceInterface $known,
        string $title
    ): VariableKnowledgeRegistry {
        return new VariableKnowledgeRegistry(
            [$this->providerAnswering($known, $this->entry($known, $title))],
            [new InstructionAffordanceResolver()],
            new DirectiveReferenceParser()
        );
    }

    private function entry(DirectiveReferenceInterface $reference, string $title): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            $reference,
            true,
            $title,
            'A description written for this test.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_TEMPLATE_VAR, 'order', 'Assigned by the sending code.')
        );
    }
}
