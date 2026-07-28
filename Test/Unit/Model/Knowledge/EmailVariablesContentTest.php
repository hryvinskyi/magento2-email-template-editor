<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use PHPUnit\Framework\TestCase;

/**
 * Guards the content of the shipped knowledge file, which the schema cannot judge.
 *
 * The schema says an entry is well formed. It cannot say whether an entry is true, and it cannot say
 * whether the file still covers what the editor is used on. The checks here are the ones that turn a
 * silent content mistake into a failing build:
 *
 * - nothing is described twice, so an administrator reading an entry is reading the only one;
 * - nothing is described emptily, so a popover never opens on blank prose;
 * - a link is an admin route and never a finished address, because an administration URL carries a
 *   key belonging to the session it was built in and a literal one is dead before anybody follows it;
 * - a value the sending code assigns offers written steps rather than a link, because no page in the
 *   administration edits it and a link would take an administrator somewhere that cannot help;
 * - every caveat and every step carries an identifier, because a module adding one to a variable that
 *   already has several is merged alongside them only when both sides are identified, and without it
 *   the merge of the whole configuration fails;
 * - every family of messages the module knows how to preview has at least one entry, so a family
 *   dropped while the file was being written fails by name instead of quietly going undocumented.
 */
class EmailVariablesContentTest extends TestCase
{
    /**
     * Admin route front names a link affordance may open
     *
     * The first segment of a route is what decides which application the link lands in. Restricting it
     * to the administration's own front names is what makes "a link is a route" checkable: a storefront
     * route, an absolute address or a bare path all fail here.
     *
     * Derived from the full routes below rather than written out a second time: a route that has to
     * be one of those cannot have a front name they do not carry, and two lists stating the same
     * thing only stay in agreement until somebody edits one of them.
     *
     * @return string[]
     */
    private function allowedRouteFrontNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $route): string => explode('/', $route)[0],
            self::ALLOWED_ROUTES
        )));
    }

    /**
     * One directive every family of messages must have an entry for
     *
     * The families are read from the module's own template-to-group mapping rather than written out
     * here, so a family added to that mapping without a word being written about it fails by name. The
     * expression beside each family is a variable that only that family's messages carry, which is why
     * finding it is evidence the family is covered.
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
     * Name of the type whose mapping decides which families the editor can preview
     */
    private const TEMPLATE_MAPPING_TYPE =
        'Hryvinskyi\EmailTemplateEditor\Model\SampleData\TemplateProviderMapping';

    /**
     * The whole route a link affordance may open
     *
     * Every one of these was looked up in a routes.xml and found to have a controller behind it on
     * this installation. Checking the front name alone would still let a typo in the controller or
     * the action through, and a link that opens a page which does not exist is worse than no link:
     * an administrator reads it as the feature being broken rather than as the entry being wrong.
     *
     * @var string[]
     */
    private const ALLOWED_ROUTES = [
        'adminhtml/system_config/edit',
        'adminhtml/system_variable/index',
        'theme/design_config/index',
    ];

    /**
     * The configuration paths a {{config}} directive can read
     *
     * The email template filter reads this fixed list and renders every other path as an empty
     * string without a word of complaint, so an entry describing a path outside it is describing
     * something that never appears in a message. The list is repeated here because these tests do
     * not boot Magento; a second check compares this copy against the wiring it mirrors, so the two
     * cannot drift apart unnoticed.
     *
     * @var string[]
     */
    private const READABLE_CONFIG_PATHS = [
        'web/unsecure/base_url',
        'web/secure/base_url',
        'trans_email/ident_general/name',
        'trans_email/ident_general/email',
        'trans_email/ident_sales/name',
        'trans_email/ident_sales/email',
        'trans_email/ident_support/name',
        'trans_email/ident_support/email',
        'trans_email/ident_custom1/name',
        'trans_email/ident_custom1/email',
        'trans_email/ident_custom2/name',
        'trans_email/ident_custom2/email',
        'general/store_information/name',
        'general/store_information/phone',
        'general/store_information/hours',
        'general/store_information/country_id',
        'general/store_information/region_id',
        'general/store_information/postcode',
        'general/store_information/city',
        'general/store_information/street_line1',
        'general/store_information/street_line2',
        'general/store_information/merchant_vat_number',
    ];

    /**
     * Wording an entry about an unreadable configuration path has to contain
     *
     * Deliberately loose: what is being checked is that the entry warns at all, not that it warns in
     * one particular sentence. The wording is meant to be edited; the warning is not.
     */
    private const EMPTY_RENDER_PHRASE = 'empty string';

    /**
     * Where the wiring that decides the readable paths lives, relative to this module's neighbours
     */
    private const READABLE_PATHS_WIRING = 'magento/module-variable/etc/di.xml';

    public function testEveryEntryDescribesADifferentDirective(): void
    {
        $seen = [];

        foreach ($this->variables() as $variable) {
            $reference = $variable->getAttribute('kind') . ':' . $variable->getAttribute('expression');

            self::assertNotContains(
                $reference,
                $seen,
                sprintf(
                    'The knowledge file describes "%s" more than once. Only one of the two would ever '
                    . 'be read and nothing would say which.',
                    $reference
                )
            );

            $seen[] = $reference;
        }

        self::assertNotEmpty($seen, 'The knowledge file describes nothing at all.');
    }

    public function testEveryEntrySaysWhatItIsAndWhereItComesFrom(): void
    {
        foreach ($this->variables() as $variable) {
            $reference = $this->reference($variable);

            self::assertNotSame('', $this->childText($variable, 'title'), $this->because($reference, 'no title'));
            self::assertNotSame('', $this->childText($variable, 'summary'), $this->because($reference, 'no summary'));

            $origin = $this->firstChild($variable, 'origin');

            self::assertNotNull($origin, $this->because($reference, 'no origin'));
            self::assertNotSame(
                '',
                $origin->getAttribute('kind'),
                $this->because($reference, 'an origin that does not say what kind it is')
            );
            self::assertNotSame(
                '',
                $this->normalise($origin->textContent),
                $this->because($reference, 'an origin that does not explain how the value is produced')
            );
        }
    }

    public function testALinkNamesAnAdminRouteRatherThanAFinishedAddress(): void
    {
        foreach ($this->affordances() as $reference => $affordance) {
            $route = trim($affordance->getAttribute('route'));

            if ($route === '') {
                continue;
            }

            self::assertStringNotContainsString(
                '://',
                $route,
                $this->because($reference, 'a finished address where a route belongs')
            );
            self::assertStringStartsNotWith(
                '/',
                $route,
                $this->because($reference, 'a path where a route belongs')
            );

            $frontName = explode('/', $route)[0];

            self::assertContains(
                $frontName,
                $this->allowedRouteFrontNames(),
                $this->because(
                    $reference,
                    sprintf('a route into "%s", which is not an administration front name', $frontName)
                )
            );
        }
    }

    public function testAValueTheSendingCodeAssignsOffersWrittenStepsRatherThanALink(): void
    {
        $checked = 0;

        foreach ($this->variables() as $variable) {
            $origin = $this->firstChild($variable, 'origin');

            if ($origin === null || $origin->getAttribute('kind') !== OriginInterface::KIND_TEMPLATE_VAR) {
                continue;
            }

            $reference = $this->reference($variable);
            $affordance = $this->firstChild($variable, 'affordance');

            self::assertNotNull($affordance, $this->because($reference, 'nothing an administrator can do'));
            self::assertSame(
                EditAffordanceInterface::KIND_INSTRUCTION,
                $affordance->getAttribute('kind'),
                $this->because(
                    $reference,
                    'an edit route other than written steps, although no administration page assigns '
                    . 'a value the sending code puts into the message'
                )
            );
            self::assertNotEmpty(
                $this->children($affordance, 'step'),
                $this->because($reference, 'written steps with nothing written in them')
            );

            $checked++;
        }

        self::assertGreaterThan(
            0,
            $checked,
            'No entry describes a value the sending code assigns, so this check proved nothing.'
        );
    }

    public function testEveryCaveatAndStepIsIdentifiedSoContributionsMergeAlongsideThem(): void
    {
        foreach ($this->variables() as $variable) {
            $reference = $this->reference($variable);

            foreach ($this->children($variable, 'caveat') as $caveat) {
                self::assertNotSame(
                    '',
                    trim($caveat->getAttribute('id')),
                    $this->because($reference, 'a caveat with no identifier')
                );
            }

            $affordance = $this->firstChild($variable, 'affordance');

            if ($affordance === null) {
                continue;
            }

            foreach ($this->children($affordance, 'step') as $step) {
                self::assertNotSame(
                    '',
                    trim($step->getAttribute('id')),
                    $this->because($reference, 'a step with no identifier')
                );
            }
        }
    }

    public function testEveryFamilyOfMessagesTheEditorPreviewsHasAnEntry(): void
    {
        $expressions = [];

        foreach ($this->variables() as $variable) {
            if ($variable->getAttribute('kind') === 'var') {
                $expressions[] = $variable->getAttribute('expression');
            }
        }

        foreach ($this->templateFamilies() as $family) {
            self::assertArrayHasKey(
                $family,
                self::FAMILY_WITNESS,
                sprintf(
                    'The module can preview the "%s" messages, but nothing here says which variable '
                    . 'proves that family is documented.',
                    $family
                )
            );

            self::assertContains(
                self::FAMILY_WITNESS[$family],
                $expressions,
                sprintf(
                    'The "%s" messages have no entry in the knowledge file: "%s" is not described.',
                    $family,
                    self::FAMILY_WITNESS[$family]
                )
            );
        }
    }

    public function testEveryLinkOpensARouteThatWasLookedUpOnThisInstallation(): void
    {
        foreach ($this->affordances() as $reference => $affordance) {
            $route = trim($affordance->getAttribute('route'));

            if ($affordance->getAttribute('kind') === EditAffordanceInterface::KIND_LINK) {
                // A link with no route is thrown away when the file is read, leaving the entry
                // described and its edit route silently absent.
                self::assertNotSame(
                    '',
                    $route,
                    $this->because($reference, 'a link that names no route to open')
                );
            }

            if ($route === '') {
                continue;
            }

            self::assertContains(
                $route,
                self::ALLOWED_ROUTES,
                $this->because(
                    $reference,
                    sprintf(
                        'a link into "%s", which is not one of the routes this content was checked '
                        . 'against. Look the new route up in a routes.xml, confirm it has a '
                        . 'controller, and add it here',
                        $route
                    )
                )
            );
        }
    }

    public function testEveryWrittenInstructionCarriesAtLeastOneStep(): void
    {
        foreach ($this->affordances() as $reference => $affordance) {
            if ($affordance->getAttribute('kind') !== EditAffordanceInterface::KIND_INSTRUCTION) {
                continue;
            }

            self::assertNotEmpty(
                $this->children($affordance, 'step'),
                $this->because($reference, 'written steps with nothing written in them')
            );
        }
    }

    public function testEveryDirectiveKindIsDescribedInItsOwnRight(): void
    {
        $described = [];

        foreach ($this->variables() as $variable) {
            if ($variable->getAttribute('expression') === '') {
                $described[] = $variable->getAttribute('kind');
            }
        }

        foreach (array_keys(DirectiveReferenceParser::KINDS) as $kind) {
            self::assertContains(
                $kind,
                $described,
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
        $checked = 0;

        foreach ($this->variables() as $variable) {
            $path = $variable->getAttribute('expression');

            // An entry with no path describes the directive itself rather than one setting, and
            // there is nothing to check it against.
            if ($variable->getAttribute('kind') !== 'config' || $path === '') {
                continue;
            }

            $readable = in_array($path, self::READABLE_CONFIG_PATHS, true);
            $warns = str_contains($this->caveatText($variable), self::EMPTY_RENDER_PHRASE);

            self::assertTrue(
                $readable || $warns,
                $this->because(
                    $this->reference($variable),
                    'a description of a configuration path an email cannot read and no word about it '
                    . 'rendering as an empty string, so an administrator would take it for a working '
                    . 'setting'
                )
            );

            $checked++;
        }

        self::assertGreaterThan(
            0,
            $checked,
            'No entry describes a configuration path, so this check proved nothing.'
        );
    }

    public function testTheReadablePathsThisFileAssumesAreStillTheOnesMagentoWires(): void
    {
        $wiring = $this->neighbourFile(self::READABLE_PATHS_WIRING);

        if ($wiring === null) {
            self::markTestSkipped(
                'Magento_Variable is not installed beside this module, so the set of configuration '
                . 'paths an email can read cannot be compared against its wiring.'
            );
        }

        $document = new DOMDocument();
        $document->load($wiring);

        $wired = [];

        foreach ($document->getElementsByTagName('item') as $item) {
            $name = $item->getAttribute('name');

            // The wiring nests the paths one level deep, inside an item per configuration group.
            // Only the leaves are paths, and a leaf is the item that carries no items of its own.
            if (str_contains($name, '/') && $this->children($item, 'item') === []) {
                $wired[] = $name;
            }
        }

        $listed = self::READABLE_CONFIG_PATHS;
        sort($wired);
        sort($listed);

        self::assertSame(
            $listed,
            $wired,
            'The set of configuration paths an email can read has changed. Update the list this file '
            . 'assumes, and re-check every entry describing one of those paths before doing so.'
        );
    }

    /**
     * Everything the caveats of an entry say, run together
     *
     * @param DOMElement $variable Entry element
     * @return string
     */
    private function caveatText(DOMElement $variable): string
    {
        $texts = [];

        foreach ($this->children($variable, 'caveat') as $caveat) {
            $texts[] = $this->normalise($caveat->textContent);
        }

        return implode(' ', $texts);
    }

    /**
     * Absolute path to a file of another package installed beside this module, or null
     *
     * @param string $relativePath Path relative to the directory this module is installed in
     * @return string|null
     */
    private function neighbourFile(string $relativePath): ?string
    {
        $candidate = dirname(__DIR__, 6) . '/' . $relativePath;

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * Every family of messages the module knows how to fill with sample data
     *
     * @return string[] Family codes, without repetition
     */
    private function templateFamilies(): array
    {
        $document = new DOMDocument();
        $document->load($this->path('di.xml'));

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query(
            sprintf(
                '//type[@name="%s"]/arguments/argument[@name="templateGroupMap"]/item',
                self::TEMPLATE_MAPPING_TYPE
            )
        );

        self::assertInstanceOf(
            DOMNodeList::class,
            $nodes,
            'The module no longer declares which templates belong to which family.'
        );

        $families = [];

        foreach ($nodes as $node) {
            $family = trim($node->textContent);

            if ($family !== '' && !in_array($family, $families, true)) {
                $families[] = $family;
            }
        }

        self::assertNotEmpty($families, 'The module no longer declares any family of messages.');

        return $families;
    }

    /**
     * Every entry in the shipped knowledge file
     *
     * @return DOMElement[]
     */
    private function variables(): array
    {
        $document = new DOMDocument();
        $document->load($this->path('email_variables.xml'));

        $root = $document->documentElement;

        return $root === null ? [] : $this->children($root, 'variable');
    }

    /**
     * Every affordance in the shipped knowledge file, keyed by the reference it belongs to
     *
     * @return array<string, DOMElement>
     */
    private function affordances(): array
    {
        $affordances = [];

        foreach ($this->variables() as $variable) {
            $affordance = $this->firstChild($variable, 'affordance');

            if ($affordance !== null) {
                $affordances[$this->reference($variable)] = $affordance;
            }
        }

        return $affordances;
    }

    /**
     * The reference an entry describes, for naming it in a failure message
     *
     * @param DOMElement $variable Entry element
     * @return string
     */
    private function reference(DOMElement $variable): string
    {
        return $variable->getAttribute('kind') . ':' . $variable->getAttribute('expression');
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

    /**
     * The text of the first child element of the given name, collapsed to one line
     *
     * @param DOMElement $parent Element to look under
     * @param string $name Element name
     * @return string
     */
    private function childText(DOMElement $parent, string $name): string
    {
        $child = $this->firstChild($parent, $name);

        return $child === null ? '' : $this->normalise($child->textContent);
    }

    /**
     * The first direct child element of the given name, or null
     *
     * @param DOMElement $parent Element to look under
     * @param string $name Element name
     * @return DOMElement|null
     */
    private function firstChild(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($this->children($parent, $name) as $child) {
            return $child;
        }

        return null;
    }

    /**
     * Every direct child element of the given name
     *
     * Direct children only, so that a step inside an affordance is never read as a caveat of the entry
     * around it.
     *
     * @param DOMElement $parent Element to look under
     * @param string $name Element name
     * @return DOMElement[]
     */
    private function children(DOMElement $parent, string $name): array
    {
        $elements = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * Collapse the layout of the file out of a piece of prose
     *
     * @param string $text Text as written in the document
     * @return string
     */
    private function normalise(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Absolute path to a file in the module's etc directory
     *
     * @param string $fileName Name of the file
     * @return string
     */
    private function path(string $fileName): string
    {
        return dirname(__DIR__, 4) . '/etc/' . $fileName;
    }
}
