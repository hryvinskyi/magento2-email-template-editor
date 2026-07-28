/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What a row of the modifier list must carry before anything binds to it.
 *
 * The rule the whole file is about: a row carries every property, whatever the descriptor behind it
 * looked like. A binding inside the list is evaluated in the row's own scope, so a name the row
 * happens not to carry is an unresolved name and the binding throws - and a binding that throws
 * does not lose a row, it takes down the component the row was drawn in. The descriptors are server
 * data, which is to say a field may simply not be there, so the shapes below are the shapes a
 * response can really have rather than shapes chosen to be awkward.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertTrue = harness.assertTrue,
    assertThrows = harness.assertThrows,
    rows = harness.loadPureModule('email-editor/knowledge/modifier-rows.js');

/**
 * A stand-in for an observable: something that holds a value and can be called for it
 *
 * @param {*} initial
 * @return {Function}
 */
function held(initial) {
    var value = initial;

    return function (next) {
        if (arguments.length > 0) {
            value = next;
        }

        return value;
    };
}

/**
 * The properties a row actually carries, in order
 *
 * @param {Object} row
 * @return {string[]}
 */
function propertiesOf(row) {
    return Object.keys(row).sort();
}

test('a row carries every published property even when the descriptor carried almost none', function () {
    var built = rows.build([{name: 'raw'}], held);

    assertSame(built.length, 1, 'one row');
    assertLike(propertiesOf(built[0]), rows.PROPERTIES.slice().sort(), 'nothing is left off');
});

test('every property a bare descriptor produces is of its declared type', function () {
    var row = rows.build([{name: 'raw'}], held)[0];

    assertSame(row.name, 'raw', 'the name it was published under');
    assertSame(row.label, 'raw', 'its own name, when the server worded none');
    assertSame(row.description, '', 'a string, not a missing property');
    assertSame(row.implemented, true, 'implemented unless the server said otherwise');
    assertLike(row.options, [], 'a list, not a missing property');
    assertSame(row.defaultArgument, '', 'a string, not a missing property');
    assertSame(row.applied(), false, 'not in use until the document says so');
    assertSame(row.argument(), '', 'nothing chosen yet');
});

test('a descriptor with no name is not a row at all', function () {
    var built = rows.build([
        {name: 'escape'},
        {label: 'Nameless'},
        {name: ''},
        {name: 42},
        null,
        {name: 'raw'}
    ], held);

    assertLike(
        built.map(function (row) {
            return row.name;
        }),
        ['escape', 'raw'],
        'only the ones that could be written into a chain'
    );
});

test('the published order is the order, and nothing is added or dropped for being unimplemented', function () {
    var built = rows.build([
        {name: 'escape', implemented: true},
        {name: 'nl2br', implemented: false},
        {name: 'raw', implemented: true}
    ], held);

    assertLike(
        built.map(function (row) {
            return row.name;
        }),
        ['escape', 'nl2br', 'raw'],
        'as published'
    );
    assertSame(built[1].implemented, false, 'said to be unimplemented, still listed');
});

test('the first argument specification becomes the options and the fallback', function () {
    var row = rows.build([{
        name: 'date',
        label: 'Date',
        description: 'Formats a date.',
        arguments: [{'default': 'medium', options: ['short', 'medium', 'long']}]
    }], held)[0];

    assertLike(row.options, ['short', 'medium', 'long'], 'what may be chosen');
    assertSame(row.defaultArgument, 'medium', 'what the server calls it without an argument');
    assertSame(row.argument(), 'medium', 'the row starts on the default');
    assertSame(row.label, 'Date', 'the published wording wins over the name');
    assertSame(row.description, 'Formats a date.', 'the published description');
});

test('an option that is not text is not an option', function () {
    var row = rows.build([{
        name: 'date',
        arguments: [{options: ['short', 7, null, {value: 'long'}, 'long']}]
    }], held)[0];

    assertLike(row.options, ['short', 'long'], 'only what could be written into a template');
});

test('an argument specification of a shape nobody publishes still leaves a usable row', function () {
    var shapes = [
            {name: 'a', arguments: 'medium'},
            {name: 'b', arguments: []},
            {name: 'c', arguments: [null]},
            {name: 'd', arguments: [{options: 'short,long'}]},
            {name: 'e', arguments: [{'default': 3}]}
        ],
        built = rows.build(shapes, held);

    assertSame(built.length, shapes.length, 'every one is still a row');

    built.forEach(function (row) {
        assertLike(propertiesOf(row), rows.PROPERTIES.slice().sort(), 'row ' + row.name + ' is whole');
        assertLike(row.options, [], 'row ' + row.name + ' offers nothing to choose');
        assertSame(row.defaultArgument, '', 'row ' + row.name + ' has no fallback');
    });
});

test('a published list that is not a list produces no rows rather than an error', function () {
    assertLike(rows.build(null, held), [], 'nothing published yet');
    assertLike(rows.build('escape,raw', held), [], 'something that is not a list');
    assertLike(rows.build({escape: {}}, held), [], 'an object keyed by name');
});

test('rows cannot be built without a way to make the two fields that change', function () {
    var thrown = assertThrows(function () {
        rows.build([{name: 'escape'}]);
    }, 'no factory');

    assertTrue(
        thrown.message.indexOf('observable') !== -1,
        'the message says what is missing, got ' + JSON.stringify(thrown.message)
    );
});

test('each row gets fields of its own, so setting one row does not set another', function () {
    var built = rows.build([{name: 'escape'}, {name: 'raw'}], held);

    built[0].applied(true);
    built[0].argument('x');

    assertSame(built[1].applied(), false, 'the other row is untouched');
    assertSame(built[1].argument(), '', 'and so is its argument');
});
