<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EditAffordanceTest extends TestCase
{
    public function testALinkCarriesItsTargetAndNothingElse(): void
    {
        $affordance = EditAffordance::link('Open Store Information', 'https://example.test/admin/config');

        self::assertSame(EditAffordanceInterface::KIND_LINK, $affordance->getKind());
        self::assertSame('Open Store Information', $affordance->getLabel());
        self::assertSame('https://example.test/admin/config', $affordance->getUrl());
        self::assertSame([], $affordance->getSteps());
        self::assertNull($affordance->getEditorType());
        self::assertSame([], $affordance->getEditorOptions());
    }

    public function testAnInlineEditorCarriesItsInputTypeAndNoUrl(): void
    {
        $affordance = EditAffordance::inline('Store name', EditAffordanceInterface::EDITOR_TEXT);

        self::assertSame(EditAffordanceInterface::KIND_INLINE, $affordance->getKind());
        self::assertSame(EditAffordanceInterface::EDITOR_TEXT, $affordance->getEditorType());
        self::assertNull($affordance->getUrl());
        self::assertSame([], $affordance->getSteps());
    }

    public function testAnInlineSelectCarriesItsChoices(): void
    {
        $options = [['value' => 'de', 'label' => 'Germany'], ['value' => 'ua', 'label' => 'Ukraine']];

        $affordance = EditAffordance::inline('Country', EditAffordanceInterface::EDITOR_SELECT, $options);

        self::assertSame($options, $affordance->getEditorOptions());
    }

    /**
     * Editing one value in place does not reach everything an administrator may need: the page that
     * owns the value shows the rest of it and the scopes it is stored in, so an inline editor may
     * offer that page alongside its input.
     *
     * @return void
     */
    public function testAnInlineEditorMayAlsoOfferThePageOwningTheValue(): void
    {
        $affordance = EditAffordance::inline(
            'Store name',
            EditAffordanceInterface::EDITOR_TEXT,
            [],
            'https://example.test/admin/config'
        );

        self::assertSame(EditAffordanceInterface::KIND_INLINE, $affordance->getKind());
        self::assertSame(EditAffordanceInterface::EDITOR_TEXT, $affordance->getEditorType());
        self::assertSame('https://example.test/admin/config', $affordance->getUrl());
    }

    public function testAnInlineEditorOfferingAPageWithoutATargetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry its URL');

        EditAffordance::inline('Store name', EditAffordanceInterface::EDITOR_TEXT, [], '   ');
    }

    public function testInstructionStepsKeepTheirOrderAndAreRenumbered(): void
    {
        $affordance = EditAffordance::instruction('How to change this', [5 => 'First', 9 => 'Second']);

        self::assertSame(EditAffordanceInterface::KIND_INSTRUCTION, $affordance->getKind());
        self::assertSame(['First', 'Second'], $affordance->getSteps());
        self::assertNull($affordance->getUrl());
        self::assertNull($affordance->getEditorType());
    }

    public function testNothingToDoStillSaysSomething(): void
    {
        $affordance = EditAffordance::none('This value cannot be changed from the admin.');

        self::assertSame(EditAffordanceInterface::KIND_NONE, $affordance->getKind());
        self::assertSame('This value cannot be changed from the admin.', $affordance->getLabel());
        self::assertNull($affordance->getUrl());
        self::assertSame([], $affordance->getSteps());
    }

    /**
     * A link with no target renders as a button that does nothing when it is pressed, which is worse
     * than offering no link at all.
     *
     * @return void
     */
    public function testALinkWithoutATargetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('target URL');

        EditAffordance::link('Open Store Information', '   ');
    }

    public function testAnInlineEditorWithoutAKnownInputTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('input types');

        EditAffordance::inline('Store name', '');
    }

    public function testAnInlineEditorAskingForAnUnpublishedInputTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EditAffordance::inline('Store name', 'wysiwyg');
    }

    /**
     * A choice with nothing to choose from is an empty dropdown, and an administrator cannot tell it
     * apart from one whose options failed to load.
     *
     * @return void
     */
    public function testAnInlineSelectWithoutChoicesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('choices');

        EditAffordance::inline('Country', EditAffordanceInterface::EDITOR_SELECT);
    }

    public function testInstructionsWithoutStepsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one step');

        EditAffordance::instruction('How to change this', []);
    }

    /**
     * @dataProvider unlabelledAffordanceProvider
     *
     * @param callable():EditAffordance $build Attempt to build an affordance with no label
     * @return void
     */
    public function testEveryKindRefusesAnEmptyLabel(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a label');

        $build();
    }

    /**
     * @return array<string, array{0: callable():EditAffordance}>
     */
    public function unlabelledAffordanceProvider(): array
    {
        return [
            'link' => [static fn (): EditAffordance => EditAffordance::link('', 'https://example.test')],
            'inline' => [
                static fn (): EditAffordance => EditAffordance::inline('', EditAffordanceInterface::EDITOR_TEXT),
            ],
            'instruction' => [static fn (): EditAffordance => EditAffordance::instruction(' ', ['First'])],
            'none' => [static fn (): EditAffordance => EditAffordance::none('')],
        ];
    }

    /**
     * The named constructors are the only way in, so no caller can assemble a kind out of fields that
     * do not belong together.
     *
     * @return void
     */
    public function testTheConstructorIsNotReachableFromOutside(): void
    {
        $constructor = (new \ReflectionClass(EditAffordance::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
