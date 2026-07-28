<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use DOMDocument;
use DOMXPath;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use LibXMLError;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Keeps the shipped knowledge file and its schema honest.
 *
 * Two things are checked. The file this module ships really does satisfy the schema every
 * contribution is checked against - a schema nobody's own file obeys would be one nobody trusts. And
 * every enumeration in that schema still lists exactly what PHP publishes: a schema cannot read a
 * PHP constant, so the sets are written twice, and without this the two would drift until a
 * perfectly good contribution was refused for naming something the code has accepted all along.
 */
class EmailVariablesSchemaTest extends TestCase
{
    /**
     * Namespace prefix bound to the XML Schema namespace for the queries below
     */
    private const XSD_PREFIX = 'xs';

    /**
     * The XML Schema namespace
     */
    private const XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';

    public function testTheShippedKnowledgeFileSatisfiesItsOwnSchema(): void
    {
        $document = new DOMDocument();
        $document->load($this->path('email_variables.xml'));

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $valid = $document->schemaValidate($this->path('email_variables.xsd'));
        $messages = array_map(
            static fn (LibXMLError $error): string => sprintf(
                'line %d: %s',
                $error->line,
                trim($error->message)
            ),
            libxml_get_errors()
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode(PHP_EOL, $messages));
    }

    /**
     * @dataProvider enumerationProvider
     * @param string $simpleType Name of the schema's simple type
     * @param string[] $published The set PHP publishes
     * @return void
     */
    public function testAnEnumerationInTheSchemaListsWhatThePhpSidePublishes(
        string $simpleType,
        array $published
    ): void {
        self::assertSame($published, $this->enumeration($simpleType));
    }

    /**
     * @return array<string, array{string, string[]}>
     */
    public static function enumerationProvider(): array
    {
        return [
            'directive kinds' => [
                'directiveKindType',
                self::sorted(array_keys(DirectiveReferenceParser::KINDS)),
            ],
            'output kinds' => [
                'outputKindType',
                self::constants(VariableKnowledgeInterface::class, 'OUTPUT_'),
            ],
            'origin kinds' => [
                'originKindType',
                self::constants(OriginInterface::class, 'KIND_'),
            ],
            'affordance kinds' => [
                'affordanceKindType',
                self::constants(EditAffordanceInterface::class, 'KIND_'),
            ],
            'inline editor types' => [
                'editorTypeType',
                self::constants(EditAffordanceInterface::class, 'EDITOR_'),
            ],
        ];
    }

    /**
     * The values a named simple type in the schema enumerates
     *
     * @param string $simpleType Name of the simple type
     * @return string[] Sorted, so the comparison is about the set and not about the order
     */
    private function enumeration(string $simpleType): array
    {
        $document = new DOMDocument();
        $document->load($this->path('email_variables.xsd'));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace(self::XSD_PREFIX, self::XSD_NAMESPACE);

        $nodes = $xpath->query(
            sprintf(
                '//%1$s:simpleType[@name="%2$s"]/%1$s:restriction/%1$s:enumeration/@value',
                self::XSD_PREFIX,
                $simpleType
            )
        );

        self::assertNotFalse($nodes, sprintf('The schema declares no simple type named "%s".', $simpleType));

        $values = [];

        foreach ($nodes as $node) {
            $values[] = $node->nodeValue ?? '';
        }

        return self::sorted($values);
    }

    /**
     * The values of an interface's constants whose names share a prefix
     *
     * @param string $interface Interface publishing the set
     * @param string $prefix Constant name prefix marking membership of the set
     * @return string[] Sorted
     */
    private static function constants(string $interface, string $prefix): array
    {
        $values = [];

        foreach ((new ReflectionClass($interface))->getConstants() as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $values[] = (string)$value;
            }
        }

        return self::sorted($values);
    }

    /**
     * @param string[] $values Values to sort
     * @return string[]
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * Absolute path to a file in the module's etc directory
     *
     * @param string $fileName Name of the file
     * @return string
     */
    private function path(string $fileName): string
    {
        return dirname(__DIR__, 4) . '/etc/' . $fileName;
    }
}
