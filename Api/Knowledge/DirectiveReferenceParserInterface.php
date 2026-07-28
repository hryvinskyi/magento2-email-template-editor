<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;

/**
 * Builds directive references, either from a canonical string or from parsed directive parts.
 *
 * Both entry points apply the same validation and the same kind-specific normalisation, so a
 * reference built from a directive found in a document and one parsed from the canonical string a
 * browser sent back are equal whenever they point at the same thing.
 */
interface DirectiveReferenceParserInterface
{
    /**
     * Build a reference from its canonical `<kind>:<expression>` string
     *
     * The split is on the first colon only, so an expression may contain colons of its own.
     *
     * @param string $canonical Canonical reference string
     * @return DirectiveReferenceInterface
     * @throws \InvalidArgumentException When the string carries no separator, the kind is empty or
     *                                   outside the published set, or the expression contains a
     *                                   character that may not appear in a reference
     */
    public function parse(string $canonical): DirectiveReferenceInterface;

    /**
     * Build a reference from a directive kind and a raw expression
     *
     * @param string $kind Directive kind, matched against the published set without regard to case
     * @param string $expression Raw expression, normalised here according to the kind
     * @return DirectiveReferenceInterface
     * @throws \InvalidArgumentException When the kind is empty or outside the published set, or the
     *                                   expression contains a character that may not appear in a
     *                                   reference
     */
    public function create(string $kind, string $expression): DirectiveReferenceInterface;
}
