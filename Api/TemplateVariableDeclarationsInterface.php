<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

/**
 * Reads the variables an email template declares about itself.
 *
 * A template file carries an annotation listing the directives the code that sends it will fill in,
 * each with a label. That list is the only record of what a template family is given, and it is
 * wanted in two unrelated places - the chooser an author picks a variable from, and the description
 * shown for a directive already in the content - so loading it lives here rather than in either of
 * them.
 *
 * The declarations come back keyed by the directive without its braces. A template may write a key
 * either way round, so unwrapping happens once here and both callers see one shape: a consumer that
 * wants the directive back puts the braces on again, a consumer that wants to know what it points at
 * reads the kind and the rest straight out of the key.
 */
interface TemplateVariableDeclarationsInterface
{
    /**
     * The variables a template declares, as directive interiors mapped to their labels
     *
     * An empty array is a normal answer: a template may declare nothing, and one that cannot be
     * loaded at all is reported as declaring nothing rather than as an error, because a chooser and
     * an inspector both have something useful to show without it.
     *
     * @param string $templateId Identifier of the template to read
     * @return array<string, string> Directive without its braces, mapped to the declared label
     */
    public function getDeclarations(string $templateId): array;
}
