<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Value;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Value\TranslatedMessageValueStrategy;
use Magento\Framework\App\Area;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TranslatedMessageValueStrategyTest extends TestCase
{
    private const STORE_ID = 3;

    /**
     * @var Emulation&MockObject
     */
    private $appEmulation;

    /**
     * @var StateInterface&MockObject
     */
    private $inlineTranslation;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    private TranslatedMessageValueStrategy $strategy;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->appEmulation = $this->createMock(Emulation::class);
        $this->inlineTranslation = $this->createMock(StateInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Theitbay Store View');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->strategy = new TranslatedMessageValueStrategy(
            $this->appEmulation,
            $storeManager,
            $this->inlineTranslation,
            $this->logger
        );
    }

    public function testItClaimsTheOriginEveryDirectiveKindCarries(): void
    {
        self::assertTrue(
            $this->strategy->supports(new Origin(OriginInterface::KIND_DIRECTIVE, '', 'A directive.'))
        );
    }

    public function testItLeavesEveryOtherOriginAlone(): void
    {
        self::assertFalse(
            $this->strategy->supports(new Origin(OriginInterface::KIND_CONFIG, 'a/b/c', 'Configured.'))
        );
    }

    public function testAMessageIsReadAsExactlyWhatTheEmailWouldCarry(): void
    {
        $value = $this->strategy->resolve($this->entry('trans', 'Contact Us'), self::STORE_ID, 'contact_email');

        self::assertTrue($value->isAvailable());
        self::assertTrue($value->isExact());
        self::assertSame('Contact Us', $value->getPreview());
    }

    public function testTheMessageIsReadInsideTheStoreViewsOwnFrontendEnvironment(): void
    {
        // Read as the request stands, the phrase would be translated against the administrator's
        // language and the admin area's dictionaries - a different answer that looks like the right
        // one.
        $this->appEmulation->expects(self::once())
            ->method('startEnvironmentEmulation')
            ->with(self::STORE_ID, Area::AREA_FRONTEND, true);
        $this->appEmulation->expects(self::once())->method('stopEnvironmentEmulation');

        $this->strategy->resolve($this->entry('trans', 'Contact Us'), self::STORE_ID, 'contact_email');
    }

    public function testTheEnvironmentIsHandedBackEvenWhenTheReadFails(): void
    {
        $this->appEmulation->method('startEnvironmentEmulation')->willReturn(null);
        $this->inlineTranslation->method('disable')->willThrowException(new \RuntimeException('broken'));
        $this->appEmulation->expects(self::once())->method('stopEnvironmentEmulation');
        $this->logger->expects(self::once())->method('error');

        $value = $this->strategy->resolve($this->entry('trans', 'Contact Us'), self::STORE_ID, 'contact_email');

        self::assertFalse($value->isAvailable());
    }

    public function testInlineTranslationIsSwitchedOffAroundTheReadAndBackOnAfterIt(): void
    {
        // Its output carries markup meant for the storefront's translation overlay, which would be
        // shown to the administrator as part of the value.
        $this->inlineTranslation->expects(self::once())->method('disable');
        $this->inlineTranslation->expects(self::once())->method('enable');

        $this->strategy->resolve($this->entry('trans', 'Contact Us'), self::STORE_ID, 'contact_email');
    }

    public function testTheDefaultConfigurationIsNotAStoreAndIsNotEmulated(): void
    {
        $this->appEmulation->expects(self::never())->method('startEnvironmentEmulation');

        $value = $this->strategy->resolve($this->entry('trans', 'Contact Us'), 0, 'contact_email');

        self::assertSame(ResolvedValueInterface::SCOPE_DEFAULT, $value->getScope());
        self::assertSame('Default Config', $value->getScopeLabel());
    }

    public function testAStoreViewsAnswerNamesTheStoreItWasReadFor(): void
    {
        $value = $this->strategy->resolve($this->entry('trans', 'Contact Us'), self::STORE_ID, 'contact_email');

        self::assertSame(ResolvedValueInterface::SCOPE_STORE, $value->getScope());
        self::assertSame(self::STORE_ID, $value->getScopeId());
        self::assertSame('Theitbay Store View', $value->getScopeLabel());
    }

    public function testPlaceholdersAreLeftStandingRatherThanFilledWithInventedValues(): void
    {
        // The sending code supplies the parameters; they are not part of what identifies the
        // directive, so a preview that filled them would show a message no recipient receives.
        $value = $this->strategy->resolve(
            $this->entry('trans', 'Thank you, %name'),
            self::STORE_ID,
            'sales_email_order'
        );

        self::assertSame('Thank you, %name', $value->getPreview());
    }

    public function testADirectiveOfAnotherKindIsNotAnsweredFor(): void
    {
        $value = $this->strategy->resolve(
            $this->entry('block', 'Magento\Cms\Block\Block'),
            self::STORE_ID,
            'contact_email'
        );

        self::assertFalse($value->isAvailable());
    }

    public function testAMessageTheScannerCouldNotReadIsNotGuessedAt(): void
    {
        $value = $this->strategy->resolve($this->entry('trans', ''), self::STORE_ID, 'contact_email');

        self::assertFalse($value->isAvailable());
    }

    /**
     * Build an entry carrying a directive origin and the given reference
     *
     * @param string $kind Directive kind
     * @param string $expression Directive expression
     * @return VariableKnowledgeInterface An entry the strategy can be asked about
     */
    private function entry(string $kind, string $expression): VariableKnowledgeInterface
    {
        $reference = new DirectiveReference($kind, $expression);

        return new VariableKnowledge(
            $reference,
            true,
            'A directive',
            'Written for this test.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_DIRECTIVE, '', 'Handled by the template filter.')
        );
    }
}
