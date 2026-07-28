<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge;

/**
 * Everything the knowledge base has to say about one directive reference.
 *
 * An entry is answered for every reference that is asked about, including the ones nothing knows.
 * That is what {@see isKnown()} is for: an entry may honestly report that the base has no record of
 * this reference, and a consumer can then say so instead of showing a plausible-looking description
 * that was invented. An administrator cannot tell an invented description from a researched one, so
 * the base never guesses.
 *
 * Implementations are immutable values.
 */
interface VariableKnowledgeInterface
{
    /**
     * Plain text, escaped before it reaches the message
     */
    public const OUTPUT_TEXT = 'text';

    /**
     * Markup that is meant to survive into the message as markup
     */
    public const OUTPUT_HTML = 'html';

    /**
     * A URL
     */
    public const OUTPUT_URL = 'url';

    /**
     * An amount already formatted in a currency
     */
    public const OUTPUT_CURRENCY = 'currency';

    /**
     * A formatted date or date and time
     */
    public const OUTPUT_DATE = 'date';

    /**
     * A number that is not an amount of money
     */
    public const OUTPUT_NUMBER = 'number';

    /**
     * The reference this entry describes
     *
     * @return DirectiveReferenceInterface
     */
    public function getReference(): DirectiveReferenceInterface;

    /**
     * Whether the base actually has a record of this reference
     *
     * False marks the honest not-documented entry: its title, summary and origin describe the kind
     * of directive in general and say as much, and claim nothing about this reference in particular.
     *
     * @return bool
     */
    public function isKnown(): bool;

    /**
     * A short name for the value, as an administrator would call it
     *
     * @return string
     */
    public function getTitle(): string;

    /**
     * A sentence or two on what the value is
     *
     * @return string
     */
    public function getSummary(): string;

    /**
     * What sort of output the reference produces
     *
     * One of the published output kinds this interface declares. It is what makes the difference
     * between a value that is safe to escape and one that is markup on purpose.
     *
     * @return string
     */
    public function getOutputKind(): string;

    /**
     * Where the value comes from and how it is produced
     *
     * @return OriginInterface
     */
    public function getOrigin(): OriginInterface;

    /**
     * Things that are true about this reference and easy to get wrong
     *
     * Each caveat is one complete statement; they are shown as a list.
     *
     * @return string[]
     */
    public function getCaveats(): array;

    /**
     * The modifier that applies when the directive carries no modifier chain at all, or null
     *
     * It exists because an absent chain is not the absence of formatting: in an email template a
     * `{{var}}` with no chain is escaped, and a chain editor that presented that as "unformatted"
     * would have an administrator add escaping that was already there, or remove the whole chain
     * believing the output would stay as it was.
     *
     * @return string|null
     */
    public function getDefaultModifier(): ?string;

    /**
     * Whether the value behind this reference may be written back from the inspector
     *
     * A hint for the browser only. Every write is decided again on the server from the reference
     * itself, so a client that ignores this is refused rather than obeyed.
     *
     * @return bool
     */
    public function isValueWritable(): bool;

    /**
     * What the administrator can do about the value, or null before it has been resolved
     *
     * A provider builds an entry without one; the affordance is chosen afterwards, so that the rule
     * for choosing it lives in a single place instead of in every provider. Anything handed out by
     * the registry carries one.
     *
     * @return EditAffordanceInterface|null
     */
    public function getAffordance(): ?EditAffordanceInterface;

    /**
     * A copy of this entry carrying the given affordance
     *
     * @param EditAffordanceInterface $affordance Affordance chosen for this entry
     * @return VariableKnowledgeInterface
     */
    public function withAffordance(EditAffordanceInterface $affordance): VariableKnowledgeInterface;
}
