/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The popover's width is written down twice and nothing at run time compares the two.
 *
 * The box is positioned before it exists, so the width used to keep it inside the window is the
 * number in the script rather than a measured one. If the stylesheet is then given a different
 * width, nothing breaks loudly: the box is simply placed as though it were a size it is not, and
 * near the right-hand edge it hangs off the window or is nudged away from the directive it
 * describes - which reads as sloppy placement rather than as two numbers that disagree.
 *
 * Measuring the real box instead would need a browser, and this module has no browser harness. So
 * the two are read out of the source and compared here, which is cheap and catches the whole of it.
 */
var fs = require('fs'),
    path = require('path'),
    harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,

    WEB = path.join(__dirname, '..', '..', 'view', 'adminhtml', 'web'),

    script = fs.readFileSync(
        path.join(WEB, 'js', 'email-editor', 'knowledge', 'variable-inspector.js'),
        'utf8'
    ),
    stylesheet = fs.readFileSync(path.join(WEB, 'css', 'email-editor.css'), 'utf8');

/**
 * The width the given source states, or null when it states none
 *
 * @param {string} source
 * @param {RegExp} pattern with the number as its first group
 * @return {number|null}
 */
function statedWidth(source, pattern) {
    var found = source.match(pattern);

    return found ? parseInt(found[1], 10) : null;
}

test('the popover is the same width in the script and in the stylesheet', function () {
    var inScript = statedWidth(script, /PANEL_WIDTH\s*=\s*(\d+)/),
        inStylesheet = statedWidth(stylesheet, /\.ete-inspector\s*\{[^}]*?\bwidth:\s*(\d+)px/);

    assertSame(typeof inScript, 'number', 'the script states a width');
    assertSame(typeof inStylesheet, 'number', 'the stylesheet states a width');
    assertSame(inScript, inStylesheet, 'the two agree');
});
