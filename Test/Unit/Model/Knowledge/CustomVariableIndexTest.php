<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\CustomVariableIndex;
use Magento\Framework\DataObject;
use Magento\Variable\Model\ResourceModel\Variable\Collection as CustomVariableCollection;
use Magento\Variable\Model\ResourceModel\Variable\CollectionFactory as CustomVariableCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomVariableIndexTest extends TestCase
{
    private CustomVariableCollectionFactory&MockObject $collectionFactory;
    private LoggerInterface&MockObject $logger;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->collectionFactory = $this->createFactoryMock(CustomVariableCollectionFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testVariablesAreIndexedByTheirCode(): void
    {
        $index = $this->indexOf(
            new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours']),
            new DataObject(['id' => 8, 'code' => 'returns_policy', 'name' => 'Returns policy'])
        );

        self::assertSame(
            [
                'support_hours' => ['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'],
                'returns_policy' => ['id' => 8, 'code' => 'returns_policy', 'name' => 'Returns policy'],
            ],
            $index->getAll()
        );
    }

    public function testOneVariableIsFoundByItsCode(): void
    {
        $index = $this->indexOf(new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours']));

        self::assertSame(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours'], $index->find('support_hours'));
    }

    /**
     * A directive naming a code nothing defines renders as nothing, and saying so is more use than
     * any description would be - so the absence has to be reported rather than papered over.
     *
     * @return void
     */
    public function testACodeNoVariableCarriesIsNotFound(): void
    {
        $index = $this->indexOf(new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours']));

        self::assertNull($index->find('no_such_code'));
    }

    public function testAVariableWithoutACodeIsNotIndexed(): void
    {
        $index = $this->indexOf(
            new DataObject(['id' => 7, 'code' => '', 'name' => 'Nameless']),
            new DataObject(['id' => 8, 'code' => 'returns_policy', 'name' => 'Returns policy'])
        );

        self::assertSame(['returns_policy'], array_keys($index->getAll()));
    }

    /**
     * A description request carries up to two hundred directives; loading per question would turn
     * one query into hundreds for a table that cannot change while the request runs.
     *
     * @return void
     */
    public function testTheCollectionIsLoadedOnceHoweverManyQuestionsAreAsked(): void
    {
        $index = $this->indexOf(new DataObject(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours']));

        $index->find('support_hours');
        $index->find('returns_policy');
        $index->getAll();
        $index->find('support_hours');

        self::addToAssertionCount(1);
    }

    public function testALoadThatFailedIsReportedOnceAndLeavesAnEmptyIndex(): void
    {
        $collection = $this->createMock(CustomVariableCollection::class);
        $collection->method('getIterator')->willThrowException(new \RuntimeException('the database is away'));
        $this->collectionFactory->expects(self::once())->method('create')->willReturn($collection);
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('the database is away'));

        $index = new CustomVariableIndex($this->collectionFactory, $this->logger);

        self::assertSame([], $index->getAll());
        self::assertNull($index->find('support_hours'));
    }

    /**
     * Build an index over the given variables, expecting exactly one collection load
     *
     * @param DataObject ...$variables Variables the collection holds
     * @return CustomVariableIndex
     */
    private function indexOf(DataObject ...$variables): CustomVariableIndex
    {
        $collection = $this->createMock(CustomVariableCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($variables));

        $this->collectionFactory->expects(self::once())->method('create')->willReturn($collection);

        return new CustomVariableIndex($this->collectionFactory, $this->logger);
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
