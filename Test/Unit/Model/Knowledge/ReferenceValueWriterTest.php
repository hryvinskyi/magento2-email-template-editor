<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueWriteStrategyInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\WriteAuthorizationInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ReferenceValueWriter;
use InvalidArgumentException;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The questions asked of every change, whatever kind of value is being changed.
 *
 * The tests that matter here are the refusals, and each of them asserts that the write never
 * happened rather than only that an exception came out: a refusal that still writes is worse than no
 * refusal at all, because it reads as a working guard.
 */
class ReferenceValueWriterTest extends TestCase
{
    private const STORE_ID = 3;

    private ReferenceValueWriteStrategyInterface&MockObject $strategy;

    private WriteAuthorizationInterface&MockObject $writeAuthorization;

    private StoreManagerInterface&MockObject $storeManager;

    private ReferenceValueResolverInterface&MockObject $valueResolver;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->strategy = $this->createMock(ReferenceValueWriteStrategyInterface::class);
        $this->writeAuthorization = $this->createMock(WriteAuthorizationInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->valueResolver = $this->createMock(ReferenceValueResolverInterface::class);

        $this->valueResolver->method('resolve')->willReturn(new ResolvedValue(true, true, 'Acme Ltd'));

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Theitbay Store View');
        $this->storeManager->method('getStore')->willReturn($store);
    }

    /**
     * @return void
     */
    public function testAWritableEntryIsHandedToTheStrategyThatClaimsItsOrigin(): void
    {
        $entry = $this->entry(true);

        $this->strategy->method('supports')->willReturn(true);
        $this->strategy->expects(self::once())
            ->method('write')
            ->with($entry, self::STORE_ID, 'Acme Ltd');

        $this->writer()->write($entry, self::STORE_ID, 'Acme Ltd');
    }

    /**
     * The browser's opinion about what may be edited is a hint for drawing a control, never an input
     * to the decision. The entry is the one the server built, and it decides.
     *
     * @return void
     */
    public function testAnEntryThatIsNotWritableIsRefusedBeforeAnythingElseIsConsulted(): void
    {
        $this->writeAuthorization->expects(self::never())->method('assertAllowed');
        $this->storeManager->expects(self::never())->method('getStore');
        $this->strategy->expects(self::never())->method('write');

        $this->expectException(LocalizedException::class);

        $this->writer()->write($this->entry(false), self::STORE_ID, 'Acme Ltd');
    }

    /**
     * @return void
     */
    public function testAMissingPermissionRefusesBeforeTheStoreViewIsEvenLookedUp(): void
    {
        $this->writeAuthorization
            ->method('assertAllowed')
            ->willThrowException(new AuthorizationException(new Phrase('Not allowed.')));
        $this->storeManager->expects(self::never())->method('getStore');
        $this->strategy->expects(self::never())->method('write');

        $this->expectException(AuthorizationException::class);

        $this->writer()->write($this->entry(true), self::STORE_ID, 'Acme Ltd');
    }

    /**
     * An identifier naming no store view would otherwise leave a row scoped to a store that does not
     * exist: unreadable by any message, invisible on the configuration page, and indistinguishable
     * from a save that did nothing.
     *
     * @return void
     */
    public function testAStoreViewThatDoesNotExistIsRefusedAndNothingIsWritten(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')
            ->willThrowException(new NoSuchEntityException(new Phrase('No such store.')));

        $this->strategy->method('supports')->willReturn(true);
        $this->strategy->expects(self::never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('4242');

        $this->writer($storeManager)->write($this->entry(true), 4242, 'Acme Ltd');
    }

    /**
     * There is no member of this pool claiming every origin, on purpose: falling through would look
     * from the browser exactly like a save that worked.
     *
     * @return void
     */
    public function testAnOriginNoStrategyClaimsIsRefusedRatherThanPassedOver(): void
    {
        $this->strategy->method('supports')->willReturn(false);
        $this->strategy->expects(self::never())->method('write');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(OriginInterface::KIND_CONFIG);

        $this->writer()->write($this->entry(true), self::STORE_ID, 'Acme Ltd');
    }

    /**
     * The scope is the part the administrator is being invited to check, so it is stated from the
     * store view that was validated and written, not inferred anywhere else.
     *
     * @return void
     */
    public function testTheAnswerNamesTheStoreViewThatWasWritten(): void
    {
        $this->strategy->method('supports')->willReturn(true);

        $value = $this->writer()->write($this->entry(true), self::STORE_ID, 'Acme Ltd');

        self::assertSame(ResolvedValueInterface::SCOPE_STORE, $value->getScope());
        self::assertSame(self::STORE_ID, $value->getScopeId());
        self::assertSame('Theitbay Store View', $value->getScopeLabel());
        self::assertSame('Acme Ltd', $value->getPreview());
    }

    /**
     * Store view zero is "All Store Views", which is the default configuration and belongs to no
     * store view - so it is named as such and no store view is looked up for it.
     *
     * @return void
     */
    public function testTheAnswerNamesTheDefaultScopeWhenThatIsWhatWasWritten(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStore');

        $this->strategy->method('supports')->willReturn(true);

        $value = $this->writer($storeManager)->write($this->entry(true), 0, 'Acme Ltd');

        self::assertSame(ResolvedValueInterface::SCOPE_DEFAULT, $value->getScope());
        self::assertSame(0, $value->getScopeId());
        self::assertSame('Default Config', $value->getScopeLabel());
    }

    /**
     * The value beside the scope is read back through the same reader the panel uses, so what is
     * shown after a change is produced the same way as what was shown before it.
     *
     * @return void
     */
    public function testTheValueIsReadBackThroughTheReaderThePanelUses(): void
    {
        $entry = $this->entry(true);
        $this->strategy->method('supports')->willReturn(true);

        $valueResolver = $this->createMock(ReferenceValueResolverInterface::class);
        $valueResolver->expects(self::once())
            ->method('resolve')
            ->with($entry, self::STORE_ID)
            ->willReturn(new ResolvedValue(true, true, 'Acme Ltd, Springfield'));

        $value = $this->writer(null, $valueResolver)->write($entry, self::STORE_ID, 'Acme Ltd, Springfield');

        self::assertTrue($value->isAvailable());
        self::assertTrue($value->isExact());
        self::assertSame('Acme Ltd, Springfield', $value->getPreview());
    }

    /**
     * A change that lands and then cannot be read back still says where it landed. Saying nothing
     * would leave the administrator unable to check the one thing this answer exists to let them
     * check.
     *
     * @return void
     */
    public function testTheScopeIsStatedEvenWhenTheValueCannotBeReadBack(): void
    {
        $this->strategy->method('supports')->willReturn(true);

        $valueResolver = $this->createMock(ReferenceValueResolverInterface::class);
        $valueResolver->method('resolve')->willReturn(new ResolvedValue());

        $value = $this->writer(null, $valueResolver)->write($this->entry(true), self::STORE_ID, 'Acme Ltd');

        self::assertFalse($value->isAvailable());
        self::assertSame(ResolvedValueInterface::SCOPE_STORE, $value->getScope());
        self::assertSame(self::STORE_ID, $value->getScopeId());
    }

    /**
     * Wiring that lost every writer would otherwise answer every attempt with the refusal meant for
     * origins nothing understands, which reads as a decision somebody took.
     *
     * @return void
     */
    public function testAnEmptyPoolIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReferenceValueWriter([], $this->writeAuthorization, $this->storeManager, $this->valueResolver);
    }

    /**
     * @return void
     */
    public function testAPoolMemberThatCannotWriteIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('config');

        new ReferenceValueWriter(
            ['config' => new \stdClass()],
            $this->writeAuthorization,
            $this->storeManager,
            $this->valueResolver
        );
    }

    /**
     * The writer under test
     *
     * @param StoreManagerInterface|null $storeManager Store manager to use, the shared one by default
     * @param ReferenceValueResolverInterface|null $valueResolver Reader to use, the shared one by default
     * @return ReferenceValueWriter
     */
    private function writer(
        ?StoreManagerInterface $storeManager = null,
        ?ReferenceValueResolverInterface $valueResolver = null
    ): ReferenceValueWriter {
        return new ReferenceValueWriter(
            ['config' => $this->strategy],
            $this->writeAuthorization,
            $storeManager ?? $this->storeManager,
            $valueResolver ?? $this->valueResolver
        );
    }

    /**
     * An entry for a configuration value
     *
     * @param bool $writable Whether the entry reports its value as changeable
     * @return VariableKnowledgeInterface
     */
    private function entry(bool $writable): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('config', 'general/store_information/name'),
            true,
            'Store Name',
            'The name of the store.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, 'general/store_information/name', ''),
            [],
            null,
            $writable
        );
    }
}
