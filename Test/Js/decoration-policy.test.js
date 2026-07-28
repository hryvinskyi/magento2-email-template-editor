/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The rules the directive marking obeys.
 *
 * These are the judgements that would otherwise sit inline in an adapter nothing can load: when a
 * document is too big to mark, which mark a pointer is over, and which gestures belong to the
 * editor rather than to this feature. Each one is a decision an admin feels directly - a stall
 * while typing, the wrong popover, a selection that opens something instead of selecting - and
 * none of them needs a browser to be checked.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    policy = harness.loadPureModule('email-editor/knowledge/decoration-policy.js');

/**
 * A press with nothing held down and no travel
 *
 * @param {Object} [overrides]
 * @return {Object}
 */
function plainPress(overrides) {
    var press = {
            button: 0,
            ctrlKey: false,
            metaKey: false,
            altKey: false,
            shiftKey: false,
            x: 100,
            y: 200
        },
        key;

    for (key in overrides || {}) {
        if (Object.prototype.hasOwnProperty.call(overrides, key)) {
            press[key] = overrides[key];
        }
    }

    return press;
}

test('a document at the ceiling is marked and one character past it is not', function () {
    assertSame(policy.shouldDecorate(policy.SIZE_CEILING), true, 'exactly at the ceiling');
    assertSame(policy.shouldDecorate(policy.SIZE_CEILING + 1), false, 'one past it');
    assertSame(policy.shouldDecorate(0), true, 'an empty document');
});

test('a length that is not a length is not marked', function () {
    assertSame(policy.shouldDecorate(-1), false, 'below zero');
    assertSame(policy.shouldDecorate('120'), false, 'a string');
    assertSame(policy.shouldDecorate(undefined), false, 'nothing at all');
});

test('an offset inside a span finds that span', function () {
    var spans = [{start: 10, end: 20}, {start: 40, end: 60}];

    assertSame(policy.spanIndexAt(spans, 10), 0, 'the first character of a span');
    assertSame(policy.spanIndexAt(spans, 19), 0, 'the last character of a span');
    assertSame(policy.spanIndexAt(spans, 55), 1, 'inside the second span');
});

test('the offset just past a span belongs to the text after it', function () {
    assertSame(policy.spanIndexAt([{start: 10, end: 20}], 20), -1, 'one past the end');
    assertSame(policy.spanIndexAt([{start: 10, end: 20}], 9), -1, 'one before the start');
});

test('a span whose text has been deleted holds nothing and does not shift the rest', function () {
    var spans = [{start: 10, end: 20}, null, {start: 40, end: 60}];

    assertSame(policy.spanIndexAt(spans, 15), 0, 'before the hole');
    assertSame(policy.spanIndexAt(spans, 50), 2, 'after the hole keeps its own position');
});

test('nothing to look through holds nothing', function () {
    assertSame(policy.spanIndexAt([], 5), -1, 'an empty list');
    assertSame(policy.spanIndexAt(null, 5), -1, 'no list at all');
    assertSame(policy.spanIndexAt([{start: 0, end: 5}], null), -1, 'no offset');
});

test('a plain press and release in the same place is an activation', function () {
    assertSame(policy.isActivatingClick(plainPress(), {x: 100, y: 200}, false), true, 'no travel');
    assertSame(policy.isActivatingClick(plainPress(), {x: 102, y: 198}, false), true, 'a hand that wobbled');
});

test('a pointer that travelled was selecting text, not clicking', function () {
    assertSame(policy.isActivatingClick(plainPress(), {x: 140, y: 200}, false), false, 'across');
    assertSame(policy.isActivatingClick(plainPress(), {x: 100, y: 260}, false), false, 'down');
});

test('a release that ends with a selection is not an activation', function () {
    assertSame(policy.isActivatingClick(plainPress(), {x: 100, y: 200}, true), false, 'something selected');
});

test('every gesture the editor already owns is left to the editor', function () {
    var release = {x: 100, y: 200};

    assertSame(policy.isActivatingClick(plainPress({shiftKey: true}), release, false), false, 'extending a selection');
    assertSame(policy.isActivatingClick(plainPress({altKey: true}), release, false), false, 'a rectangular selection');
    assertSame(policy.isActivatingClick(plainPress({ctrlKey: true}), release, false), false, 'the control key');
    assertSame(policy.isActivatingClick(plainPress({metaKey: true}), release, false), false, 'the platform key');
    assertSame(policy.isActivatingClick(plainPress({button: 2}), release, false), false, 'the context menu button');
    assertSame(policy.isActivatingClick(plainPress({button: 1}), release, false), false, 'the middle button');
});

test('a release with no press behind it is not one gesture', function () {
    assertSame(policy.isActivatingClick(null, {x: 100, y: 200}, false), false, 'the press was elsewhere');
    assertSame(policy.isActivatingClick(plainPress(), null, false), false, 'nowhere to release');
});
