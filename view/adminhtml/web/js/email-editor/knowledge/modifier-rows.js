/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The rows a modifier list is drawn from.
 *
 * Single charter: turn the modifier descriptors a server published into the records one row of the
 * list is bound against. Nothing here knows what a modifier means, which of them a directive is
 * currently using, or how a chain is written back into a template - the modifier registry answers
 * the first, the document answers the second and the chain module answers the third. This module
 * holds no wording of its own either: a label is either the one the server published or the
 * modifier's own name.
 *
 * Three properties of the result are load-bearing rather than incidental.
 *
 * Every row carries every property, always. A binding inside the list is evaluated in the row's own
 * scope, so a name the row happens not to carry is an unresolved name rather than an empty value:
 * the binding throws, and it takes down the whole component it was drawn in - not just the row. A
 * descriptor is data that arrived over the wire and may be missing any field, so each field is
 * given a value of its declared type here rather than left off. That is why the property list is
 * published: it is the contract a template binding inside the list may rely on, and it is checkable
 * against the template.
 *
 * A descriptor with no name is not a row. The name is the whole of what a formatting change is
 * written as, so a row without one is a control whose only possible outcome is a refusal.
 *
 * Nothing but strings survives into the arguments a row can offer. An option is written into the
 * template verbatim, and a value of any other type has no spelling to be written as; it would reach
 * the document as whatever its `toString` happened to produce and leave a directive that cannot be
 * read back.
 */
define([], function () {
    'use strict';

    /**
     * Every property a row carries, whatever the descriptor behind it looked like
     *
     * @type {string[]}
     */
    var PROPERTIES = [
        'name',
        'label',
        'description',
        'implemented',
        'options',
        'defaultArgument',
        'applied',
        'argument'
    ];

    /**
     * The value as text, or nothing at all
     *
     * @param {*} value
     * @return {string}
     */
    function textOf(value) {
        return typeof value === 'string' ? value : '';
    }

    /**
     * The specification of a modifier's first argument, if it publishes one
     *
     * Only the first is read: the chain a row can write carries a single chosen argument, so a
     * descriptor declaring more of them has nothing here that could offer the rest.
     *
     * @param {Object} descriptor
     * @return {Object|null}
     */
    function argumentSpec(descriptor) {
        var declared = descriptor.arguments;

        if (Object.prototype.toString.call(declared) !== '[object Array]') {
            return null;
        }

        return declared.length > 0 && declared[0] && typeof declared[0] === 'object' ? declared[0] : null;
    }

    /**
     * The arguments a row may offer, as text
     *
     * @param {Object|null} spec
     * @return {string[]}
     */
    function optionsOf(spec) {
        var published = spec ? spec.options : null;

        if (Object.prototype.toString.call(published) !== '[object Array]') {
            return [];
        }

        return published.filter(function (option) {
            return typeof option === 'string';
        });
    }

    /**
     * Build the rows for a published modifier list
     *
     * @param {Array.<Object>} descriptors as the server published them, in the order it published
     * @param {Function} observableFactory makes the two fields a row changes while it is on screen
     * @return {Array.<Object>} one row per named descriptor, each carrying every published property
     */
    function build(descriptors, observableFactory) {
        var list = Object.prototype.toString.call(descriptors) === '[object Array]' ? descriptors : [],
            rows = [],
            index,
            descriptor,
            spec,
            fallback;

        if (typeof observableFactory !== 'function') {
            throw new Error('Modifier rows need a way to make an observable, or nothing can bind to them.');
        }

        for (index = 0; index < list.length; index++) {
            descriptor = list[index] && typeof list[index] === 'object' ? list[index] : {};

            if (textOf(descriptor.name) === '') {
                continue;
            }

            spec = argumentSpec(descriptor);
            fallback = spec ? textOf(spec['default']) : '';

            rows.push({
                name: descriptor.name,
                label: textOf(descriptor.label) || descriptor.name,
                description: textOf(descriptor.description),
                implemented: descriptor.implemented !== false,
                options: optionsOf(spec),
                defaultArgument: fallback,
                applied: observableFactory(false),
                argument: observableFactory(fallback)
            });
        }

        return rows;
    }

    return {
        PROPERTIES: PROPERTIES,
        build: build
    };
});
