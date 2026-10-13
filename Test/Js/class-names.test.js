/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Which class names a template asks Tailwind for.
 *
 * The rule being proved is that a class survives however broken the markup around it is as a
 * document. The header and footer templates are halves of one document - the footer starts by
 * closing the cell the header opened - and a class on the footer's own cell that went missing here
 * was never compiled, never stored with the footer, and so never reached a single email.
 */
var harness = require('./harness'),
    test = harness.test,
    assertLike = harness.assertLike,
    classNames = harness.loadPureModule('email-editor/tailwind/class-names.js');

test('a class on a cell the fragment reopens after closing the one before it is collected', function () {
    var footer = '<!-- End Content -->\n</td>\n</tr>\n<tr>\n' +
        '<td class="footer bg-white py-1 px-2 border-t-3 border-black">\n' +
        '<table><tr><td colspan="3"><h3 class="email-footer">Helpful Links</h3></td></tr></table>\n' +
        '</td>\n</tr>\n</table>\n</body>';

    assertLike(
        classNames.collect(footer),
        ['footer', 'bg-white', 'py-1', 'px-2', 'border-t-3', 'border-black', 'email-footer'],
        'classes of a footer fragment'
    );
});

test('a class next to a directive inside the same tag is collected', function () {
    var header = '<img {{if logo_width}} width="{{var logo_width}}" {{/if}} class="invert logo" />';

    assertLike(classNames.collect(header), ['invert', 'logo'], 'classes beside a directive');
});

test('either quote is read and a repeated class is listed once, where it first appears', function () {
    assertLike(
        classNames.collect('<p class="text-black  mt-2"></p><span class=\'mt-2 !bg-primary\'></span>'),
        ['text-black', 'mt-2', '!bg-primary'],
        'classes under both quotes'
    );
});

test('a directive in a class value is skipped, even one that carries the same quote', function () {
    assertLike(
        classNames.collect('<td class="{{var row.css|escape:"html"}} p-4 bg-gray-100">'),
        ['p-4', 'bg-gray-100'],
        'classes beside a quoted directive'
    );
});

test('an unquoted class value is read', function () {
    assertLike(classNames.collect('<td class=p-4 align="left">'), ['p-4'], 'unquoted class');
});

test('an attribute that only ends in "class" is not read as one', function () {
    assertLike(classNames.collect('<td data-class="p-4" class="m-2">'), ['m-2'], 'data-class ignored');
});

test('source with no class attribute, or no source, asks for nothing', function () {
    assertLike(classNames.collect('<p>{{trans "Hello"}}</p>'), [], 'classes of markup without any');
    assertLike(classNames.collect(''), [], 'classes of empty source');
    assertLike(classNames.collect(null), [], 'classes of no source');
});
