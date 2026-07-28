<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Model\LegacyTemplateRepository;
use Magento\Email\Model\BackendTemplate;
use Magento\Email\Model\BackendTemplateFactory;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LegacyTemplateRepositoryTest extends TestCase
{
    private MockObject $collectionFactory;
    private LegacyTemplateRepository $repository;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->mockFactory(CollectionFactory::class);

        $this->repository = new LegacyTemplateRepository(
            $this->mockFactory(BackendTemplateFactory::class),
            $this->collectionFactory,
            $this->createMock(WebsiteRepositoryInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(PluginBypassFlagInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return array<string, array{0: string[]}>
     */
    public function emptyCodeProvider(): array
    {
        return [
            'no codes at all' => [[]],
            'only blank codes' => [['', '']],
        ];
    }

    /**
     * @dataProvider emptyCodeProvider
     * @param string[] $origCodes
     * @return void
     */
    public function testEmptyOrigCodesAnswerWithoutQuerying(array $origCodes): void
    {
        $this->collectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->repository->getByOrigCodes($origCodes));
    }

    public function testABlankOrigCodeStillAnswersWithoutQuerying(): void
    {
        $this->collectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->repository->getByOrigCode(''));
    }

    public function testScopeBindingsAreResolvedOncePerTemplateForTheLifeOfTheRequest(): void
    {
        $template = $this->createMock(BackendTemplate::class);
        $template->method('getId')->willReturn(7);
        $template->expects(self::once())
            ->method('getSystemConfigPathsWhereCurrentlyUsed')
            ->willReturn([['scope' => 'stores', 'scope_id' => 4]]);

        self::assertSame([4], $this->repository->getScopeBindingsForTemplate($template));
        self::assertSame(
            [4],
            $this->repository->getScopeBindingsForTemplate($template),
            'Resolving a binding scans the config table; the answer cannot change mid-request.'
        );
    }

    public function testResettingStateForcesTheBindingsToBeResolvedAgain(): void
    {
        $template = $this->createMock(BackendTemplate::class);
        $template->method('getId')->willReturn(7);
        $template->expects(self::exactly(2))
            ->method('getSystemConfigPathsWhereCurrentlyUsed')
            ->willReturn([['scope' => 'default', 'scope_id' => 0]]);

        $this->repository->getScopeBindingsForTemplate($template);
        $this->repository->_resetState();
        $this->repository->getScopeBindingsForTemplate($template);
    }

    public function testAnUnsavedTemplateHasNoBindingsAndIsNotLookedUp(): void
    {
        $template = $this->createMock(BackendTemplate::class);
        $template->method('getId')->willReturn(null);
        $template->expects(self::never())->method('getSystemConfigPathsWhereCurrentlyUsed');

        self::assertSame([], $this->repository->getScopeBindingsForTemplate($template));
    }

    /**
     * Mock a DI-compiler-generated factory whether or not it has been generated yet
     *
     * The root package autoloads generated/code, so a factory that has already been compiled is a
     * real class here and its create() must be stubbed with onlyMethods(); one that has not been
     * compiled does not exist at all and only addMethods() can declare it. A clean recompile
     * flips which case applies, so the branch is picked at runtime.
     *
     * @param class-string $factoryClass
     * @return MockObject
     */
    private function mockFactory(string $factoryClass): MockObject
    {
        $builder = $this->getMockBuilder($factoryClass)->disableOriginalConstructor();

        return method_exists($factoryClass, 'create')
            ? $builder->onlyMethods(['create'])->getMock()
            : $builder->addMethods(['create'])->getMock();
    }
}
