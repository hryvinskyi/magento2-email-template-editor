/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Where the directive popover lands.
 *
 * Every case here is one an admin meets by clicking a directive somewhere ordinary: near the
 * bottom of a long template, on the last line, in a narrow window. What each one is guarding
 * against is the same failure - a box whose buttons are off the screen, with no scrollbar leading
 * to them - and none of it needs a browser to be checked.
 *
 * The pinned edge is checked as carefully as the position. It is what lets the box be placed
 * before its content has arrived, and a box pinned by the wrong edge drifts off the directive it
 * describes the moment a long explanation lands in it.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    placement = harness.loadPureModule('email-editor/knowledge/inspector-placement.js'),
    VIEWPORT = {width: 1400, height: 800},
    BOX = {width: 360, height: 420};

test('a click with room under it opens below the directive, pinned by its top', function () {
    var placed = placement.place({x: 500, y: 120}, BOX, VIEWPORT);

    assertSame(placed.placement, 'below', 'below the point');
    assertSame(placed.top, 120 + placement.GAP, 'clear of the directive');
    assertSame(placed.bottom, null, 'its foot is its own business');
    assertSame(placed.left, 500, 'starting at the pointer');
});

test('a click too near the bottom opens above the directive, pinned by its foot', function () {
    var placed = placement.place({x: 500, y: 700}, BOX, VIEWPORT);

    assertSame(placed.placement, 'above', 'above the point');
    assertSame(placed.bottom, VIEWPORT.height - (700 - placement.GAP), 'its foot clear of the directive');
    assertSame(placed.top, null, 'its top is its own business');
});

test('the box never runs past the right edge of the window', function () {
    var placed = placement.place({x: 1380, y: 120}, BOX, VIEWPORT);

    assertSame(placed.left, VIEWPORT.width - placement.MARGIN - BOX.width, 'pulled back inside');
});

test('the box never starts left of the window', function () {
    var placed = placement.place({x: -40, y: 120}, BOX, VIEWPORT);

    assertSame(placed.left, placement.MARGIN, 'pushed inside');
});

test('a window narrower than the box pins the box to the left edge', function () {
    var placed = placement.place({x: 100, y: 120}, BOX, {width: 200, height: 800});

    assertSame(placed.left, placement.MARGIN, 'its title and close button stay reachable');
});

test('a box that fits may use the whole of the side it was put on', function () {
    var placed = placement.place({x: 500, y: 120}, BOX, VIEWPORT);

    assertSame(
        placed.maxHeight,
        VIEWPORT.height - placement.MARGIN - (120 + placement.GAP),
        'everything under the point, down to the margin'
    );
});

test('a point with room on neither side takes the roomier side', function () {
    var placed = placement.place({x: 500, y: 150}, BOX, {width: 1400, height: 300});

    assertSame(placed.placement, 'below', 'the side with at least as much room');
    assertSame(placed.top, 150 + placement.GAP, 'still clear of the directive');
    assertSame(placed.maxHeight, placement.MIN_HEIGHT, 'never told to be shorter than it is worth');
});

test('a point near the very bottom of a short window opens above it', function () {
    var placed = placement.place({x: 500, y: 280}, BOX, {width: 1400, height: 300});

    assertSame(placed.placement, 'above', 'the roomier side');
    assertSame(placed.bottom, 300 - (280 - placement.GAP), 'its foot clear of the directive');
    assertSame(placed.maxHeight, 300 - placement.MARGIN - placed.bottom, 'up to the top margin');
});

test('a point above the top of the window still leaves the box inside it', function () {
    var placed = placement.place({x: 500, y: -400}, BOX, VIEWPORT);

    assertSame(placed.top, placement.MARGIN, 'against the top margin');
});

test('measurements that are not measurements do not produce a position that is not one', function () {
    var placed = placement.place(null, null, null);

    assertSame(placed.left, placement.MARGIN, 'a left edge that is a number');
    assertSame(placed.top, placement.GAP, 'a top edge that is a number');
    assertSame(placed.maxHeight, placement.MIN_HEIGHT, 'a height it can still use');
});

test('a point with no size behind it is still placed inside a real window', function () {
    var placed = placement.place(null, null, VIEWPORT);

    assertSame(placed.left, placement.MARGIN, 'inside the left margin');
    assertSame(placed.top, placement.GAP, 'below a point at the very top');
    assertSame(placed.bottom, null, 'pinned by its top');
});
