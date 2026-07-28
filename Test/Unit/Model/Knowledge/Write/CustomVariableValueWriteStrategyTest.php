<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Write;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Write\CustomVariableValueWriteStrategy;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Validation\ValidationException;
use Magento\Variable\Model\Variable;
use Magento\Variable\Model\VariableFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Changing the HTML value of a merchant-authored custom variable.
 *
 * The variable is a partial mock: only loading, saving and its identifier are replaced, so that
 * setting and reading its two values goes through the real accessors and these tests say something
 * about what would actually be stored.
 */
class CustomVariableValueWriteStrategyTest extends TestCase
{
    private const STORE_ID = 3;
    private const CODE = 'my_code';

    private VariableFactory&MockObject $variableFactory;

    private AuthSession&MockObject $authSession;

    private LoggerInterface&MockObject $logger;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->variableFactory = $this->createMock(VariableFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->authSession = $this->getMockBuilder(AuthSession::class)
            ->disableOriginalConstructor()
            ->addMethods(['getUser'])
            ->getMock();
        $this->authSession->method('getUser')->willReturn(null);
    }

    public function testItClaimsCustomVariableOriginsOnly(): void
    {
        $this->variableFactory->method('create')->willReturn($this->variable([]));
        $strategy = $this->strategy();

        self::assertTrue(
            $strategy->supports(new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, self::CODE, ''))
        );
        self::assertFalse(
            $strategy->supports(new Origin(OriginInterface::KIND_CONFIG, 'general/store_information/name', ''))
        );
    }

    /**
     * The HTML value is the one an HTML message carries, and it is the one this editor changes.
     *
     * @return void
     */
    public function testTheHtmlValueIsChangedAndSavedThroughTheVariableItself(): void
    {
        $variable = $this->variable([
            'variable_id' => 7,
            'plain_value' => 'Free delivery this week',
            'html_value' => '<b>Free delivery</b> this week',
        ]);
        // Saving through the model rather than through its storage is what makes the check on
        // user-authored HTML run at all, so that the save happened is the assertion.
        $variable->expects(self::once())->method('save');
        $this->variableFactory->method('create')->willReturn($variable);

        $this->strategy()->write($this->entry(), self::STORE_ID, '<b>Free delivery</b> all month');

        self::assertSame('<b>Free delivery</b> all month', $variable->getData('html_value'));
    }

    /**
     * The plain value belongs to plain-text messages and this editor is not editing those, so it is
     * left exactly as it was.
     *
     * @return void
     */
    public function testAPlainValueThatDifferedFromTheHtmlOneIsLeftAlone(): void
    {
        $variable = $this->variable([
            'variable_id' => 7,
            'plain_value' => 'Free delivery this week',
            'html_value' => '<b>Free delivery</b> this week',
        ]);
        $this->variableFactory->method('create')->willReturn($variable);

        $this->strategy()->write($this->entry(), self::STORE_ID, '<b>Free delivery</b> all month');

        self::assertSame('Free delivery this week', $variable->getData('plain_value'));
    }

    /**
     * Two values that were identical were written once and stored twice. Letting them drift apart
     * here would change what HTML readers see while plain-text readers carry on with the old text,
     * from an edit that mentioned only one of them.
     *
     * @return void
     */
    public function testAPlainValueIdenticalToTheHtmlOneIsKeptIdentical(): void
    {
        $variable = $this->variable([
            'variable_id' => 7,
            'plain_value' => 'Free delivery this week',
            'html_value' => 'Free delivery this week',
        ]);
        $this->variableFactory->method('create')->willReturn($variable);

        $this->strategy()->write($this->entry(), self::STORE_ID, 'Free delivery all month');

        self::assertSame('Free delivery all month', $variable->getData('plain_value'));
        self::assertSame('Free delivery all month', $variable->getData('html_value'));
    }

    /**
     * With no HTML value the variable renders its plain value into HTML messages, so the plain value
     * is what HTML readers see today. Setting an HTML value here would move authority from one field
     * to the other and leave plain-text messages on the old text, with nothing reporting it.
     *
     * @return void
     */
    public function testAVariableWithNoHtmlValueRefusesTheChange(): void
    {
        $variable = $this->variable([
            'variable_id' => 7,
            'plain_value' => 'Free delivery this week',
            'html_value' => '',
        ]);
        $variable->expects(self::never())->method('save');
        $this->variableFactory->method('create')->willReturn($variable);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no HTML value');

        $this->strategy()->write($this->entry(), self::STORE_ID, '<b>Free delivery</b> all month');
    }

    /**
     * The check on user-authored HTML is the guard that stops markup Magento's own variable form
     * would have rejected from being stored into every message that names the variable. What it
     * says is passed on as a refusal rather than escaping as a general failure.
     *
     * @return void
     */
    public function testContentTheVariableRejectsBecomesARefusal(): void
    {
        $variable = $this->variable([
            'variable_id' => 7,
            'plain_value' => 'Free delivery',
            'html_value' => '<b>Free delivery</b>',
        ]);
        $variable->method('save')->willThrowException(
            new ValidationException(new Phrase('The script tag is not allowed.'))
        );
        $this->variableFactory->method('create')->willReturn($variable);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('script tag is not allowed');

        $this->strategy()->write($this->entry(), self::STORE_ID, '<script>alert(1)</script>');
    }

    /**
     * A directive naming a code no variable carries renders as nothing at all, so there is nothing
     * to change and nothing is created either.
     *
     * @return void
     */
    public function testACodeNoVariableCarriesIsRefused(): void
    {
        $variable = $this->variable([]);
        $variable->expects(self::never())->method('save');
        $this->variableFactory->method('create')->willReturn($variable);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(self::CODE);

        $this->strategy()->write($this->entry(), self::STORE_ID, 'Anything');
    }

    /**
     * @return void
     */
    public function testAnOriginNamingNoCodeIsRefused(): void
    {
        $this->variableFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);

        $this->strategy()->write($this->entry(''), self::STORE_ID, 'Anything');
    }

    /**
     * The value is loaded and saved for the store view being edited, which is what decides whether a
     * store view gets a value of its own or keeps falling back to the one saved for all of them.
     *
     * @return void
     */
    public function testTheVariableIsLoadedForTheStoreViewBeingEdited(): void
    {
        $variable = $this->variable([
            'variable_id' => 7,
            'plain_value' => 'Free delivery',
            'html_value' => '<b>Free delivery</b>',
        ]);
        $this->variableFactory->method('create')->willReturn($variable);

        $this->strategy()->write($this->entry(), self::STORE_ID, '<b>More</b>');

        self::assertSame(self::STORE_ID, $variable->getStoreId());
    }

    /**
     * @return void
     */
    public function testASuccessfulChangeIsRecorded(): void
    {
        $variable = $this->variable([
            'variable_id' => 7,
            'plain_value' => 'Free delivery',
            'html_value' => '<b>Free delivery</b>',
        ]);
        $this->variableFactory->method('create')->willReturn($variable);

        $this->logger->expects(self::once())->method('info')->with(self::stringContains(self::CODE));

        $this->strategy()->write($this->entry(), self::STORE_ID, '<b>More</b>');
    }

    /**
     * The strategy under test
     *
     * @return CustomVariableValueWriteStrategy
     */
    private function strategy(): CustomVariableValueWriteStrategy
    {
        return new CustomVariableValueWriteStrategy(
            $this->variableFactory,
            $this->authSession,
            $this->logger
        );
    }

    /**
     * A custom variable that answers a load by code with the given stored row
     *
     * @param array<string, mixed> $row Row the load produces, empty when no variable carries the code
     * @return Variable&MockObject
     */
    private function variable(array $row): Variable
    {
        $variable = $this->getMockBuilder(Variable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByCode', 'save', 'getId'])
            ->getMock();

        $variable->method('getId')->willReturn($row['variable_id'] ?? null);
        $variable->method('loadByCode')->willReturnCallback(
            static function () use ($variable, $row): Variable {
                $variable->addData($row);

                return $variable;
            }
        );

        return $variable;
    }

    /**
     * An entry for a custom variable
     *
     * @param string $code Code the origin points at
     * @return VariableKnowledgeInterface
     */
    private function entry(string $code = self::CODE): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('customVar', $code),
            true,
            'Custom variable',
            'Whatever a merchant put there.',
            VariableKnowledgeInterface::OUTPUT_HTML,
            new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, $code, ''),
            [],
            null,
            true
        );
    }
}
