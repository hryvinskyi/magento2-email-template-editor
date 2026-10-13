/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Text placed into HTML the editor builds as a string.
 *
 * The preview frame a server message is written into runs with the admin's origin, so the rule
 * being proved is that such a message is shown as words and never read as markup.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    htmlText = harness.loadPureModule('email-editor/html-text.js');

test('markup in a message is escaped, quotes included', function () {
    assertSame(
        htmlText.escape('<img src=x onerror="alert(1)"> & \'q\''),
        '&lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp; &#39;q&#39;',
        'escaped message'
    );
});

test('no message is the empty string', function () {
    assertSame(htmlText.escape(null), '', 'null');
    assertSame(htmlText.escape(undefined), '', 'undefined');
    assertSame(htmlText.escape(12), '12', 'number');
});
