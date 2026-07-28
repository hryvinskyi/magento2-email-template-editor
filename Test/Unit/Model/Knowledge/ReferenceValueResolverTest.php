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
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueStrategyInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ReferenceValueResolver;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ReferenceValueResolverTest extends TestCase
{
    private const STORE_ID = 3;
    private const TEMPLATE_ID = 'sales_email_order_template';

    public function testTheFirstStrategyThatClaimsTheOriginAnswers(): void
    {
        $resolver = new ReferenceValueResolver(
            [
                $this->strategyAnswering(true, new ResolvedValue(true, true, 'from the first')),
                $this->strategyAnswering(true, new ResolvedValue(true, true, 'from the second')),
            ],
            $this->silentLogger()
        );

        self::assertSame('from the first', $this->resolve($resolver)->getPreview());
    }

    public function testAStrategyThatDoesNotClaimTheOriginIsPassedOver(): void
    {
        $resolver = new ReferenceValueResolver(
            [
                $this->strategyAnswering(false, new ResolvedValue(true, true, 'never asked for')),
                $this->strategyAnswering(true, new ResolvedValue(true, true, 'from the second')),
            ],
            $this->silentLogger()
        );

        self::assertSame('from the second', $this->resolve($resolver)->getPreview());
    }

    /**
     * The pool's last member claims everything, so a reference is always answered.
     *
     * @return void
     */
    public function testTheMemberClaimingEveryOriginBacksTheWholePoolUp(): void
    {
        $resolver = new ReferenceValueResolver(
            [
                $this->strategyAnswering(false, new ResolvedValue(true, true, 'never asked for')),
                $this->strategyAnswering(true, new ResolvedValue()),
            ],
            $this->silentLogger()
        );

        $value = $this->resolve($resolver);

        self::assertFalse($value->isAvailable());
        self::assertSame('', $value->getPreview());
    }

    /**
     * A pool that lost its last member is a wiring gap, and it belongs in the log rather than in a
     * panel that fails to open.
     *
     * @return void
     */
    public function testAnOriginNoStrategyClaimsIsRecordedAndReportedAsNoValue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('No reference value strategy claims an origin of kind "config"'));

        $resolver = new ReferenceValueResolver(
            [$this->strategyAnswering(false, new ResolvedValue(true, true, 'never asked for'))],
            $logger
        );

        self::assertFalse($this->resolve($resolver)->isAvailable());
    }

    public function testAStrategyThatFailsIsRecordedAndReportedAsNoValue(): void
    {
        $strategy = $this->createMock(ReferenceValueStrategyInterface::class);
        $strategy->method('supports')->willReturn(true);
        $strategy->method('resolve')->willThrowException(new RuntimeException('the sample builder fell over'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('config:general/store_information/name'),
                self::callback(static fn (array $context): bool => isset($context['exception']))
            );

        $resolver = new ReferenceValueResolver([$strategy], $logger);
        $value = $resolver->resolve($this->entry(), self::STORE_ID, self::TEMPLATE_ID);

        self::assertFalse($value->isAvailable());
        self::assertFalse($value->isExact());
        self::assertSame('', $value->getPreview());
    }

    /**
     * The cap belongs to the facade, so two references are never shortened differently because two
     * strategies each picked their own limit.
     *
     * @return void
     */
    public function testAnOverLongPreviewIsCutAndFlagged(): void
    {
        $resolver = new ReferenceValueResolver(
            [$this->strategyAnswering(true, new ResolvedValue(true, true, str_repeat('a', 12)))],
            $this->silentLogger(),
            10
        );

        $value = $this->resolve($resolver);

        self::assertSame(str_repeat('a', 10), $value->getPreview());
        self::assertTrue($value->isTruncated());
    }

    public function testShorteningKeepsEverythingElseTheAnswerClaimed(): void
    {
        $resolver = new ReferenceValueResolver(
            [
                $this->strategyAnswering(
                    true,
                    new ResolvedValue(
                        true,
                        true,
                        str_repeat('a', 12),
                        false,
                        ResolvedValueInterface::SCOPE_STORE,
                        self::STORE_ID,
                        'Theitbay Store View'
                    )
                ),
            ],
            $this->silentLogger(),
            10
        );

        $value = $this->resolve($resolver);

        self::assertTrue($value->isAvailable());
        self::assertTrue($value->isExact());
        self::assertSame(ResolvedValueInterface::SCOPE_STORE, $value->getScope());
        self::assertSame(self::STORE_ID, $value->getScopeId());
        self::assertSame('Theitbay Store View', $value->getScopeLabel());
    }

    public function testAPreviewWithinTheLimitIsHandedBackUntouched(): void
    {
        $produced = new ResolvedValue(true, true, str_repeat('a', 10));

        $resolver = new ReferenceValueResolver(
            [$this->strategyAnswering(true, $produced)],
            $this->silentLogger(),
            10
        );

        $value = $this->resolve($resolver);

        self::assertSame($produced, $value);
        self::assertFalse($value->isTruncated());
    }

    /**
     * Half of a multi-byte character is not a character, and a preview is supposed to be faithful.
     *
     * @return void
     */
    public function testThePreviewIsCutByCharactersRatherThanBytes(): void
    {
        $resolver = new ReferenceValueResolver(
            [$this->strategyAnswering(true, new ResolvedValue(true, true, str_repeat('ї', 8)))],
            $this->silentLogger(),
            5
        );

        $value = $this->resolve($resolver);

        self::assertSame(str_repeat('ї', 5), $value->getPreview());
        self::assertTrue($value->isTruncated());
    }

    public function testAnEmptyPoolIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The strategy pool of the reference value resolver is empty');

        new ReferenceValueResolver([], $this->silentLogger());
    }

    public function testAPoolMemberThatCannotReadAValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry "config" of the strategy pool');

        new ReferenceValueResolver(['config' => new \stdClass()], $this->silentLogger());
    }

    public function testAPreviewLimitWithNoRoomForAValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at least one character');

        new ReferenceValueResolver(
            [$this->strategyAnswering(true, new ResolvedValue())],
            $this->silentLogger(),
            0
        );
    }

    /**
     * Resolve the shared entry through a resolver
     *
     * @param ReferenceValueResolver $resolver Resolver under test
     * @return ResolvedValueInterface
     */
    private function resolve(ReferenceValueResolver $resolver): ResolvedValueInterface
    {
        return $resolver->resolve($this->entry(), self::STORE_ID, self::TEMPLATE_ID);
    }

    /**
     * An entry whose origin is a configuration path
     *
     * @return VariableKnowledgeInterface
     */
    private function entry(): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('config', 'general/store_information/name'),
            true,
            'Store name',
            'The name of the store.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, 'general/store_information/name', 'Read from configuration.')
        );
    }

    /**
     * A strategy that claims what it is told to and answers with a fixed value
     *
     * @param bool $supports Whether it claims the origin
     * @param ResolvedValueInterface $value What it answers with
     * @return ReferenceValueStrategyInterface&MockObject
     */
    private function strategyAnswering(bool $supports, ResolvedValueInterface $value): ReferenceValueStrategyInterface
    {
        $strategy = $this->createMock(ReferenceValueStrategyInterface::class);
        $strategy->method('supports')->willReturn($supports);
        $strategy->method('resolve')->willReturn($value);

        return $strategy;
    }

    /**
     * A logger that is not expected to hear anything
     *
     * @return LoggerInterface&MockObject
     */
    private function silentLogger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }
}
