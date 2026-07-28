/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What the variable chooser makes of the answer it is given.
 *
 * The rule with the longest reach is the last one proved here: a row's reference and the reference
 * the scanner derives from that row's own directive are the same string. That is the whole claim
 * the chooser exists to support - a variable picked out of the panel and the same variable already
 * written in the template are one entry, described one way, not two entries that happen to look
 * alike. The rows below are shaped exactly as the server sends them, quoting and all, because the
 * two sources it merges disagree about quoting and that disagreement is where this can silently
 * fail.
 *
 * The rest is about identity: a group is keyed by its code and read by its label, and narrowing the
 * list by a search must not drop the code, or every collapsed group would spring open the moment an
 * author typed into the search box.
 */
var harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    groups = harness.loadPureModule('email-editor/variable-groups.js'),
    scanner = harness.loadPureModule('email-editor/knowledge/directive-scanner.js'),

    /**
     * The three rows the server builds, as it builds them
     *
     * The configuration row's parameter is quoted because Magento's own source quotes it; the custom
     * variable row's is bare because this module writes it bare. Both spellings are here so that the
     * agreement below is proved in both directions rather than in the convenient one.
     */
    SERVER_ANSWER = [
        {
            code: 'system',
            label: 'System Variables',
            variables: [
                {
                    label: 'Store Name',
                    value: '{{config path="general/store_information/name"}}',
                    reference: 'config:general/store_information/name'
                }
            ]
        },
        {
            code: 'custom',
            label: 'Custom Variables',
            variables: [
                {
                    label: 'Support hours',
                    value: '{{customVar code=support_hours}}',
                    reference: 'customVar:support_hours'
                }
            ]
        },
        {
            code: 'template',
            label: 'Template Variables',
            variables: [
                {
                    label: 'Order Id',
                    value: '{{var order.increment_id}}',
                    reference: 'var:order.increment_id'
                },
                {
                    label: 'Shipping Address',
                    value: '{{var formattedShippingAddress|raw}}',
                    reference: 'var:formattedShippingAddress'
                }
            ]
        }
    ];

/**
 * The reference the scanner derives from a directive standing on its own
 *
 * @param {string} directive
 * @return {string}
 */
function scannedReference(directive) {
    var occurrences = scanner.scan(directive);

    if (occurrences.length !== 1) {
        throw new Error('expected exactly one directive in ' + JSON.stringify(directive));
    }

    return occurrences[0].reference;
}

/**
 * Every row of every group, flattened
 *
 * @param {Array} normalised
 * @return {Array}
 */
function rowsOf(normalised) {
    var rows = [];

    normalised.forEach(function (group) {
        group.variables.forEach(function (variable) {
            rows.push(variable);
        });
    });

    return rows;
}

test('a row carries the reference the scanner derives from the directive it inserts', function () {
    rowsOf(groups.normalise(SERVER_ANSWER)).forEach(function (row) {
        assertSame(
            row.reference,
            scannedReference(row.value),
            'the chooser and the content agree about ' + row.value
        );
    });
});

test('the answer is read into groups that keep their code, label and rows', function () {
    var normalised = groups.normalise(SERVER_ANSWER);

    assertSame(normalised.length, 3, 'three groups');
    assertLike(
        normalised.map(function (group) {
            return group.code;
        }),
        ['system', 'custom', 'template'],
        'the codes'
    );
    assertLike(
        normalised[1],
        {
            code: 'custom',
            label: 'Custom Variables',
            variables: [
                {
                    label: 'Support hours',
                    value: '{{customVar code=support_hours}}',
                    reference: 'customVar:support_hours'
                }
            ]
        },
        'one group in full'
    );
});

test('a group with no code is dropped rather than given one', function () {
    assertSame(
        groups.normalise([{label: 'Nameless', variables: [{value: '{{var x}}'}]}]).length,
        0,
        'nothing to remember it by'
    );
});

test('a group falls back to its code when the answer named it nothing', function () {
    assertSame(
        groups.normalise([{code: 'system', variables: [{value: '{{var x}}'}]}])[0].label,
        'system',
        'readable rather than blank'
    );
});

test('a row that inserts nothing is not a row, and a group of them is not a group', function () {
    assertSame(
        groups.normalise([{code: 'system', variables: [{label: 'Nothing to insert'}, 'not a row', null]}]).length,
        0,
        'the group goes with its rows'
    );
});

test('a row is read with its label and reference absent rather than refused', function () {
    assertLike(
        groups.normalise([{code: 'system', variables: [{value: '{{var x}}'}]}])[0].variables[0],
        {label: '', value: '{{var x}}', reference: ''},
        'insertable, with nothing to explain'
    );
});

test('an answer that is not a list of groups yields no groups', function () {
    assertSame(groups.normalise(undefined).length, 0, 'nothing at all');
    assertSame(groups.normalise({system: []}).length, 0, 'the shape this used to be sent in');
    assertSame(groups.normalise('system').length, 0, 'text');
});

test('a search narrows the rows and keeps the code of every group it keeps', function () {
    var narrowed = groups.filter(groups.normalise(SERVER_ANSWER), 'increment');

    assertSame(narrowed.length, 1, 'one group survives');
    assertSame(narrowed[0].code, 'template', 'and it is still that group');
    assertLike(
        narrowed[0].variables.map(function (variable) {
            return variable.reference;
        }),
        ['var:order.increment_id'],
        'with only the matching row'
    );
});

test('a search matches what a row reads as, not only what it inserts', function () {
    var narrowed = groups.filter(groups.normalise(SERVER_ANSWER), 'support hours');

    assertSame(narrowed.length, 1, 'one group');
    assertSame(narrowed[0].variables[0].value, '{{customVar code=support_hours}}', 'found by its label');
});

test('a search is blind to case, in the label and in the directive alike', function () {
    assertSame(groups.filter(groups.normalise(SERVER_ANSWER), 'STORE_INFORMATION').length, 1, 'the directive');
    assertSame(groups.filter(groups.normalise(SERVER_ANSWER), 'Order ID').length, 1, 'the label');
});

test('an empty search returns the groups untouched', function () {
    var normalised = groups.normalise(SERVER_ANSWER);

    assertSame(groups.filter(normalised, ''), normalised, 'the very same list');
    assertSame(groups.filter(normalised, null), normalised, 'nothing typed yet');
});

test('a search nothing matches leaves no group standing', function () {
    assertSame(groups.filter(groups.normalise(SERVER_ANSWER), 'nothing here').length, 0, 'no group');
});

test('the count is every row of every group', function () {
    assertSame(groups.countVariables(groups.normalise(SERVER_ANSWER)), 4, 'four rows');
    assertSame(groups.countVariables([]), 0, 'no groups');
    assertSame(groups.countVariables(null), 0, 'no list at all');
});
