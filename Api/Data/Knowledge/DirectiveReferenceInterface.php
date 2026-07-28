<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge;

/**
 * What a `{{...}}` directive points at, independent of where it sits in a document.
 *
 * A reference is a kind plus a normalised, kind-specific expression, and it is the lookup key of
 * the variable knowledge base. Its canonical string form (`<kind>:<expression>`) is what travels
 * over the wire, so the parser guarantees the round trip: parsing a canonical string produces a
 * reference equal to the one the string came from. Anything that fails to round-trip is a lookup
 * miss the admin sees as "not documented".
 *
 * Implementations are immutable values.
 */
interface DirectiveReferenceInterface
{
    /**
     * The directive kind
     *
     * One of the published set the parser validates against, in its canonical spelling
     * (for example `var`, `config`, `customVar`).
     *
     * @return string
     */
    public function getKind(): string;

    /**
     * The normalised expression for this kind
     *
     * Normalisation is kind-specific: a variable path with its whitespace stripped, a config path
     * or custom-variable code with any surrounding quotes removed, a directive parameter with its
     * internal whitespace collapsed, or an empty string for the kinds that carry no identity
     * beyond the kind itself.
     *
     * @return string
     */
    public function getExpression(): string;

    /**
     * Whether the expression given to the parser exceeded the key length limit and was truncated
     *
     * Over-long expressions are truncated rather than rejected, so a long `{{trans}}` message
     * stays usable; the flag lets a consumer answer with a generic, kind-level description instead
     * of pretending the truncated key identifies something exactly. The flag describes the input
     * this reference was built from, not the reference's identity: a canonical string is always
     * within the limit already, so re-parsing one reports false while still producing an equal
     * reference.
     *
     * @return bool
     */
    public function isOverlong(): bool;

    /**
     * The canonical string form, `<kind>:<expression>`
     *
     * Parsing is a split on the first colon only, so an expression may contain colons of its own.
     *
     * @return string
     */
    public function toCanonicalString(): string;

    /**
     * Whether another reference points at the same thing
     *
     * Identity is the kind plus the expression; the over-long flag is metadata about the input and
     * plays no part in it.
     *
     * @param DirectiveReferenceInterface $other Reference to compare against
     * @return bool
     */
    public function equals(DirectiveReferenceInterface $other): bool;
}
