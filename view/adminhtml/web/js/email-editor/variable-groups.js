/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The variable chooser's groups, as data rather than as a panel.
 *
 * Single charter: read the answer the server sent into the shape the panel binds, narrow it to what
 * a search matches, and count it. Nothing here knows about Knockout, about a request, or about how a
 * group is drawn - which is what lets every rule below be proved without a browser.
 *
 * A group is identified by its code and read by its label, and the two are never the same string.
 * The label is translated, so anything that keyed on it - the panel's collapsed-state map is the one
 * that mattered - would forget its state in every language but English, and would key two groups
 * together the day two of them were translated alike. The code survives translation and is carried
 * through every one of these functions for that reason: a narrowed group is still the same group,
 * and it stays open or collapsed as it was.
 */
define([], function () {
    'use strict';

    /**
     * Is this a usable object to read fields off?
     *
     * @param {*} value
     * @return {boolean}
     */
    function isObject(value) {
        return typeof value === 'object' && value !== null;
    }

    /**
     * Is this an array, whichever context built it?
     *
     * @param {*} value
     * @return {boolean}
     */
    function isArray(value) {
        return Object.prototype.toString.call(value) === '[object Array]';
    }

    /**
     * A field read as a string, with anything else read as absent
     *
     * @param {*} value
     * @return {string}
     */
    function asText(value) {
        return typeof value === 'string' ? value : '';
    }

    /**
     * Read one row of a group
     *
     * The directive to insert is the one thing a row cannot do without: a row that inserts nothing
     * is not a row, however well it reads. The reference may legitimately be absent - the server
     * sends none for a directive whose identity it will not guess at - and a row without one is
     * offered for insertion exactly like any other, with nothing to explain about it.
     *
     * @param {*} raw
     * @return {{label: string, value: string, reference: string}|null}
     */
    function readVariable(raw) {
        var value;

        if (!isObject(raw)) {
            return null;
        }

        value = asText(raw.value);

        if (value === '') {
            return null;
        }

        return {
            label: asText(raw.label),
            value: value,
            reference: asText(raw.reference)
        };
    }

    /**
     * Read one group
     *
     * A group with no code is dropped rather than given one: the code is what its collapsed state is
     * remembered under and what a later answer is matched against, and an invented code would be a
     * different group on every load. A group whose rows are all unusable is dropped too - a heading
     * with nothing under it says only that something went wrong somewhere else.
     *
     * @param {*} raw
     * @return {{code: string, label: string, variables: Array}|null}
     */
    function readGroup(raw) {
        var code,
            variables = [],
            index,
            variable;

        if (!isObject(raw)) {
            return null;
        }

        code = asText(raw.code);

        if (code === '') {
            return null;
        }

        if (isArray(raw.variables)) {
            for (index = 0; index < raw.variables.length; index++) {
                variable = readVariable(raw.variables[index]);

                if (variable) {
                    variables.push(variable);
                }
            }
        }

        if (variables.length === 0) {
            return null;
        }

        return {
            code: code,
            label: asText(raw.label) === '' ? code : asText(raw.label),
            variables: variables
        };
    }

    /**
     * Read the groups out of a server answer
     *
     * @param {*} rawGroups whatever came back under `groups`
     * @return {Array.<{code: string, label: string, variables: Array}>}
     */
    function normalise(rawGroups) {
        var groups = [],
            index,
            group;

        if (!isArray(rawGroups)) {
            return groups;
        }

        for (index = 0; index < rawGroups.length; index++) {
            group = readGroup(rawGroups[index]);

            if (group) {
                groups.push(group);
            }
        }

        return groups;
    }

    /**
     * Narrow the groups to the rows a search matches
     *
     * Both what a row inserts and what it reads as are searched, because an author looking for a
     * variable knows one or the other and rarely both. A group left with no matching row is dropped
     * rather than shown empty, and a group that survives keeps its code, so narrowing the list never
     * silently reopens a group the author had collapsed.
     *
     * @param {Array} groups groups as normalise() produced them
     * @param {string} query what was typed into the search box
     * @return {Array} the same groups when nothing was typed, a narrowed copy otherwise
     */
    function filter(groups, query) {
        var needle = asText(query).toLowerCase(),
            narrowed = [],
            index,
            matching,
            matches;

        if (!isArray(groups)) {
            return [];
        }

        if (needle === '') {
            return groups;
        }

        /**
         * Does this row answer to what was typed?
         *
         * @param {{label: string, value: string}} variable
         * @return {boolean}
         */
        matches = function (variable) {
            return variable.value.toLowerCase().indexOf(needle) !== -1 ||
                variable.label.toLowerCase().indexOf(needle) !== -1;
        };

        for (index = 0; index < groups.length; index++) {
            matching = groups[index].variables.filter(matches);

            if (matching.length > 0) {
                narrowed.push({
                    code: groups[index].code,
                    label: groups[index].label,
                    variables: matching
                });
            }
        }

        return narrowed;
    }

    /**
     * How many rows there are across every group
     *
     * @param {Array} groups
     * @return {number}
     */
    function countVariables(groups) {
        var total = 0,
            index;

        if (!isArray(groups)) {
            return 0;
        }

        for (index = 0; index < groups.length; index++) {
            total += groups[index].variables.length;
        }

        return total;
    }

    return {
        normalise: normalise,
        filter: filter,
        countVariables: countVariables
    };
});
