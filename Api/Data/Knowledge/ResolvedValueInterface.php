<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge;

/**
 * What a directive currently evaluates to, together with how much that answer can be trusted.
 *
 * Two answers are possible and they must never be confused. An exact value was read from the place
 * the email will read it from, in a named scope. A sample value came from mock data standing in for
 * a record the email has not been sent about yet, and is only representative of the shape of the
 * output. Presenting the second as the first would have an administrator tune a template against
 * numbers that will never appear in a real message.
 *
 * The scope is carried alongside the value rather than being left implicit, because the same
 * reference resolves to different values in different scopes, and an administrator can only check
 * that a change landed where it was meant to if the answer says which scope it came from.
 *
 * Implementations are immutable values.
 */
interface ResolvedValueInterface
{
    /**
     * The value that applies when nothing more specific is set
     */
    public const SCOPE_DEFAULT = 'default';

    /**
     * The value set for one website
     */
    public const SCOPE_WEBSITE = 'websites';

    /**
     * The value set for one store view
     */
    public const SCOPE_STORE = 'stores';

    /**
     * Whether a value could be produced at all
     *
     * False means the reference has no readable value here - not that the value is empty. An empty
     * string that really is what renders is available.
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Whether this is the value the email will actually render
     *
     * False means it came from sample data standing in for a record, and must be presented as such.
     *
     * @return bool
     */
    public function isExact(): bool;

    /**
     * The value, already shortened to a length fit for display
     *
     * @return string
     */
    public function getPreview(): string;

    /**
     * Whether the preview is shorter than the value it was taken from
     *
     * @return bool
     */
    public function isTruncated(): bool;

    /**
     * The scope type the value was read from
     *
     * One of the published scope types this interface declares, or an empty string when the value
     * did not come from a scope at all. The empty string is deliberate: naming the default scope for
     * a value that was never read from any scope would be indistinguishable from a value that really
     * does fall back to the default, which is exactly the confusion an administrator checking where
     * a change landed cannot afford.
     *
     * @return string
     */
    public function getScope(): string;

    /**
     * The identifier of that scope, zero for the default scope and for no scope at all
     *
     * @return int
     */
    public function getScopeId(): int;

    /**
     * The scope named the way the administrator sees it in the admin
     *
     * @return string
     */
    public function getScopeLabel(): string;
}
