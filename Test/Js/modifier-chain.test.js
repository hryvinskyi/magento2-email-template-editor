/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What the chain module must do to a `|...` suffix.
 *
 * The operations are checked for leaving their input alone as carefully as for their result: a
 * chain is read by the decorator and the inspector at the same time while the document stays
 * editable, so an operation that wrote through its argument would change what another caller
 * already believed it held.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertTrue = harness.assertTrue,
    chain = harness.loadPureModule('email-editor/knowledge/modifier-chain.js');

test('an empty chain is an empty list', function () {
    assertLike(chain.parse(''), [], 'empty string');
    assertLike(chain.parse('|'), [], 'a lone pipe');
    assertSame(chain.serialise([]), '', 'empty list');
});

test('a chain parses with or without its leading pipe', function () {
    var expected = [{name: 'escape', args: ['html']}, {name: 'nl2br', args: []}];

    assertLike(chain.parse('|escape:html|nl2br'), expected, 'with the pipe');
    assertLike(chain.parse('escape:html|nl2br'), expected, 'without the pipe');
});

test('a call keeps every argument the renderer would pass', function () {
    assertLike(chain.parse('|escape:html:extra'), [{name: 'escape', args: ['html', 'extra']}], 'two arguments');
});

test('serialising what was parsed reproduces the chain', function () {
    var text = '|escape:html|nl2br';

    assertSame(chain.serialise(chain.parse(text)), text, 'round trip');
});

test('adding a modifier that is not there appends it', function () {
    var calls = chain.parse('|nl2br'),
        result = chain.withModifier(calls, 'escape', ['html']);

    assertLike(
        result,
        [{name: 'nl2br', args: []}, {name: 'escape', args: ['html']}],
        'result'
    );
    assertLike(calls, [{name: 'nl2br', args: []}], 'input untouched');
});

test('adding a modifier that is already there replaces its arguments in place', function () {
    var calls = chain.parse('|escape:html|nl2br'),
        result = chain.withModifier(calls, 'escape', ['url']);

    assertLike(
        result,
        [{name: 'escape', args: ['url']}, {name: 'nl2br', args: []}],
        'result'
    );
    assertSame(chain.serialise(result), '|escape:url|nl2br', 'serialised');
    assertSame(chain.serialise(calls), '|escape:html|nl2br', 'input untouched');
});

test('adding a modifier with no arguments drops the arguments it had', function () {
    assertSame(chain.serialise(chain.withModifier(chain.parse('|escape:html'), 'escape')), '|escape', 'result');
});

test('removing a modifier leaves the rest in order', function () {
    var calls = chain.parse('|escape:html|nl2br'),
        result = chain.withoutModifier(calls, 'escape');

    assertSame(chain.serialise(result), '|nl2br', 'result');
    assertSame(chain.serialise(calls), '|escape:html|nl2br', 'input untouched');
});

test('removing a modifier that is not there changes nothing', function () {
    assertSame(chain.serialise(chain.withoutModifier(chain.parse('|nl2br'), 'escape')), '|nl2br', 'result');
});

test('removing every modifier yields an empty chain', function () {
    assertSame(chain.serialise(chain.withoutModifier(chain.parse('|escape'), 'escape')), '', 'result');
});

test('reordering moves one call and leaves the input alone', function () {
    var calls = chain.parse('|escape:html|nl2br'),
        result = chain.reorder(calls, 1, 0);

    assertSame(chain.serialise(result), '|nl2br|escape:html', 'result');
    assertSame(chain.serialise(calls), '|escape:html|nl2br', 'input untouched');
});

test('reordering outside the list changes nothing', function () {
    var calls = chain.parse('|escape|nl2br');

    assertSame(chain.serialise(chain.reorder(calls, 0, 5)), '|escape|nl2br', 'target too high');
    assertSame(chain.serialise(chain.reorder(calls, -1, 0)), '|escape|nl2br', 'source below zero');
    assertSame(chain.serialise(chain.reorder(calls, 1, 1)), '|escape|nl2br', 'moved onto itself');
});

test('every operation hands back a new list', function () {
    var calls = chain.parse('|escape:html');

    assertTrue(chain.withModifier(calls, 'nl2br') !== calls, 'adding');
    assertTrue(chain.withoutModifier(calls, 'nl2br') !== calls, 'removing');
    assertTrue(chain.reorder(calls, 0, 0) !== calls, 'reordering');
});

test('a call cannot be reached through the list it came from', function () {
    var calls = chain.parse('|escape:html'),
        result = chain.withModifier(calls, 'nl2br');

    result[0].args.push('url');

    assertSame(chain.serialise(calls), '|escape:html', 'input untouched');
});

test('an argument that is not text is dropped rather than spelled out', function () {
    // A chain is written into the template as text, so anything without a spelling has to go. It
    // reached this module once as a Knockout observable passed instead of the value it holds, and
    // the serialised chain then failed to read back, which surfaced to the author as a directive
    // that simply refused to be edited.
    assertSame(
        chain.serialise(chain.withModifier([], 'escape', [function () { return 'html'; }])),
        '|escape',
        'a function argument'
    );
    assertSame(
        chain.serialise(chain.withModifier([], 'escape', [null, 'html', undefined, 7])),
        '|escape:html',
        'only the text survives, in order'
    );
});

test('a chain built from a non-text argument can still be written back', function () {
    var built = chain.serialise(chain.withModifier(chain.parse('|raw'), 'escape', [{}]));

    assertSame(built, '|raw|escape', 'serialised');
    assertSame(chain.serialise(chain.parse(built)), built, 'round trips');
});
