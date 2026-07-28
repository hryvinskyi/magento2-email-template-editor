<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

/**
 * What is being described, for the sources of knowledge that cannot answer without it.
 *
 * Describing a reference needs the reference and the store view, and that is what a source is
 * handed. One source needs more: a variable declared by a template only means anything in the
 * context of that template, and the same name in another template family is a different value or no
 * value at all. Widening what every source is given, so that one of them can read a field, would put
 * an argument nobody else uses into every present and future implementation - so the template being
 * described is carried alongside instead.
 *
 * It is request-scoped mutable state, which is a thing to be careful with rather than to spread
 * around. Whoever sets it owns clearing it, in a finally, so that a failed description cannot leave
 * a template identifier behind for the next one: that would not fail, it would answer confidently
 * about the wrong template. An unset context reports an empty template identifier, and a source that
 * needs one describes nothing rather than guessing.
 */
interface DescribeContextInterface
{
    /**
     * State what is being described
     *
     * Both parts are set together, so the context is never half-filled with a template from one
     * description and a store view from another.
     *
     * @param string $templateId Identifier of the template being described
     * @param int $storeId Store view the description is asked for
     * @return void
     */
    public function set(string $templateId, int $storeId): void;

    /**
     * The template being described, or an empty string when nothing is
     *
     * @return string
     */
    public function getTemplateId(): string;

    /**
     * The store view the description is asked for, or zero when nothing is being described
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Forget what was being described
     *
     * @return void
     */
    public function clear(): void;
}
