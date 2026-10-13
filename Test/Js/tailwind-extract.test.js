/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Which stylesheet the compiler reads back out of its iframe.
 *
 * The iframe holds three kinds of <style>: the theme block the compiler writes for the bundle to
 * read, any <style> the template itself carries (baked into the content wrapper so its classes are
 * scanned), and the stylesheet the bundle appends. Only the last is output. The rule being proved
 * is that the stored stylesheet is exactly that output - reading the others stored the raw theme
 * and a copy of the template's own styles with every override, and let the output poll settle on
 * the theme block before the bundle had produced anything.
 *
 * The document is a hand-built stand-in; what a real bundle writes is not reproduced here.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertTrue = harness.assertTrue,
    assertFalse = harness.assertFalse,
    compiler = harness.loadStubbedModule('email-editor/tailwind-compiler.js', {
        jquery: {},
        'Hryvinskyi_EmailTemplateEditor/js/email-editor/tailwind/class-names':
            harness.loadPureModule('email-editor/tailwind/class-names.js')
    }),
    HEAD = {id: '', parentNode: null},
    WRAPPER = {id: 'tw-content', parentNode: {id: '', parentNode: null}};

/**
 * A document holding the given <style> elements
 *
 * @param {Array<Object>} styles
 * @return {Object}
 */
function documentWith(styles) {
    return {
        querySelectorAll: function () {
            return styles;
        }
    };
}

/**
 * The theme block the compiler writes
 *
 * @return {Object}
 */
function themeBlock() {
    return {id: 'ete-tw-theme', parentNode: HEAD, textContent: '@theme { --color-primary: red; } @layer x { .a{} }'};
}

/**
 * A <style> the template carries in its markup
 *
 * @return {Object}
 */
function templateBlock() {
    return {id: '', parentNode: {id: '', parentNode: WRAPPER}, textContent: '.text-red { color: red; }'};
}

test('only the stylesheet the bundle wrote is read back', function () {
    var bundle = {id: '', parentNode: HEAD, textContent: '@layer utilities { .m-2 { margin: .5rem; } }'};

    assertSame(
        compiler._extractCss(documentWith([themeBlock(), templateBlock(), bundle])),
        '@layer utilities { .m-2 { margin: .5rem; } }',
        'extracted stylesheet'
    );
});

test('before the bundle has written anything there is no output, however Tailwind-like the rest looks', function () {
    var doc = documentWith([themeBlock(), templateBlock()]);

    assertSame(compiler._extractCss(doc), '', 'extracted stylesheet');
    assertTrue(compiler._bundleStyleMissing(doc), 'bundle reported missing');
});

test('a bundle stylesheet that carries no familiar keyword is still read back whole', function () {
    var bundle = {id: '', parentNode: HEAD, textContent: '.border-t-3 { border-top-width: 3px; }'},
        doc = documentWith([themeBlock(), bundle]);

    assertSame(compiler._extractCss(doc), '.border-t-3 { border-top-width: 3px; }', 'extracted stylesheet');
    assertFalse(compiler._bundleStyleMissing(doc), 'bundle reported missing');
});
