<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DescribeContextInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVariableDeclarationsInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use InvalidArgumentException;

/**
 * The safety net: a label for every variable the template itself declares.
 *
 * It is asked last of all the sources, so anything written by hand or worked out from what Magento
 * knows answers first and this only fills the gaps. What it has to offer is thin - a template's
 * declaration records a label and nothing else - but a label plus an honest statement of where the
 * value comes from beats no entry at all for the variables that are peculiar to one template family.
 *
 * Only plain variable directives are answered. The declarations also carry entries for directives of
 * other kinds, and turning those into references would mean guessing which of their parameters
 * identifies them; a guess that is wrong produces a key nothing ever asks about, so those are left
 * to the sources that do know.
 */
class TemplateVarsKnowledgeProvider implements VariableKnowledgeProviderInterface
{
    /**
     * The directive kind this provider answers for
     */
    private const REFERENCE_KIND = 'var';

    /**
     * What a variable directive is formatted with when it carries no modifier chain
     *
     * An email template's variable directive is expanded through the legacy path, where the whole
     * interior is split on the first pipe and the fallback formatting is applied only when there is
     * no pipe to split on. So a directive with no chain is escaped, and a chain editor that showed
     * it as unformatted would invite an administrator to add protection that is already there - or
     * to strip a chain believing the output would stay as it is.
     */
    private const DEFAULT_MODIFIER = 'escape';

    /**
     * Where a declared directive's expression ends and its modifier chain begins
     *
     * A template declares its variables with their formatting attached - the sales templates declare
     * an address as `var formattedShippingAddress|raw` - while the same variable written in the
     * content is read up to that pipe and no further, because that is where the filter splits it. A
     * declaration keyed with its chain attached is therefore a key nothing ever asks about, and the
     * label it carries would be lost for exactly the variables templates bother to format.
     */
    private const MODIFIER_SEPARATOR = '|';

    /**
     * Declared variables of a template as canonical references mapped to labels, keyed by template
     *
     * @var array<string, array<string, string>>
     */
    private array $declaredByTemplate = [];

    /**
     * @param TemplateVariableDeclarationsInterface $declarations Reads what a template declares
     * @param DirectiveReferenceParserInterface $referenceParser Turns a declaration into a reference
     * @param DescribeContextInterface $describeContext Says which template is being described
     */
    public function __construct(
        private readonly TemplateVariableDeclarationsInterface $declarations,
        private readonly DirectiveReferenceParserInterface $referenceParser,
        private readonly DescribeContextInterface $describeContext
    ) {
    }

    /**
     * @inheritDoc
     */
    public function describe(DirectiveReferenceInterface $reference, int $storeId): ?VariableKnowledgeInterface
    {
        if ($reference->getKind() !== self::REFERENCE_KIND) {
            return null;
        }

        $templateId = $this->describeContext->getTemplateId();
        $declared = $this->declaredVariables($templateId);
        $canonical = $reference->toCanonicalString();

        if (!isset($declared[$canonical])) {
            return null;
        }

        return $this->buildEntry($reference, $declared[$canonical], $templateId);
    }

    /**
     * @inheritDoc
     */
    public function listAll(int $storeId): array
    {
        $templateId = $this->describeContext->getTemplateId();
        $entries = [];

        foreach ($this->declaredVariables($templateId) as $canonical => $label) {
            $entries[] = $this->buildEntry($this->referenceParser->parse($canonical), $label, $templateId);
        }

        return $entries;
    }

    /**
     * The variable declarations of one template, as canonical references mapped to labels
     *
     * A description request asks about many directives and each one would otherwise re-derive the
     * same references from the same declarations, so the derived map is kept for the request. An
     * empty template identifier means nothing is being described, and then this provider knows
     * nothing rather than answering out of whichever template was described last.
     *
     * @param string $templateId Identifier of the template being described, empty when there is none
     * @return array<string, string> Canonical reference mapped to the declared label
     */
    private function declaredVariables(string $templateId): array
    {
        if ($templateId === '') {
            return [];
        }

        if (!isset($this->declaredByTemplate[$templateId])) {
            $this->declaredByTemplate[$templateId] = $this->deriveReferences($templateId);
        }

        return $this->declaredByTemplate[$templateId];
    }

    /**
     * Turn a template's declarations into canonical references
     *
     * @param string $templateId Identifier of the template to read
     * @return array<string, string> Canonical reference mapped to the declared label
     */
    private function deriveReferences(string $templateId): array
    {
        $references = [];

        foreach ($this->declarations->getDeclarations($templateId) as $directive => $label) {
            $reference = $this->toReference($directive);

            if ($reference === null) {
                continue;
            }

            $references[$reference->toCanonicalString()] = $label;
        }

        return $references;
    }

    /**
     * Read one declaration as a reference, or null when it is not one this provider answers for
     *
     * A declaration is a directive without its braces: the kind, then the rest, and for a variable
     * the rest may carry the formatting it is normally written with. Running what is left after the
     * chain through the same parser the browser's directives go through is what makes a declaration
     * and an identical directive in the content resolve to one entry rather than two.
     *
     * @param string $directive Declared directive without its braces
     * @return DirectiveReferenceInterface|null
     */
    private function toReference(string $directive): ?DirectiveReferenceInterface
    {
        $parts = preg_split('/\s+/', trim($directive), 2);

        if ($parts === false || $parts[0] === '') {
            return null;
        }

        try {
            $reference = $this->referenceParser->create($parts[0], $this->withoutChain($parts[1] ?? ''));
        } catch (InvalidArgumentException $e) {
            // A declaration naming a kind the grammar does not publish, or carrying a character a
            // reference may not hold. Nothing can point at it, so there is nothing to describe.
            return null;
        }

        if ($reference->getKind() !== self::REFERENCE_KIND || $reference->getExpression() === '') {
            return null;
        }

        return $reference;
    }

    /**
     * A declared directive's interior with any formatting taken off the end
     *
     * @param string $interior Everything the declaration wrote after the kind
     * @return string The part that says what the directive points at
     */
    private function withoutChain(string $interior): string
    {
        $expression = strstr($interior, self::MODIFIER_SEPARATOR, true);

        return $expression === false ? $interior : $expression;
    }

    /**
     * Build the entry for one declared variable
     *
     * @param DirectiveReferenceInterface $reference Reference the entry describes
     * @param string $label Label the template declared for it
     * @param string $templateId Identifier of the template that declared it
     * @return VariableKnowledgeInterface
     */
    private function buildEntry(
        DirectiveReferenceInterface $reference,
        string $label,
        string $templateId
    ): VariableKnowledgeInterface {
        return new VariableKnowledge(
            $reference,
            true,
            $label !== '' ? $label : $reference->getExpression(),
            (string)__(
                'A variable the code sending this message hands to the template. It is declared by '
                . 'this template family and has a value only in the messages that code sends.'
            ),
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(
                OriginInterface::KIND_TEMPLATE_VAR,
                $templateId,
                (string)__(
                    'The code that sends this message assigns the variable to the template just before '
                    . 'it is rendered, from the record the message is about. No setting holds it, and '
                    . 'a template that is not sent by that code renders it as nothing at all.'
                )
            ),
            [
                // Saying where the description came from is the point: it is the difference between
                // "this is what the value contains" and "this is the name the template gave it".
                (string)__(
                    'This description comes from the list of variables the template declares about '
                    . 'itself, which records a name and nothing more. What the value actually '
                    . 'contains is not recorded there.'
                ),
            ],
            self::DEFAULT_MODIFIER,
            false
        );
    }
}
