<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Config;

use DOMElement;
use DOMNode;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use InvalidArgumentException;
use Magento\Framework\Config\ConverterInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns the merged email_variables.xml into a plain array keyed by canonical directive reference.
 *
 * The key is produced by the same parser the inspector uses, so an entry written by hand and a
 * directive found in a template arrive at the same key however either of them was spelled - quoted
 * or not, spaced or not.
 *
 * Two things can be wrong with a contribution, and each is handled so that a third party cannot take
 * the editor down and cannot fail silently either. A variable whose kind and expression do not parse
 * is dropped whole and reported: there is no key to file it under, so there is nothing to keep. An
 * affordance that is missing what its kind needs - a link with no route, an instruction with no
 * steps, an inline editor with no input type - is dropped on its own and reported, leaving the
 * description intact; a description without an edit route is still worth reading, while a control
 * that does nothing when it is pressed is not.
 *
 * Prose arrives from XML wrapped over several indented lines. Runs of whitespace are collapsed to
 * single spaces so that what an administrator reads is a sentence rather than the file's layout.
 *
 * The same kind and expression written twice resolves to the last one: the entries are keyed as they
 * are read, so a later one replaces an earlier one. The schema refuses the pair twice within one
 * file, and the merge folds two files' entries for one reference into a single element, so this is
 * the answer to a case those two have already narrowed rather than a policy anybody should rely on.
 */
class Converter implements ConverterInterface
{
    /**
     * Name of the element describing one directive reference
     */
    private const NODE_VARIABLE = 'variable';

    /**
     * Names of the elements a variable is made of
     */
    private const NODE_TITLE = 'title';
    private const NODE_SUMMARY = 'summary';
    private const NODE_ORIGIN = 'origin';
    private const NODE_AFFORDANCE = 'affordance';
    private const NODE_CAVEAT = 'caveat';
    private const NODE_PARAM = 'param';
    private const NODE_FRAGMENT = 'fragment';
    private const NODE_STEP = 'step';
    private const NODE_OPTION = 'option';

    /**
     * The output kind assumed when an entry does not name one
     *
     * The conservative answer: an entry must not be the reason a value starts being treated as
     * markup.
     */
    private const DEFAULT_OUTPUT_KIND = VariableKnowledgeInterface::OUTPUT_TEXT;

    /**
     * @param DirectiveReferenceParserInterface $referenceParser Produces the canonical key an entry
     *        is filed under, and refuses a kind or an expression that could never be looked up
     * @param LoggerInterface $logger Where a refused contribution is reported
     */
    public function __construct(
        private readonly DirectiveReferenceParserInterface $referenceParser,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Convert the merged configuration document
     *
     * @param \DOMDocument $source Merged email_variables.xml
     * @return array<string, array<string, mixed>> Entries keyed by canonical directive reference
     */
    public function convert($source): array
    {
        $entries = [];
        $root = $source->documentElement;

        if ($root === null) {
            return $entries;
        }

        foreach ($this->childElements($root, self::NODE_VARIABLE) as $node) {
            $entry = $this->convertVariable($node);

            if ($entry !== null) {
                $entries[$entry['reference']] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Convert one variable element, or report and drop it
     *
     * @param DOMElement $node The variable element
     * @return array<string, mixed>|null Null when the element could not be filed under a key
     */
    private function convertVariable(DOMElement $node): ?array
    {
        $kind = $node->getAttribute('kind');
        $expression = $node->getAttribute('expression');

        try {
            $reference = $this->referenceParser->create($kind, $expression);
        } catch (InvalidArgumentException $exception) {
            $this->logger->error(
                sprintf(
                    'A contributed email variable entry names a directive that cannot be looked up '
                    . 'and was skipped: kind "%s", expression "%s". %s',
                    $kind,
                    $expression,
                    $exception->getMessage()
                )
            );

            return null;
        }

        $outputKind = $node->getAttribute('outputKind');

        return [
            'reference' => $reference->toCanonicalString(),
            'kind' => $reference->getKind(),
            'expression' => $reference->getExpression(),
            'outputKind' => $outputKind === '' ? self::DEFAULT_OUTPUT_KIND : $outputKind,
            'title' => $this->firstChildText($node, self::NODE_TITLE),
            'summary' => $this->firstChildText($node, self::NODE_SUMMARY),
            'origin' => $this->convertOrigin($node),
            'affordance' => $this->convertAffordance($node, $reference->toCanonicalString()),
            'caveats' => $this->childTexts($node, self::NODE_CAVEAT),
        ];
    }

    /**
     * Convert the origin of a variable
     *
     * @param DOMElement $node The variable element
     * @return array{kind: string, locator: string, explanation: string}
     */
    private function convertOrigin(DOMElement $node): array
    {
        $origin = $this->firstChildElement($node, self::NODE_ORIGIN);

        if ($origin === null) {
            return ['kind' => '', 'locator' => '', 'explanation' => ''];
        }

        return [
            'kind' => $origin->getAttribute('kind'),
            'locator' => $origin->getAttribute('locator'),
            'explanation' => $this->normaliseText($origin->textContent),
        ];
    }

    /**
     * Convert the affordance of a variable, or report and drop it
     *
     * @param DOMElement $node The variable element
     * @param string $reference Canonical reference, so a report names the entry it came from
     * @return array<string, mixed>|null Null when there is no affordance, or none that could be built
     */
    private function convertAffordance(DOMElement $node, string $reference): ?array
    {
        $element = $this->firstChildElement($node, self::NODE_AFFORDANCE);

        if ($element === null) {
            return null;
        }

        $affordance = [
            'kind' => $element->getAttribute('kind'),
            'label' => $this->normaliseText($element->getAttribute('label')),
            'route' => trim($element->getAttribute('route')),
            'params' => $this->convertParams($element),
            'fragment' => trim($this->firstChildText($element, self::NODE_FRAGMENT)),
            'scopeAware' => filter_var($element->getAttribute('scopeAware'), FILTER_VALIDATE_BOOLEAN),
            'steps' => $this->childTexts($element, self::NODE_STEP),
            'editorType' => $element->getAttribute('editorType'),
            'options' => $this->convertOptions($element),
        ];

        $refusal = $this->affordanceRefusal($affordance);

        if ($refusal !== null) {
            $this->logger->error(
                sprintf(
                    'The edit route contributed for the email variable entry "%s" was skipped because '
                    . '%s. The entry is still described, without a way to change the value.',
                    $reference,
                    $refusal
                )
            );

            return null;
        }

        return $affordance;
    }

    /**
     * Why an affordance cannot be offered, or null when it can
     *
     * Which parts an affordance needs follows from its kind, and the check for each kind is looked up
     * rather than decided by a conditional over the kind, so a kind added later is a new row here.
     * The schema cannot state these rules: they are conditional on an attribute's value, which needs
     * assertions, and the validator this configuration is checked with implements XSD 1.0.
     *
     * @param array<string, mixed> $affordance Affordance as read from the document
     * @return string|null A reason, phrased to complete "was skipped because ..."
     */
    private function affordanceRefusal(array $affordance): ?string
    {
        $checks = [
            EditAffordanceInterface::KIND_LINK => static fn (array $candidate): ?string =>
                $candidate['route'] === '' ? 'a link has to name the route it opens' : null,
            EditAffordanceInterface::KIND_INLINE => static function (array $candidate): ?string {
                if ($candidate['editorType'] === '') {
                    return 'an editor has to name the kind of input it asks for';
                }

                if ($candidate['editorType'] === EditAffordanceInterface::EDITOR_SELECT
                    && $candidate['options'] === []
                ) {
                    return 'an editor offering a choice has to carry the choices to offer';
                }

                return null;
            },
            EditAffordanceInterface::KIND_INSTRUCTION => static fn (array $candidate): ?string =>
                $candidate['steps'] === [] ? 'instructions have to carry at least one step' : null,
            EditAffordanceInterface::KIND_NONE => static fn (array $candidate): ?string => null,
        ];

        $check = $checks[$affordance['kind']] ?? null;

        if ($check === null) {
            return sprintf('"%s" is not one of the four things that can be offered', $affordance['kind']);
        }

        if ($affordance['label'] === '') {
            return 'nothing was written on it for an administrator to read';
        }

        return $check($affordance);
    }

    /**
     * Convert the parameters a link affordance passes to its route
     *
     * @param DOMElement $affordance The affordance element
     * @return array<string, string> Parameter name to value
     */
    private function convertParams(DOMElement $affordance): array
    {
        $params = [];

        foreach ($this->childElements($affordance, self::NODE_PARAM) as $param) {
            $name = trim($param->getAttribute('name'));

            if ($name !== '') {
                $params[$name] = trim($param->textContent);
            }
        }

        return $params;
    }

    /**
     * Convert the choices an inline select editor offers
     *
     * @param DOMElement $affordance The affordance element
     * @return array<int, array{value: string, label: string}>
     */
    private function convertOptions(DOMElement $affordance): array
    {
        $options = [];

        foreach ($this->childElements($affordance, self::NODE_OPTION) as $option) {
            $options[] = [
                'value' => trim($option->getAttribute('value')),
                'label' => $this->normaliseText($option->textContent),
            ];
        }

        return $options;
    }

    /**
     * The text of the first child element of the given name, or an empty string
     *
     * @param DOMElement $parent Element to look under
     * @param string $name Element name
     * @return string
     */
    private function firstChildText(DOMElement $parent, string $name): string
    {
        $child = $this->firstChildElement($parent, $name);

        return $child === null ? '' : $this->normaliseText($child->textContent);
    }

    /**
     * The text of every child element of the given name, in document order
     *
     * Empty ones are left out: a blank step or a blank caveat is a gap in the content, and showing it
     * as an empty bullet would read as a broken page rather than as something missing.
     *
     * @param DOMElement $parent Element to look under
     * @param string $name Element name
     * @return string[]
     */
    private function childTexts(DOMElement $parent, string $name): array
    {
        $texts = [];

        foreach ($this->childElements($parent, $name) as $child) {
            $text = $this->normaliseText($child->textContent);

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * The first child element of the given name, or null
     *
     * @param DOMElement $parent Element to look under
     * @param string $name Element name
     * @return DOMElement|null
     */
    private function firstChildElement(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($this->childElements($parent, $name) as $child) {
            return $child;
        }

        return null;
    }

    /**
     * Every direct child element of the given name
     *
     * Direct children only. A nested document would otherwise let a step inside an affordance be read
     * as a caveat of the variable around it.
     *
     * @param DOMElement $parent Element to look under
     * @param string $name Element name
     * @return DOMElement[]
     */
    private function childElements(DOMElement $parent, string $name): array
    {
        $elements = [];

        /** @var DOMNode $child */
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * Collapse the layout of the file out of a piece of prose
     *
     * @param string $text Text as written in the document
     * @return string
     */
    private function normaliseText(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}
