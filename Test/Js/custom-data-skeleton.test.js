/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The sample-data skeleton built from a template's directives.
 *
 * The rule being proved is that a directive asks for its variables however it is laid out. A
 * {{trans}} wrapped over several lines is ordinary in a long email, and its parameters used to be
 * missing from the skeleton because the directive was only looked for within one line.
 */
var harness = require('./harness'),
    test = harness.test,
    assertLike = harness.assertLike,
    assertSame = harness.assertSame,
    skeleton = harness.loadPureModule('email-editor/custom-data-skeleton.js');

test('a directive written over several lines still asks for its parameters', function () {
    var template = '<p>{{trans\n    "%name, your order %id is ready"\n    name=$customer.name\n    id=$order.increment_id\n}}</p>';

    assertLike(
        JSON.parse(skeleton.build(template, '')),
        {customer: {name: ''}, order: {increment_id: ''}},
        'skeleton of a multi-line trans'
    );
});

test('leading variables of var, if and depend become nested placeholders', function () {
    assertLike(
        JSON.parse(skeleton.build('{{var order.id}}{{if\n  customer.email}}x{{/if}}{{depend note}}y{{/depend}}', '')),
        {order: {id: ''}, customer: {email: ''}, note: ''},
        'skeleton of leading variables'
    );
});

test('a parameter that calls a method and the template object are not offered as data', function () {
    assertSame(skeleton.build('{{var this.getUrl()}}{{trans "x" y=$a.b()}}', ''), '', 'nothing asked for');
});

test('values already entered are kept for variables still referenced and dropped for the rest', function () {
    assertLike(
        JSON.parse(skeleton.build('{{var order.id}}', JSON.stringify({order: {id: '42'}, gone: 'x'}))),
        {order: {id: '42'}},
        'merged skeleton'
    );
});

test('a template that asks for nothing keeps what was entered', function () {
    assertSame(skeleton.build('<p>static</p>', '{"a":1}'), '{"a":1}', 'entered data kept');
    assertSame(skeleton.build('{{var x}}', 'not json'), JSON.stringify({x: ''}, null, 2), 'unreadable entry replaced');
});
