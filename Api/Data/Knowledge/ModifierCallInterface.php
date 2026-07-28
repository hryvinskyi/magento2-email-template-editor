<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge;

/**
 * One call in a directive's `|...` modifier chain: a name and its positional arguments.
 *
 * The renderer's own grammar for a single call is `name[:arg1[:arg2...]]` - it splits the call on
 * colons, takes the first part as the name and passes the rest through as arguments - so that is
 * the form {@see toString()} produces.
 *
 * Implementations are immutable values.
 */
interface ModifierCallInterface
{
    /**
     * The modifier name, exactly as it appears in the template
     *
     * It is not normalised. The renderer looks a modifier up by exact name and silently ignores
     * any name it does not know, so changing the spelling here would misrepresent what the
     * template actually does.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * The positional arguments of the call, in order
     *
     * @return string[]
     */
    public function getArguments(): array;

    /**
     * The call as it appears in a chain, `name` or `name:arg1:arg2`
     *
     * @return string
     */
    public function toString(): string;
}
