/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Format Code, as far as directives are concerned.
 *
 * The rule being proved is that formatting never reaches inside a directive. The text of a
 * {{trans}} is the key its translation is found by, so a line break added between two tags inside
 * it is a sentence that silently stops being translated.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    formatter = harness.loadPureModule('email-editor/markup-formatter.js');

test('adjacent tags outside directives are put on lines of their own', function () {
    assertSame(
        formatter.breakBetweenTags('<table><tr><td>x</td></tr></table>'),
        '<table>\n<tr>\n<td>x</td>\n</tr>\n</table>',
        'formatted markup'
    );
});

test('the text of a trans directive is left byte for byte as it was', function () {
    assertSame(
        formatter.breakBetweenTags('<p><span>{{trans "Hello,<br><br>%name" name=$customer.name}}</span></p>'),
        '<p>\n<span>{{trans "Hello,<br><br>%name" name=$customer.name}}</span>\n</p>',
        'formatted markup around a trans directive'
    );
});

test('a directive ends at the first closing braces, as the renderer ends it', function () {
    assertSame(
        formatter.breakBetweenTags('{{var a}}<b><i>{{trans "x<a><b>"}}<u><s>'),
        '{{var a}}<b>\n<i>{{trans "x<a><b>"}}<u>\n<s>',
        'formatting between and after directives'
    );
});

test('an unclosed directive does not stop the markup before it being formatted', function () {
    assertSame(formatter.breakBetweenTags('<a><b>{{var x<c><d>'), '<a>\n<b>{{var x<c>\n<d>', 'unclosed directive');
    assertSame(formatter.breakBetweenTags(''), '', 'empty template');
});
