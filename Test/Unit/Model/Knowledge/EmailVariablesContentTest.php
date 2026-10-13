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
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathReadabilityInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Hryvinskyi\EmailTemplateEditor\Model\SampleData\TemplateProviderMapping;
use Hryvinskyi\EmailTemplateEditor\Test\Unit\Container\ModuleContainer;
use Hryvinskyi\EmailTemplateEditor\Test\Unit\Container\ReadsEmailVariablesConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * What the shipped knowledge answers, read the way an installation reads it.
 *
 * The file is read by Magento's own reader, validated against the module's schema in developer mode,
 * converted by the module's converter and answered by the module's provider, so every check below is
 * about what an administrator is shown and not about how the file is laid out:
 *
 * - the file loads and nothing in it is refused, so no entry or edit route silently disappears;
 * - nothing is described emptily, so a popover never opens on blank prose;
 * - every link opens an administration route that was looked up on an installation;
 * - a value the sending code assigns offers written steps rather than a link, because no page in the
 *   administration edits it;
 * - another module can add a caveat or a step to any entry and both are shown, beside what is shipped;
 * - every family of messages the editor previews, and every directive kind, has an entry;
 * - an entry about a configuration path an email cannot read says that it renders empty.
 */
class EmailVariablesContentTest extends TestCase
{
    use ReadsEmailVariablesConfiguration;

    /**
     * The store view links are built for, so a scope-aware link carries its scope
     */
    private const STORE_ID = 1;

    /**
     * One directive every family of messages must have an entry for
     *
     * The expression beside each family is a variable that only that family's messages carry, which
     * is why finding it is evidence the family is covered.
     *
     * @var array<string, string>
     */
    private const FAMILY_WITNESS = [
        'order' => 'order.increment_id',
        'invoice' => 'invoice.increment_id',
        'shipment' => 'shipment.increment_id',
        'creditmemo' => 'creditmemo.increment_id',
        'customer' => 'customer.name',
        'newsletter' => 'subscriber_data.confirmation_link',
        'contact' => 'data.comment',
        'checkout' => 'reason',
        'header_footer' => 'logo_url',
    ];

    /**
     * The whole route a link affordance may open
     *
     * Every one of these was looked up in a routes.xml and found to have a controller behind it on an
     * installation. A link that opens a page which does not exist is worse than no link: an
     * administrator reads it as the feature being broken rather than as the entry being wrong.
     *
     * @var string[]
     */
    private const ALLOWED_ROUTES = [
        'adminhtml/system_config/edit',
        'adminhtml/system_variable/index',
        'theme/design_config/index',
    ];

    /**
     * Wording an entry about an unreadable configuration path has to contain
     *
     * Deliberately loose: what is checked is that the entry warns at all, not that it warns in one
     * particular sentence. The wording is meant to be edited; the warning is not.
     */
    private const EMPTY_RENDER_PHRASE = 'empty string';

    /**
     * Caveat and step a contributing module adds to every entry in the merge check
     */
    private const CONTRIBUTED_CAVEAT = 'A caveat contributed by another module.';
    private const CONTRIBUTED_STEP = 'A step contributed by another module.';

    public function testTheShippedFileIsReadWithoutRefusingAnyEntryOrEditRoute(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::never())->method('warning');

        $entries = $this->knowledgeFrom($this->shippedKnowledgeFile(), $logger)->listAll(self::STORE_ID);

        self::assertNotEmpty($entries, 'The knowledge file describes nothing at all.');
    }

    public function testEveryEntrySaysWhatItIsAndWhereItComesFrom(): void
    {
        $originKinds = $this->constants(OriginInterface::class, 'KIND_');

        foreach ($this->shippedEntries() as $entry) {
            $reference = $entry->getReference()->toCanonicalString();

            self::assertNotSame('', $entry->getTitle(), $this->because($reference, 'no title'));
            self::assertNotSame('', $entry->getSummary(), $this->because($reference, 'no summary'));
            self::assertContains(
                $entry->getOrigin()->getKind(),
                $originKinds,
                $this->because($reference, 'an origin that does not say what kind it is')
            );
            self::assertNotSame(
                '',
                $entry->getOrigin()->getExplanation(),
                $this->because($reference, 'an origin that does not explain how the value is produced')
            );
        }
    }

    public function testEveryLinkOpensARouteThatWasLookedUpOnAnInstallation(): void
    {
        $checked = 0;

        foreach ($this->shippedEntries() as $entry) {
            $url = $entry->getAffordance()?->getUrl();

            if ($url === null) {
                continue;
            }

            self::assertContains(
                explode('?', $url, 2)[0],
                self::ALLOWED_ROUTES,
                $this->because(
                    $entry->getReference()->toCanonicalString(),
                    sprintf(
                        'a link built for "%s", which is not one of the routes this content was checked '
                        . 'against. Look the new route up in a routes.xml, confirm it has a controller, '
                        . 'and add it here',
                        $url
                    )
                )
            );

            $checked++;
        }

        self::assertGreaterThan(0, $checked, 'No entry offers a link, so this check proved nothing.');
    }

    public function testAValueTheSendingCodeAssignsOffersWrittenStepsRatherThanALink(): void
    {
        $checked = 0;

        foreach ($this->shippedEntries() as $entry) {
            if ($entry->getOrigin()->getKind() !== OriginInterface::KIND_TEMPLATE_VAR) {
                continue;
            }

            $reference = $entry->getReference()->toCanonicalString();
            $affordance = $entry->getAffordance();

            self::assertNotNull($affordance, $this->because($reference, 'nothing an administrator can do'));
            self::assertSame(
                EditAffordanceInterface::KIND_INSTRUCTION,
                $affordance->getKind(),
                $this->because(
                    $reference,
                    'an edit route other than written steps, although no administration page assigns '
                    . 'a value the sending code puts into the message'
                )
            );

            $checked++;
        }

        self::assertGreaterThan(
            0,
            $checked,
            'No entry describes a value the sending code assigns, so this check proved nothing.'
        );
    }

    public function testEveryWrittenInstructionCarriesAtLeastOneStep(): void
    {
        foreach ($this->shippedEntries() as $entry) {
            $affordance = $entry->getAffordance();

            if ($affordance === null || $affordance->getKind() !== EditAffordanceInterface::KIND_INSTRUCTION) {
                continue;
            }

            self::assertNotEmpty(
                $affordance->getSteps(),
                $this->because($entry->getReference()->toCanonicalString(), 'written steps with nothing in them')
            );
        }
    }

    /**
     * A module adds to an entry by naming its directive and identifying what it adds; the merge has
     * to file the addition beside the shipped content rather than in place of it or in a new entry.
     *
     * Every entry takes part, the ones whose expression holds quotes and brackets included: the
     * merge has to identify those by their expression just the same.
     *
     * @return void
     */
    public function testAContributedCaveatAndStepAreShownBesideTheShippedOnes(): void
    {
        $shipped = $this->shippedEntries();
        $contribution = '';

        foreach ($shipped as $entry) {
            $contribution .= $this->contributionFor($entry);
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $merged = $this->knowledgeFrom(
            $this->shippedKnowledgeFile()
                + ['contribution/email_variables.xml' => '<variables>' . $contribution . '</variables>'],
            $logger
        );

        foreach ($shipped as $entry) {
            $reference = $entry->getReference();
            $after = $merged->describe($reference, self::STORE_ID);

            self::assertNotNull($after, $this->because($reference->toCanonicalString(), 'vanished in the merge'));
            self::assertSame(
                [...$entry->getCaveats(), self::CONTRIBUTED_CAVEAT],
                $after->getCaveats(),
                $this->because($reference->toCanonicalString(), 'caveats the merge did not keep beside each other')
            );

            if ($entry->getAffordance()?->getKind() === EditAffordanceInterface::KIND_INSTRUCTION) {
                self::assertSame(
                    [...$entry->getAffordance()->getSteps(), self::CONTRIBUTED_STEP],
                    $after->getAffordance()?->getSteps(),
                    $this->because($reference->toCanonicalString(), 'steps the merge did not keep beside each other')
                );
            }
        }
    }

    public function testEveryFamilyOfMessagesTheEditorPreviewsHasAnEntry(): void
    {
        $knowledge = $this->shippedKnowledge();
        $parser = $this->parser();

        foreach ($this->previewedFamilies() as $family) {
            self::assertArrayHasKey(
                $family,
                self::FAMILY_WITNESS,
                sprintf(
                    'The editor can preview the "%s" messages, but nothing here says which variable '
                    . 'proves that family is documented.',
                    $family
                )
            );

            self::assertNotNull(
                $knowledge->describe($parser->create('var', self::FAMILY_WITNESS[$family]), self::STORE_ID),
                sprintf(
                    'The "%s" messages are not documented: "%s" is not described.',
                    $family,
                    self::FAMILY_WITNESS[$family]
                )
            );
        }
    }

    public function testEveryDirectiveKindIsDescribedInItsOwnRight(): void
    {
        $knowledge = $this->shippedKnowledge();
        $parser = $this->parser();

        foreach (array_keys(DirectiveReferenceParser::KINDS) as $kind) {
            self::assertNotNull(
                $knowledge->describe($parser->create($kind, ''), self::STORE_ID),
                sprintf(
                    'Nothing describes the {{%s}} directive itself. That entry is what a directive '
                    . 'nobody wrote about falls back on, so without it the whole kind reads as '
                    . 'undocumented.',
                    $kind
                )
            );
        }
    }

    public function testAConfigEntryEitherNamesAReadablePathOrSaysItRendersEmpty(): void
    {
        $readability = $this->knowledgeContainer(
            $this->shippedKnowledgeFile(),
            $this->createMock(LoggerInterface::class)
        )->get(ConfigPathReadabilityInterface::class);
        self::assertInstanceOf(ConfigPathReadabilityInterface::class, $readability);

        $checked = 0;

        foreach ($this->shippedEntries() as $entry) {
            $reference = $entry->getReference();

            // An entry with no path describes the directive itself rather than one setting.
            if ($reference->getKind() !== 'config' || $reference->getExpression() === '') {
                continue;
            }

            $warns = str_contains(implode(' ', $entry->getCaveats()), self::EMPTY_RENDER_PHRASE);

            self::assertTrue(
                $readability->isReadable($reference->getExpression()) || $warns,
                $this->because(
                    $reference->toCanonicalString(),
                    'a description of a configuration path an email cannot read and no word about it '
                    . 'rendering as an empty string, so an administrator would take it for a working setting'
                )
            );

            $checked++;
        }

        self::assertGreaterThan(0, $checked, 'No entry describes a configuration path, so this check proved nothing.');
    }

    /**
     * The shipped knowledge, as the provider answers it
     *
     * @return VariableKnowledgeProviderInterface
     */
    private function shippedKnowledge(): VariableKnowledgeProviderInterface
    {
        return $this->knowledgeFrom($this->shippedKnowledgeFile(), $this->createMock(LoggerInterface::class));
    }

    /**
     * Every shipped entry
     *
     * @return VariableKnowledgeInterface[]
     */
    private function shippedEntries(): array
    {
        return $this->shippedKnowledge()->listAll(self::STORE_ID);
    }

    /**
     * The directive reference parser the module wires
     *
     * @return DirectiveReferenceParserInterface
     */
    private function parser(): DirectiveReferenceParserInterface
    {
        $parser = (new ModuleContainer())->build()->get(DirectiveReferenceParserInterface::class);
        self::assertInstanceOf(DirectiveReferenceParserInterface::class, $parser);

        return $parser;
    }

    /**
     * Every family of messages the editor fills with sample data, as the container configures it
     *
     * @return string[] Family codes, without repetition
     */
    private function previewedFamilies(): array
    {
        $map = (new ModuleContainer())->argumentsOf(TemplateProviderMapping::class)['templateGroupMap'] ?? [];
        self::assertIsArray($map, 'The module no longer says which templates belong to which family.');

        $families = array_values(array_unique(array_filter($map, 'is_string')));
        self::assertNotEmpty($families, 'The module no longer declares any family of messages.');

        return $families;
    }

    /**
     * A contribution adding one identified caveat, and one identified step to written instructions
     *
     * It restates what the schema requires of every variable as the entry already has it, so the
     * merge leaves the shipped prose as it was.
     *
     * @param VariableKnowledgeInterface $entry Shipped entry to add to
     * @return string
     */
    private function contributionFor(VariableKnowledgeInterface $entry): string
    {
        $origin = $entry->getOrigin();
        $affordance = $entry->getAffordance();
        $steps = '';

        if ($affordance !== null && $affordance->getKind() === EditAffordanceInterface::KIND_INSTRUCTION) {
            $steps = sprintf(
                '<affordance kind="instruction" label="%s"><step id="contributed">%s</step></affordance>',
                $this->escape($affordance->getLabel()),
                self::CONTRIBUTED_STEP
            );
        }

        return sprintf(
            '<variable kind="%s" expression="%s" outputKind="%s"><title>%s</title><summary>%s</summary>'
            . '<origin kind="%s" locator="%s">%s</origin>%s<caveat id="contributed">%s</caveat></variable>',
            $this->escape($entry->getReference()->getKind()),
            $this->escape($entry->getReference()->getExpression()),
            $this->escape($entry->getOutputKind()),
            $this->escape($entry->getTitle()),
            $this->escape($entry->getSummary()),
            $this->escape($origin->getKind()),
            $this->escape($origin->getLocator()),
            $this->escape($origin->getExplanation()),
            $steps,
            self::CONTRIBUTED_CAVEAT
        );
    }

    /**
     * Text as it has to be written into XML
     *
     * @param string $text
     * @return string
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1);
    }

    /**
     * The values of an interface's constants whose names share a prefix
     *
     * @param class-string $interface Interface publishing the set
     * @param string $prefix Constant name prefix marking membership of the set
     * @return string[]
     */
    private function constants(string $interface, string $prefix): array
    {
        $values = [];

        foreach ((new ReflectionClass($interface))->getConstants() as $name => $value) {
            if (str_starts_with($name, $prefix) && is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * A failure message naming the entry and what is wrong with it
     *
     * @param string $reference Reference the entry describes
     * @param string $fault What the entry carries, phrased to complete "the entry ... has ..."
     * @return string
     */
    private function because(string $reference, string $fault): string
    {
        return sprintf('The entry for "%s" has %s.', $reference, $fault);
    }
}
