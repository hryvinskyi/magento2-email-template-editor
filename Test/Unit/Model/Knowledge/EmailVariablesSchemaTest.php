<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use Hryvinskyi\EmailTemplateEditor\Test\Unit\Container\ReadsEmailVariablesConfiguration;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * What a module may write in an email_variables.xml, as an installation reads it.
 *
 * A schema cannot read a PHP constant, so every set the PHP side publishes - directive kinds, output
 * kinds, origin kinds, affordance kinds, inline editor types - is stated a second time as an
 * enumeration in the schema. Each value PHP publishes is therefore written into a contribution here
 * and read through the module's reader and schema: one the schema has fallen behind on refuses a
 * perfectly good contribution, and fails by name. A value nobody publishes is written as well, and
 * has to be refused, so the schema is proved to enumerate at all.
 */
class EmailVariablesSchemaTest extends TestCase
{
    use ReadsEmailVariablesConfiguration;

    /**
     * What a contribution has to write for an affordance of each kind to be built
     *
     * @var array<string, string>
     */
    private const AFFORDANCE_BODY = [
        EditAffordanceInterface::KIND_LINK => ' route="adminhtml/system_config/edit">',
        EditAffordanceInterface::KIND_INLINE => ' editorType="text">',
        EditAffordanceInterface::KIND_INSTRUCTION => '><step id="one">Do this.</step>',
        EditAffordanceInterface::KIND_NONE => '>',
    ];

    public function testEveryDirectiveKindThePhpSidePublishesCanBeDescribed(): void
    {
        $variables = '';

        foreach (array_keys(DirectiveReferenceParser::KINDS) as $kind) {
            $variables .= $this->variable($kind, '', 'text', 'computed', '');
        }

        $knowledge = $this->accepted($variables);

        foreach (array_keys(DirectiveReferenceParser::KINDS) as $kind) {
            self::assertNotNull(
                $knowledge->describe(new DirectiveReference($kind, ''), 0),
                sprintf('A contribution describing the {{%s}} directive was not filed.', $kind)
            );
        }
    }

    public function testEveryOutputKindThePhpSidePublishesCanBeDeclared(): void
    {
        $kinds = $this->constants(VariableKnowledgeInterface::class, 'OUTPUT_');
        $variables = '';

        foreach ($kinds as $kind) {
            $variables .= $this->variable('var', 'fixture.' . $kind, $kind, 'computed', '');
        }

        $entries = $this->entriesByExpression($this->accepted($variables));

        foreach ($kinds as $kind) {
            self::assertSame($kind, $entries['fixture.' . $kind]->getOutputKind());
        }
    }

    public function testEveryOriginKindThePhpSidePublishesCanBeDeclared(): void
    {
        $kinds = $this->constants(OriginInterface::class, 'KIND_');
        $variables = '';

        foreach ($kinds as $kind) {
            $variables .= $this->variable('var', 'fixture.' . $kind, 'text', $kind, '');
        }

        $entries = $this->entriesByExpression($this->accepted($variables));

        foreach ($kinds as $kind) {
            self::assertSame($kind, $entries['fixture.' . $kind]->getOrigin()->getKind());
        }
    }

    public function testEveryAffordanceKindThePhpSidePublishesCanBeOffered(): void
    {
        $kinds = $this->constants(EditAffordanceInterface::class, 'KIND_');
        $variables = '';

        foreach ($kinds as $kind) {
            self::assertArrayHasKey(
                $kind,
                self::AFFORDANCE_BODY,
                sprintf('Say here what a contribution writes to offer a "%s" affordance.', $kind)
            );

            $variables .= $this->variable(
                'var',
                'fixture.' . $kind,
                'text',
                'computed',
                sprintf('<affordance kind="%s" label="Change it"', $kind)
                . self::AFFORDANCE_BODY[$kind] . '</affordance>'
            );
        }

        $entries = $this->entriesByExpression($this->accepted($variables));

        foreach ($kinds as $kind) {
            self::assertSame($kind, $entries['fixture.' . $kind]->getAffordance()?->getKind());
        }
    }

    public function testEveryInlineEditorTypeThePhpSidePublishesCanBeOffered(): void
    {
        $types = $this->constants(EditAffordanceInterface::class, 'EDITOR_');
        $variables = '';

        foreach ($types as $type) {
            $variables .= $this->variable(
                'var',
                'fixture.' . $type,
                'text',
                'computed',
                sprintf(
                    '<affordance kind="inline" label="Change it" editorType="%s">'
                    . '<option value="a">A</option></affordance>',
                    $type
                )
            );
        }

        $entries = $this->entriesByExpression($this->accepted($variables));

        foreach ($types as $type) {
            self::assertSame($type, $entries['fixture.' . $type]->getAffordance()?->getEditorType());
        }
    }

    /**
     * @dataProvider unpublishedValueProvider
     * @param string $variable A variable naming a value no PHP set publishes
     * @return void
     */
    public function testAValueNobodyPublishesIsRefused(string $variable): void
    {
        $this->expectException(LocalizedException::class);

        $this->knowledgeFrom(
            ['fixture/email_variables.xml' => '<variables>' . $variable . '</variables>'],
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unpublishedValueProvider(): array
    {
        $origin = '<title>T</title><summary>S</summary>';

        return [
            'directive kind' => ['<variable kind="banana" expression="">' . $origin
                . '<origin kind="computed">E</origin></variable>'],
            'output kind' => ['<variable kind="var" expression="x" outputKind="banana">' . $origin
                . '<origin kind="computed">E</origin></variable>'],
            'origin kind' => ['<variable kind="var" expression="x">' . $origin
                . '<origin kind="banana">E</origin></variable>'],
            'affordance kind' => ['<variable kind="var" expression="x">' . $origin
                . '<origin kind="computed">E</origin><affordance kind="banana" label="L"/></variable>'],
            'inline editor type' => ['<variable kind="var" expression="x">' . $origin
                . '<origin kind="computed">E</origin><affordance kind="inline" label="L" editorType="banana"/>'
                . '</variable>'],
        ];
    }

    /**
     * Read a contribution of the given variables, which nothing may refuse
     *
     * @param string $variables Variable elements
     * @return VariableKnowledgeProviderInterface
     */
    private function accepted(string $variables): VariableKnowledgeProviderInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        return $this->knowledgeFrom(
            ['fixture/email_variables.xml' => '<variables>' . $variables . '</variables>'],
            $logger
        );
    }

    /**
     * Every entry the knowledge answers with, keyed by its expression
     *
     * @param VariableKnowledgeProviderInterface $knowledge
     * @return array<string, VariableKnowledgeInterface>
     */
    private function entriesByExpression(VariableKnowledgeProviderInterface $knowledge): array
    {
        $entries = [];

        foreach ($knowledge->listAll(0) as $entry) {
            $entries[$entry->getReference()->getExpression()] = $entry;
        }

        return $entries;
    }

    /**
     * One variable element, complete as the schema requires
     *
     * @param string $kind Directive kind
     * @param string $expression Directive expression
     * @param string $outputKind Output kind
     * @param string $originKind Origin kind
     * @param string $affordance Affordance element, or an empty string for none
     * @return string
     */
    private function variable(
        string $kind,
        string $expression,
        string $outputKind,
        string $originKind,
        string $affordance
    ): string {
        return sprintf(
            '<variable kind="%s" expression="%s" outputKind="%s"><title>Fixture</title>'
            . '<summary>A fixture entry.</summary><origin kind="%s">How it is made.</origin>%s</variable>',
            $kind,
            $expression,
            $outputKind,
            $originKind,
            $affordance
        );
    }

    /**
     * The values of an interface's constants whose names share a prefix
     *
     * @param class-string $interface Interface publishing the set
     * @param string $prefix Constant name prefix marking membership of the set
     * @return string[]
     */
    private function constants(string $interface, string $prefix): array
    {
        $values = [];

        foreach ((new ReflectionClass($interface))->getConstants() as $name => $value) {
            if (str_starts_with($name, $prefix) && is_string($value)) {
                $values[] = $value;
            }
        }

        self::assertNotEmpty($values, sprintf('%s publishes no %s constants.', $interface, $prefix));

        return $values;
    }
}
