/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What the popover is allowed to remember, and for how long.
 *
 * The rule being proved is that an answer belongs to the store view it was read at. A value read
 * at one store view shown as the answer for another is not a stale cache entry an admin can spot -
 * it is a confident wrong answer, and the whole point of the popover is that it does not give
 * those. The modifier list is the one thing that outlives a store switch, and that is proved here
 * too, because re-fetching it would be a request for an answer that cannot have changed.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    cacheModule = harness.loadPureModule('email-editor/knowledge/entry-cache.js');

test('an entry comes back for the store view it was remembered at', function () {
    var cache = cacheModule.create(),
        entry = {title: 'Store name'};

    cache.remember(3, 'config:general/store_information/name', entry);

    assertLike(
        cache.entry(3, 'config:general/store_information/name'),
        entry,
        'the same store view'
    );
});

test('a reference nobody asked about is a miss, not an answer', function () {
    var cache = cacheModule.create();

    cache.remember(3, 'config:general/store_information/name', {title: 'Store name'});

    assertSame(cache.entry(3, 'var:order.increment_id'), null, 'never remembered');
    assertSame(cache.entry(3, 'toString'), null, 'a name every object carries');
    assertSame(cache.entry(3, '__proto__'), null, 'the prototype');
});

test('an entry read at one store view is never handed back for another', function () {
    var cache = cacheModule.create();

    cache.remember(3, 'config:general/store_information/name', {title: 'Store name'});

    assertSame(cache.entry(1, 'config:general/store_information/name'), null, 'a different store view');
    assertSame(cache.entry(0, 'config:general/store_information/name'), null, 'the default scope');
});

test('remembering at a different store view empties everything held for the old one', function () {
    var cache = cacheModule.create();

    cache.remember(3, 'config:general/store_information/name', {title: 'Store name'});
    cache.remember(3, 'var:order.increment_id', {title: 'Order number'});
    assertSame(cache.size(), 2, 'both held');

    cache.remember(1, 'var:order.increment_id', {title: 'Order number'});

    assertSame(cache.size(), 1, 'only the new one survives');
    assertSame(cache.scope(), 1, 'the cache now belongs to the new store view');
    assertSame(cache.entry(1, 'config:general/store_information/name'), null, 'the old entry is gone');
});

test('forgetting drops every entry and the store view with them', function () {
    var cache = cacheModule.create();

    cache.remember(3, 'var:order.increment_id', {title: 'Order number'});
    cache.forget();

    assertSame(cache.size(), 0, 'nothing held');
    assertSame(cache.scope(), null, 'no store view either');
    assertSame(cache.entry(3, 'var:order.increment_id'), null, 'the entry is gone');
});

test('the modifier list survives a store switch and an emptying', function () {
    var cache = cacheModule.create(),
        modifiers = [{name: 'escape'}, {name: 'nl2br'}, {name: 'raw'}];

    cache.rememberModifiers(modifiers);
    cache.remember(3, 'var:order.increment_id', {title: 'Order number'});
    cache.remember(1, 'var:order.increment_id', {title: 'Order number'});
    cache.forget();

    assertLike(cache.modifiers(), modifiers, 'still known');
});

test('the modifier list is nothing until it has been seen once', function () {
    var cache = cacheModule.create();

    assertSame(cache.modifiers(), null, 'never fetched');

    cache.rememberModifiers('escape,nl2br');
    assertSame(cache.modifiers(), null, 'something that is not a list is not a list');
});

test('a store view given as text is the same store view', function () {
    var cache = cacheModule.create();

    cache.remember('3', 'var:order.increment_id', {title: 'Order number'});

    assertLike(cache.entry(3, 'var:order.increment_id'), {title: 'Order number'}, 'read as a number');
});

test('nothing is remembered without a store view, a reference and an answer', function () {
    var cache = cacheModule.create();

    cache.remember(null, 'var:order.increment_id', {title: 'Order number'});
    cache.remember(3, '', {title: 'Order number'});
    cache.remember(3, 'var:order.increment_id', null);

    assertSame(cache.size(), 0, 'none of the three were kept');
});
