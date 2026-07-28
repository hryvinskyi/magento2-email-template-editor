<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ModifierDescriptor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ModifierDescriptorTest extends TestCase
{
    public function testADescriptorPublishesWhatItWasDeclaredWith(): void
    {
        $descriptor = new ModifierDescriptor('nl2br', 'Newlines', 'Turns newlines into line breaks.');

        self::assertSame('nl2br', $descriptor->getName());
        self::assertSame('Newlines', $descriptor->getLabel());
        self::assertSame('Turns newlines into line breaks.', $descriptor->getDescription());
        self::assertTrue($descriptor->isImplemented());
        self::assertSame([], $descriptor->getArgumentSpec());
    }

    /**
     * A name is published for a chain to be written with, and the filter matches a chain entry by
     * exact string, so nothing about the spelling may be tidied up on the way through.
     *
     * @return void
     */
    public function testTheNameIsPublishedExactlyAsDeclared(): void
    {
        self::assertSame('Escape', (new ModifierDescriptor('Escape', 'Escape', ''))->getName());
    }

    /**
     * The specification is declared as a keyed configuration array and published as a positional
     * one, so both levels of keys are dropped rather than travelling to a consumer that would then
     * have to know how the wiring happened to be written.
     *
     * @return void
     */
    public function testTheArgumentSpecificationIsPublishedAsPositionalLists(): void
    {
        $descriptor = new ModifierDescriptor(
            'escape',
            'Escape',
            '',
            true,
            [
                'type' => [
                    'name' => 'type',
                    'options' => ['html' => 'html', 'url' => 'url'],
                    'default' => 'html',
                ],
            ]
        );

        self::assertSame(
            [['name' => 'type', 'options' => ['html', 'url'], 'default' => 'html']],
            $descriptor->getArgumentSpec()
        );
    }

    public function testADescriptorWithoutANameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry the name it describes');

        new ModifierDescriptor('  ', 'Escape', '');
    }

    public function testADescriptorWithoutALabelIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a label');

        new ModifierDescriptor('escape', '', '');
    }

    public function testAnArgumentMissingPartOfItsSpecificationIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare a name');

        new ModifierDescriptor('escape', 'Escape', '', true, [['name' => 'type']]);
    }

    /**
     * The filter compares an argument value exactly and treats a spelling it does not know as no
     * reason to stop, so a default outside the offered options would be pre-selected in the editor
     * and would then do nothing at all when the message is rendered. That is worth refusing where it
     * is declared, since there is no later point at which it is visible.
     *
     * @return void
     */
    public function testADefaultThatIsNotOneOfTheOptionsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not one of the options');

        new ModifierDescriptor(
            'escape',
            'Escape',
            '',
            true,
            [['name' => 'type', 'options' => ['html', 'htmlentities', 'url'], 'default' => 'HTML']]
        );
    }

    /**
     * An empty option list is how an argument states that the filter constrains nothing there, so
     * there is no set for the default to be checked against.
     *
     * @return void
     */
    public function testAnArgumentWithoutFixedOptionsAcceptsAnyDefault(): void
    {
        $descriptor = new ModifierDescriptor(
            'date',
            'Date',
            '',
            true,
            [['name' => 'format', 'options' => [], 'default' => 'Y-m-d']]
        );

        self::assertSame(
            [['name' => 'format', 'options' => [], 'default' => 'Y-m-d']],
            $descriptor->getArgumentSpec()
        );
    }
}
