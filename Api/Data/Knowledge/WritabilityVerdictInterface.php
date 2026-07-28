<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge;

/**
 * The answer to "may this value be written from the inspector, and if not, why not".
 *
 * A refusal always carries its reason, because the two places that ask the question use the answer
 * for different things: the side that builds an entry turns the reason into a caveat an
 * administrator reads before trying, and the side that accepts a write turns it into the message
 * explaining why the write was refused. Both get the same sentence, so what the inspector promises
 * and what the write path does can never say different things.
 *
 * The reason is written as a statement of fact about the value rather than as an instruction, so it
 * reads correctly in either place.
 *
 * Implementations are immutable values.
 */
interface WritabilityVerdictInterface
{
    /**
     * Whether the value may be written
     *
     * @return bool
     */
    public function isWritable(): bool;

    /**
     * Why the value may not be written, or an empty string when it may
     *
     * @return string
     */
    public function getReason(): string;
}
