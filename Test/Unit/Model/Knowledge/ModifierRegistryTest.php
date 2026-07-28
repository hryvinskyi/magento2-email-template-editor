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
use DOMXPath;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ModifierDescriptorInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ModifierDescriptor;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ModifierRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The registry is exercised twice over: with descriptors built here, for its own behaviour, and with
 * the descriptors this module actually ships, read out of the wiring. The second half is the point.
 * A vocabulary is only useful if it is true, and what makes it true or false is what was written in
 * configuration, which nothing else in the test suite would ever look at.
 */
class ModifierRegistryTest extends TestCase
{
    /**
     * The modifier names the email template filter runs something for
     *
     * It holds its modifiers in an array keyed by name - `nl2br`, plus `escape` added when the filter
     * is constructed - and walks a chain looking each name up in it. A name that is not a key is
     * skipped without a word, so offering one as though the filter carried it out would describe
     * formatting that never happens. For the escaping modifier that is not cosmetic: the absence of a
     * recognised name is exactly what lets an unescaped value through.
     */
    private const NAMES_THE_FILTER_RUNS = ['nl2br', 'escape'];

    /**
     * The wiring this module ships, so that a change to it has to be a deliberate one
     *
     * @return void
     */
    public function testTheShippedVocabularyIsOfferedInItsDeclaredOrder(): void
    {
        $names = array_map(
            static fn (ModifierDescriptorInterface $descriptor): string => $descriptor->getName(),
            $this->shippedRegistry()->getAll()
        );

        self::assertSame(['escape', 'nl2br', 'raw'], $names);
    }

    /**
     * The filter compares the type argument with a switch whose arms are these three spellings and
     * whose fall-through returns the value untouched, so an option published in any other spelling
     * would be an instruction to stop escaping.
     *
     * @return void
     */
    public function testEscapePublishesTheTypesTheFilterComparesAgainst(): void
    {
        $escape = $this->shippedRegistry()->get('escape');

        self::assertInstanceOf(ModifierDescriptorInterface::class, $escape);
        self::assertTrue($escape->isImplemented());
        self::assertSame(
            [['name' => 'type', 'options' => ['html', 'htmlentities', 'url'], 'default' => 'html']],
            $escape->getArgumentSpec()
        );
    }

    /**
     * The name is registered with no callback of its own, so what runs is PHP's own function, which
     * is called with the value alone.
     *
     * @return void
     */
    public function testNewlineConversionTakesNoArguments(): void
    {
        $nl2br = $this->shippedRegistry()->get('nl2br');

        self::assertInstanceOf(ModifierDescriptorInterface::class, $nl2br);
        self::assertTrue($nl2br->isImplemented());
        self::assertSame([], $nl2br->getArgumentSpec());
    }

    /**
     * The idiom for "write this value as it is" is published, because that is what templates are
     * written with, but it is published for what it is: the filter implements nothing for it and the
     * escaping stops only because the name is not one the filter holds.
     *
     * @return void
     */
    public function testTheRawIdiomIsPublishedAsSomethingNothingImplements(): void
    {
        $raw = $this->shippedRegistry()->get('raw');

        self::assertInstanceOf(ModifierDescriptorInterface::class, $raw);
        self::assertFalse($raw->isImplemented());
        self::assertSame([], $raw->getArgumentSpec());
    }

    /**
     * The rule this guards: the editor may name a modifier the filter does not implement, but only
     * while saying so. A name published as implemented that the filter does not hold would be
     * offered as formatting and then silently dropped while the message is rendered, and the one
     * place that shows is in the message that was sent.
     *
     * @return void
     */
    public function testNoNameIsMarkedImplementedUnlessTheFilterRunsIt(): void
    {
        foreach ($this->shippedRegistry()->getAll() as $descriptor) {
            if (!$descriptor->isImplemented()) {
                continue;
            }

            self::assertContains(
                $descriptor->getName(),
                self::NAMES_THE_FILTER_RUNS,
                sprintf(
                    'The modifier "%s" is published as implemented, but the email template filter '
                    . 'runs nothing for it and would skip it in silence.',
                    $descriptor->getName()
                )
            );
        }
    }

    /**
     * A descriptor is never invented for a name nothing published, because the filter would not find
     * that name either.
     *
     * @return void
     */
    public function testANameNothingPublishesIsAnsweredWithNothing(): void
    {
        self::assertNull($this->shippedRegistry()->get('banana'));
    }

    /**
     * The filter's lookup is an array lookup by exact name, so a differently cased spelling is a
     * name it does not hold - and, in a chain, one that quietly does nothing.
     *
     * @return void
     */
    public function testLookupDoesNotFoldCase(): void
    {
        self::assertNull($this->shippedRegistry()->get('ESCAPE'));
    }

    /**
     * The pool arrives keyed by the names its items were wired under; the published vocabulary is
     * positional, in the order the pool gave it.
     *
     * @return void
     */
    public function testTheOfferedOrderIsThePoolsOwnAndItsKeysAreDropped(): void
    {
        $first = new ModifierDescriptor('first', 'First', '');
        $second = new ModifierDescriptor('second', 'Second', '');

        $registry = new ModifierRegistry(['b' => $first, 'a' => $second]);

        self::assertSame([$first, $second], $registry->getAll());
        self::assertSame($second, $registry->get('second'));
    }

    public function testAnEmptyPoolIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wired with no descriptors');

        new ModifierRegistry([]);
    }

    public function testAPoolMemberThatIsNotADescriptorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        new ModifierRegistry(['escape' => new stdClass()]);
    }

    /**
     * Two descriptors for one name would make a lookup answer with whichever was wired later, which
     * says nothing about the merge that produced it.
     *
     * @return void
     */
    public function testANamePublishedTwiceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than once');

        new ModifierRegistry([
            new ModifierDescriptor('escape', 'Escape', ''),
            new ModifierDescriptor('escape', 'Escape again', ''),
        ]);
    }

    /**
     * Build a registry from the descriptors this module wires, in the order the wiring asks for
     *
     * The descriptors are constructed rather than read as data, so that a declaration that could not
     * produce a valid descriptor fails here instead of on an install.
     *
     * @return ModifierRegistry
     */
    private function shippedRegistry(): ModifierRegistry
    {
        $document = new DOMDocument();
        $document->load(dirname(__DIR__, 4) . '/etc/di.xml');
        $xpath = new DOMXPath($document);

        $items = [];
        $query = sprintf(
            '/config/type[@name="%s"]/arguments/argument[@name="descriptors"]/item',
            ModifierRegistry::class
        );

        foreach ($xpath->query($query) ?: [] as $item) {
            /** @var DOMElement $item */
            $items[] = [
                'name' => $item->getAttribute('name'),
                'sortOrder' => (int)$item->getAttribute('sortOrder'),
                'virtualType' => trim((string)$item->nodeValue),
            ];
        }

        self::assertNotSame([], $items, 'The modifier registry is wired with no descriptors.');

        // The wired order is the sortOrder order, exactly as an array argument is sorted before it
        // reaches a constructor. Where the file happens to list them is not the contract.
        usort($items, static fn (array $first, array $second): int => $first['sortOrder'] <=> $second['sortOrder']);

        $descriptors = [];

        foreach ($items as $item) {
            $arguments = $this->readVirtualTypeArguments($xpath, $item['virtualType']);
            $argumentSpec = $arguments['argumentSpec'] ?? [];

            $descriptors[$item['name']] = new ModifierDescriptor(
                $this->readWiredText($arguments, 'name', $item['virtualType']),
                $this->readWiredText($arguments, 'label', $item['virtualType']),
                $this->readWiredText($arguments, 'description', $item['virtualType']),
                ($arguments['implemented'] ?? true) === true,
                is_array($argumentSpec) ? $argumentSpec : []
            );
        }

        return new ModifierRegistry($descriptors);
    }

    /**
     * Read the arguments of one wired virtual type
     *
     * @param DOMXPath $xpath Query over the wiring
     * @param string $virtualType Name of the virtual type to read
     * @return array<string, mixed> Arguments, keyed by argument name
     */
    private function readVirtualTypeArguments(DOMXPath $xpath, string $virtualType): array
    {
        $nodes = $xpath->query(sprintf('/config/virtualType[@name="%s"]/arguments/argument', $virtualType));

        self::assertNotSame(
            0,
            $nodes === false ? 0 : $nodes->length,
            sprintf('The wiring names "%s", but nothing declares it.', $virtualType)
        );

        $arguments = [];

        foreach ($nodes ?: [] as $node) {
            /** @var DOMElement $node */
            $arguments[$node->getAttribute('name')] = $this->readValue($node);
        }

        return $arguments;
    }

    /**
     * Read one wired argument that has to be text
     *
     * @param array<string, mixed> $arguments Arguments of the virtual type
     * @param string $name Argument to read
     * @param string $virtualType Virtual type being read, so a message points at the wiring
     * @return string
     * @throws RuntimeException When the argument is missing or is not text
     */
    private function readWiredText(array $arguments, string $name, string $virtualType): string
    {
        $value = $arguments[$name] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(
                sprintf('The wiring of "%s" must declare "%s" as text.', $virtualType, $name)
            );
        }

        return $value;
    }

    /**
     * Turn one wired argument or item into the value it stands for
     *
     * Only the three shapes this wiring uses are understood; anything else is reported rather than
     * quietly read as text.
     *
     * @param DOMElement $node Argument or item element
     * @return mixed
     */
    private function readValue(DOMElement $node): mixed
    {
        $type = $node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');

        if ($type === 'array') {
            $value = [];

            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->tagName === 'item') {
                    $value[$child->getAttribute('name')] = $this->readValue($child);
                }
            }

            return $value;
        }

        if ($type === 'boolean') {
            return in_array(trim((string)$node->nodeValue), ['true', '1'], true);
        }

        self::assertSame('string', $type, sprintf('Unsupported wiring value type "%s".', $type));

        return (string)$node->nodeValue;
    }
}
