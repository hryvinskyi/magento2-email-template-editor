<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

use Hryvinskyi\EmailTemplateEditor\Api\Data\ThemeInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Write access to themes, split into intents that can each change only what they are named after.
 *
 * A theme's `store_id` is not payload of a content edit: it is the field
 * `ThemeRepositoryInterface::getDefaultTheme()` filters on (`store_id IN (0, <current store>)`) to
 * decide which stores resolve a theme at render time. Moving it therefore re-points email rendering
 * for every store on the installation, and a default theme moved off scope `0` leaves every other
 * store resolving no theme at all.
 *
 * Hence the split below: creating a theme establishes its store scope, updating a theme's
 * authored content cannot change it, and moving one between store scopes is its own intent that
 * cannot touch content. Together the three cover every legitimate write to a theme, and none of
 * them can be used to smuggle another one's effect through.
 */
interface ThemeWriterInterface
{
    /**
     * Create a theme, fixing the store scope it will be resolved for
     *
     * This is the only intent that establishes a theme's store scope.
     *
     * @param string $name Human-readable theme name; must not be blank.
     * @param string $themeCss Authored theme source; must not be blank and must pass validation.
     * @param int $storeId Store view the theme is scoped to; `0` makes it resolvable by every store.
     * @return ThemeInterface The persisted theme.
     * @throws LocalizedException When the name is blank, the CSS is blank or the CSS fails
     *                            validation; also when the repository cannot persist the theme
     *                            (`CouldNotSaveException` extends `LocalizedException`).
     */
    public function create(string $name, string $themeCss, int $storeId): ThemeInterface;

    /**
     * Update a theme's authored content, leaving its store scope untouched
     *
     * There is deliberately no store id parameter: the scope is an invariant of the theme, so an
     * update path is given no way to express a change to it and no caller can pass one. Do not add
     * the parameter back — moving scope is a separate intent, not a side effect of editing content.
     *
     * A blank name (`''`) and a missing name (`null`) mean the same thing here: leave the stored
     * name alone. The HTTP caller reads its `name` param with a `''` default and therefore always
     * sends a string; the `null` default exists only for direct PHP callers.
     *
     * @param int $themeId Identifier of an existing theme.
     * @param string $themeCss Replacement theme source; must not be blank and must pass validation.
     * @param string|null $name New name, or `''`/`null` to keep the stored one.
     * @return ThemeInterface The persisted theme.
     * @throws LocalizedException When the CSS is blank or fails validation; when no theme has the
     *                            given id (`NoSuchEntityException` extends `LocalizedException`);
     *                            and when the repository cannot persist the theme
     *                            (`CouldNotSaveException` likewise).
     */
    public function updateContent(int $themeId, string $themeCss, ?string $name = null): ThemeInterface;

    /**
     * Move a theme to another store scope, leaving its authored content untouched
     *
     * The mirror image of `updateContent`: that intent is given no way to move a theme between
     * stores, and this one writes nothing but the scope — not the name, not the source, not the
     * default flag. Rescoping is an explicit administrative act, never a side effect of an edit.
     *
     * One move is refused: a theme that is currently the default for every store view cannot be
     * limited to a single store view while it is the only theme with that reach, because the
     * remaining store views would then resolve no theme at all. Moving a theme *to* the global
     * scope, or between two specific store views, is always allowed.
     *
     * @param int $themeId Identifier of an existing theme.
     * @param int $storeId Store view to scope the theme to; `0` makes it resolvable by every store.
     * @return ThemeInterface The persisted theme.
     * @throws LocalizedException When the move would leave no default theme reachable from every
     *                            store view; when no theme has the given id
     *                            (`NoSuchEntityException` extends `LocalizedException`); and when
     *                            the repository cannot persist the theme (`CouldNotSaveException`
     *                            likewise).
     */
    public function changeScope(int $themeId, int $storeId): ThemeInterface;
}
