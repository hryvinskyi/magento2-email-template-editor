<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge;

/**
 * One modifier the editor is willing to name: what it is called, what it does and what it takes.
 *
 * A descriptor is a published fact about the template filter, not a wish. The filter looks a modifier
 * up by exact name and silently skips any name it does not hold, so a name offered here that the
 * filter does not run would read as formatting that was applied when nothing happened at all - and in
 * the one case where that silence matters most, the value reaches the message unescaped. That is why
 * {@see isImplemented()} exists rather than the set simply being "the names we offer".
 *
 * Implementations are immutable values.
 */
interface ModifierDescriptorInterface
{
    /**
     * The name as it is written in a modifier chain
     *
     * Compared exactly. The filter's lookup is an array lookup by this string, so a differently
     * cased spelling is a different - and, to the filter, unknown - modifier.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * A short name for the modifier, as an administrator would call it
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * What the modifier does to the value, and what is easy to get wrong about it
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Whether the template filter actually runs anything for this name
     *
     * False marks a name that is an established idiom whose whole effect comes from the filter not
     * recognising it. Such a name is still worth publishing, because it is what templates are
     * written with, but a consumer must not present it as something the filter carries out.
     *
     * @return bool
     */
    public function isImplemented(): bool;

    /**
     * The positional arguments the modifier accepts, in the order they are written
     *
     * Each entry names the argument, lists the values the filter recognises and states which of them
     * is in force when the argument is omitted. The options are spelled exactly as the filter
     * compares them: the comparison is case sensitive and an unrecognised spelling is not reported
     * as an error, so a consumer offers these values instead of accepting typed input. An empty
     * option list means the filter places no constraint on what may be written there.
     *
     * @return array<int, array{name: string, options: string[], default: string}>
     */
    public function getArgumentSpec(): array;
}
