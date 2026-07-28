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
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\CustomVariableIndexInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use InvalidArgumentException;

/**
 * Describes the merchant-defined custom variables, and only those.
 *
 * A code nothing defines is answered with null rather than with an entry. The base then says the
 * directive is not documented, which is exactly right: an administrator looking at a directive that
 * renders as nothing needs to learn that no variable carries that code, and an entry describing "the
 * custom variable my_code" would state the opposite.
 */
class CustomVariableKnowledgeProvider implements VariableKnowledgeProviderInterface
{
    /**
     * The directive kind this provider is the authority on
     *
     * Written the way the reference grammar publishes it, which is also the spelling a parsed
     * reference always carries whatever case the directive was written in.
     */
    private const REFERENCE_KIND = 'customVar';

    /**
     * @param CustomVariableIndexInterface $customVariableIndex The installation's custom variables by code
     * @param DirectiveReferenceParserInterface $referenceParser Builds references for the listed variables
     */
    public function __construct(
        private readonly CustomVariableIndexInterface $customVariableIndex,
        private readonly DirectiveReferenceParserInterface $referenceParser
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

        $variable = $this->customVariableIndex->find($reference->getExpression());

        if ($variable === null) {
            return null;
        }

        return $this->buildEntry($reference, $variable);
    }

    /**
     * @inheritDoc
     */
    public function listAll(int $storeId): array
    {
        $entries = [];

        foreach ($this->customVariableIndex->getAll() as $variable) {
            try {
                $reference = $this->referenceParser->create(self::REFERENCE_KIND, $variable['code']);
            } catch (InvalidArgumentException $e) {
                // A code holding a character a reference may not carry cannot be pointed at by a
                // canonical key, so listing it would offer an entry nothing could ever look up.
                continue;
            }

            $entries[] = $this->buildEntry($reference, $variable);
        }

        return $entries;
    }

    /**
     * Build the entry for one custom variable
     *
     * @param DirectiveReferenceInterface $reference Reference the entry describes
     * @param array{id: int, code: string, name: string} $variable Variable behind that reference
     * @return VariableKnowledgeInterface
     */
    private function buildEntry(
        DirectiveReferenceInterface $reference,
        array $variable
    ): VariableKnowledgeInterface {
        $code = $variable['code'];
        $name = $variable['name'] !== '' ? $variable['name'] : $code;

        return new VariableKnowledge(
            $reference,
            true,
            $name,
            (string)__(
                'The custom variable "%1", defined in the admin under System > Other Settings > '
                . 'Custom Variables. Its content is whatever a merchant put there, and it can differ '
                . 'from one store view to the next.',
                $code
            ),
            // An HTML message inserts the variable's HTML value as it stands, without escaping it,
            // so what this produces is markup rather than text.
            VariableKnowledgeInterface::OUTPUT_HTML,
            new Origin(
                OriginInterface::KIND_CUSTOM_VARIABLE,
                $code,
                (string)__(
                    'A custom variable carries two values, an HTML one and a plain one, and both are '
                    . 'stored per store view: a store view with no value of its own falls back to the '
                    . 'value saved for all store views. An HTML message uses the HTML value; a '
                    . 'plain-text message uses the plain value.'
                )
            ),
            [
                // The obvious reading of the two values - "the HTML one for HTML messages, the plain
                // one for plain-text messages" - is true but incomplete, and the missing half is the
                // half that surprises people.
                (string)__(
                    'Leaving the HTML value empty does not make the variable render as nothing in an '
                    . 'HTML message. The plain value is used instead, escaped and with its line breaks '
                    . 'turned into HTML line breaks, so any markup written in it arrives as visible '
                    . 'text rather than as markup.'
                ),
                // Verified against the directive's own implementation: it reads its parameters with
                // the parameter tokenizer, which ends an unquoted value at the next space, and it
                // never applies a modifier chain to what it returns.
                (string)__(
                    'This directive takes no formatting modifiers. A chain written after the code is '
                    . 'read as part of the code, so the lookup finds no variable and the directive '
                    . 'renders as nothing at all.'
                ),
            ],
            // The directive returns the stored value unchanged, so there is no formatting in force
            // when no chain is written - unlike a plain variable directive, which is escaped.
            null,
            true
        );
    }
}
