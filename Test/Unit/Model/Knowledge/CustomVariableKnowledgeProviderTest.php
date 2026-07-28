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
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\CustomVariableIndex;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\CustomVariableKnowledgeProvider;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Magento\Framework\DataObject;
use Magento\Variable\Model\ResourceModel\Variable\Collection as CustomVariableCollection;
use Magento\Variable\Model\ResourceModel\Variable\CollectionFactory as CustomVariableCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomVariableKnowledgeProviderTest extends TestCase
{
    private const STORE_ID = 3;

    private CustomVariableCollectionFactory&MockObject $collectionFactory;
    private DirectiveReferenceParser $referenceParser;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->collectionFactory = $this->createFactoryMock(CustomVariableCollectionFactory::class);
        $this->referenceParser = new DirectiveReferenceParser();
    }

    public function testAKnownCodeIsDescribedWithTheVariableName(): void
    {
        $provider = $this->providerFor(
            new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'])
        );

        $entry = $provider->describe($this->referenceParser->parse('customVar:support_hours'), self::STORE_ID);

        self::assertInstanceOf(VariableKnowledgeInterface::class, $entry);
        self::assertTrue($entry->isKnown());
        self::assertSame('Support hours', $entry->getTitle());
        self::assertSame(VariableKnowledgeInterface::OUTPUT_HTML, $entry->getOutputKind());
        self::assertSame(OriginInterface::KIND_CUSTOM_VARIABLE, $entry->getOrigin()->getKind());
        self::assertSame('support_hours', $entry->getOrigin()->getLocator());
        self::assertTrue($entry->isValueWritable());
    }

    /**
     * The directive returns the stored value unchanged, so there is no formatting in force when no
     * chain is written - unlike a plain variable directive, which is escaped.
     *
     * @return void
     */
    public function testNoFormattingIsInForceWhenNoChainIsWritten(): void
    {
        $provider = $this->providerFor(
            new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'])
        );

        $entry = $provider->describe($this->referenceParser->parse('customVar:support_hours'), self::STORE_ID);

        self::assertNotNull($entry);
        self::assertNull($entry->getDefaultModifier());
    }

    /**
     * The two halves of the value duality are the whole point of the entry, so both have to be said
     * somewhere an administrator will read them.
     *
     * @return void
     */
    public function testTheEntryStatesWhatHappensWhenOnlyThePlainValueIsFilledIn(): void
    {
        $provider = $this->providerFor(
            new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'])
        );

        $entry = $provider->describe($this->referenceParser->parse('customVar:support_hours'), self::STORE_ID);

        self::assertNotNull($entry);
        self::assertStringContainsString('HTML value', $entry->getOrigin()->getExplanation());
        self::assertStringContainsString('plain value', $entry->getOrigin()->getExplanation());
        self::assertNotEmpty($entry->getCaveats());
    }

    /**
     * An administrator looking at a directive that renders as nothing needs to learn that no
     * variable carries the code. An entry describing "the custom variable my_code" says the
     * opposite, so the honest not-documented answer has to be left to the base.
     *
     * @return void
     */
    public function testACodeNoVariableCarriesIsNotDescribed(): void
    {
        $provider = $this->providerFor(
            new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'])
        );

        self::assertNull(
            $provider->describe($this->referenceParser->parse('customVar:no_such_code'), self::STORE_ID)
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
        $collection = $this->createMock(CustomVariableCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->collectionFactory->expects(self::never())->method('create')->willReturn($collection);

        $provider = new CustomVariableKnowledgeProvider(
            new CustomVariableIndex($this->collectionFactory, $this->createMock(LoggerInterface::class)),
            $this->referenceParser
        );

        self::assertNull($provider->describe($this->referenceParser->parse($canonical), self::STORE_ID));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function otherKindProvider(): array
    {
        return [
            'a plain variable' => ['var:order.increment_id'],
            'a configuration path' => ['config:general/store_information/name'],
            'a translated message' => ['trans:Thank you for your order'],
        ];
    }

    /**
     * The two sources that feed the chooser disagree about quoting, and a chooser row and the
     * identical directive in the content have to reach one entry rather than two.
     *
     * @return void
     */
    public function testTheQuotedAndUnquotedFormsOfACodeReachTheSameEntry(): void
    {
        $provider = $this->providerFor(
            new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'])
        );

        $unquoted = $provider->describe(
            $this->referenceParser->create('customVar', 'support_hours'),
            self::STORE_ID
        );
        $quoted = $provider->describe(
            $this->referenceParser->create('customVar', '"support_hours"'),
            self::STORE_ID
        );

        self::assertNotNull($unquoted);
        self::assertNotNull($quoted);
        self::assertSame(
            $unquoted->getReference()->toCanonicalString(),
            $quoted->getReference()->toCanonicalString()
        );
        self::assertSame($unquoted->getTitle(), $quoted->getTitle());
    }

    /**
     * A description request can carry two hundred references, and one collection load per reference
     * is the ordinary way this becomes hundreds of queries.
     *
     * @return void
     */
    public function testTheVariablesAreLoadedOnceForADescriptionOfManyReferences(): void
    {
        $provider = $this->providerFor(
            new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours']),
            new DataObject(['id' => 8, 'code' => 'returns_policy', 'name' => 'Returns policy'])
        );

        foreach (['support_hours', 'returns_policy', 'no_such_code', 'support_hours'] as $code) {
            $provider->describe($this->referenceParser->create('customVar', $code), self::STORE_ID);
        }

        self::addToAssertionCount(1);
    }

    public function testAVariableWithoutANameIsTitledByItsCode(): void
    {
        $provider = $this->providerFor(new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => '']));

        $entry = $provider->describe($this->referenceParser->parse('customVar:support_hours'), self::STORE_ID);

        self::assertNotNull($entry);
        self::assertSame('support_hours', $entry->getTitle());
    }

    public function testEveryDefinedVariableIsListed(): void
    {
        $provider = $this->providerFor(
            new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours']),
            new DataObject(['id' => 8, 'code' => 'returns_policy', 'name' => 'Returns policy'])
        );

        $references = array_map(
            static fn (VariableKnowledgeInterface $entry): string => $entry->getReference()->toCanonicalString(),
            $provider->listAll(self::STORE_ID)
        );

        self::assertSame(['customVar:support_hours', 'customVar:returns_policy'], $references);
    }

    /**
     * A code no canonical key can hold cannot be looked up, so listing it would offer an entry
     * nothing could ever ask for.
     *
     * @return void
     */
    public function testAVariableWhoseCodeCannotBeAReferenceIsNotListed(): void
    {
        $provider = $this->providerFor(
            new DataObject(['id' => 7, 'code' => "broken\ncode", 'name' => 'Broken']),
            new DataObject(['id' => 8, 'code' => 'returns_policy', 'name' => 'Returns policy'])
        );

        $references = array_map(
            static fn (VariableKnowledgeInterface $entry): string => $entry->getReference()->toCanonicalString(),
            $provider->listAll(self::STORE_ID)
        );

        self::assertSame(['customVar:returns_policy'], $references);
    }

    /**
     * Build a provider over an index holding the given variables, expecting one collection load
     *
     * @param DataObject ...$variables Variables the collection holds
     * @return CustomVariableKnowledgeProvider
     */
    private function providerFor(DataObject ...$variables): CustomVariableKnowledgeProvider
    {
        $collection = $this->createMock(CustomVariableCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($variables));

        $this->collectionFactory->expects(self::once())->method('create')->willReturn($collection);

        return new CustomVariableKnowledgeProvider(
            new CustomVariableIndex($this->collectionFactory, $this->createMock(LoggerInterface::class)),
            $this->referenceParser
        );
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
