/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What a draft save claims to know.
 *
 * The server keeps a stored value for a field left out of a save and clears one sent empty. The
 * rule being proved is that a value the editor does not actually know is left out rather than
 * sent as an empty placeholder - a theme still loading would otherwise switch the template to the
 * store default, and a stylesheet never compiled for this content would strip its utilities.
 */
var harness = require('./harness'),
    test = harness.test,
    assertLike = harness.assertLike,
    payload = harness.loadPureModule('email-editor/draft-payload.js');

test('a selected theme is carried and an unknown one is left out', function () {
    assertLike(payload.withThemeId({}, 7), {theme_id: 7}, 'selected theme');
    assertLike(payload.withThemeId({}, null), {}, 'theme not loaded');
    assertLike(payload.withThemeId({}, undefined), {}, 'no theme editor');
    assertLike(payload.withThemeId({}, ''), {}, 'empty theme id');
});

test('the stylesheet is carried only when it was compiled for the content being sent', function () {
    assertLike(
        payload.withTailwindCss({template_content: '<p class="m-2">'}, '.m-2{}', '<p class="m-2">'),
        {template_content: '<p class="m-2">', tailwind_css: '.m-2{}'},
        'stylesheet of this content'
    );
    assertLike(
        payload.withTailwindCss({template_content: '<p class="m-4">'}, '.m-2{}', '<p class="m-2">'),
        {template_content: '<p class="m-4">'},
        'stylesheet of an earlier revision'
    );
    assertLike(
        payload.withTailwindCss({template_content: '<p>'}, '.restored{}', null),
        {template_content: '<p>'},
        'stylesheet restored from storage, never compiled here'
    );
});

test('an empty stylesheet compiled for this content is known and is sent', function () {
    assertLike(
        payload.withTailwindCss({template_content: '<p>plain</p>'}, '', '<p>plain</p>'),
        {template_content: '<p>plain</p>', tailwind_css: ''},
        'empty stylesheet of this content'
    );
});
