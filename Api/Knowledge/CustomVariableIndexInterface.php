<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

/**
 * The merchant-defined custom variables of this installation, indexed by their code.
 *
 * It exists because the code is the only handle a directive carries, while everything built around
 * one - the name shown for it, the link to the form that owns it - is keyed by the variable's own
 * identifier. Several callers need that translation for the same request, and a description request
 * may ask about hundreds of directives, so the index is read once and answered from memory rather
 * than reloaded per question.
 *
 * Only what identifies a variable is held here. The values behind it are stored per store view and
 * are read where a store view is actually known, not here.
 */
interface CustomVariableIndexInterface
{
    /**
     * Every custom variable, keyed by its code
     *
     * @return array<string, array{id: int, code: string, name: string}>
     */
    public function getAll(): array;

    /**
     * One custom variable by its code, or null when no variable carries that code
     *
     * Null is the answer that matters most: a directive naming a code nothing defines renders as
     * nothing at all, and saying so is more use to an administrator than any description would be.
     *
     * @param string $code Code as written in the directive
     * @return array{id: int, code: string, name: string}|null
     */
    public function find(string $code): ?array;
}
