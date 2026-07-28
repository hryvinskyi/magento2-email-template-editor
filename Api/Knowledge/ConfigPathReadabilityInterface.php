<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

/**
 * Whether a {{config}} directive naming a path renders that path's value, or renders nothing.
 *
 * The email filter reads a fixed list of configuration paths and answers every other path with an
 * empty string, without a word of complaint. Two very different questions turn on that list - "is it
 * worth showing this value beside the directive" and "may this value be written from here" - and the
 * second is far stricter than the first: a value can be perfectly readable and still not be
 * something to edit from an email editor.
 *
 * So the list lives behind its own port, and the writability decision consults it as its first gate
 * rather than owning it. Keeping them apart is what stops the looser question from being answered
 * with the stricter predicate, which would hide the current value of every path that is readable but
 * deliberately not editable - the store's country, its region and its base URLs among them.
 *
 * One implementation asks the object the filter itself asks, so what the inspector says a directive
 * renders and what the message actually renders cannot drift apart.
 */
interface ConfigPathReadabilityInterface
{
    /**
     * Whether a {{config}} directive naming this path renders the stored value
     *
     * @param string $path Store configuration path, as a {{config}} directive would name it
     * @return bool False means the directive renders an empty string whatever is stored there
     */
    public function isReadable(string $path): bool;
}
