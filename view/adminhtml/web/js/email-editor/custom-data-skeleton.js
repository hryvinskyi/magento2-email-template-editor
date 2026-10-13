/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The sample-data JSON skeleton a template's directives ask for.
 *
 * Single charter: template text and the sample values already entered in, pretty-printed JSON out.
 * Nothing here touches an editor or the network.
 *
 * Each `{{...}}` directive is inspected for both the leading variable of `var`/`if`/`elseif`/
 * `depend` directives and any `$object.property` reference (e.g. trans parameters such as
 * `{{trans "Hi %name" name=$customer.name}}`). Variables become nested objects - `order.increment_id`
 * becomes `{"order": {"increment_id": ""}}` - and values already entered for a variable that is
 * still referenced are kept.
 *
 * A directive runs from `{{` to the first `}}` after it, across line breaks, because that is where
 * the renderer ends it; a long `{{trans}}` wrapped over several lines asks for its parameters like
 * any other.
 */
define([], function () {
    'use strict';

    var DIRECTIVE = /\{\{([\s\S]*?)\}\}/g,
        LEAD = /^\s*(?:var|if|elseif|depend)\s+([a-zA-Z_][a-zA-Z0-9_.]*)/,
        DOLLAR = /\$([a-zA-Z_][a-zA-Z0-9_.]*)/g;

    /**
     * Whether a token is a plain data variable path that JSON can hold, not a call or a helper
     *
     * @param {string} path
     * @return {boolean}
     */
    function isPlainVariablePath(path) {
        if (!path || path.indexOf('(') !== -1 || path.indexOf(' ') !== -1) {
            return false;
        }

        if (path.split('.')[0] === 'this') {
            return false;
        }

        return /^[a-zA-Z_][a-zA-Z0-9_.]*$/.test(path);
    }

    /**
     * Put an empty placeholder at a dotted path, creating the objects on the way, overwriting none
     *
     * @param {Object} target
     * @param {Array<string>} parts
     * @return {void}
     */
    function assignVariablePath(target, parts) {
        var current = target,
            index,
            key;

        for (index = 0; index < parts.length; index++) {
            key = parts[index];

            if (!key) {
                return;
            }

            if (index === parts.length - 1) {
                if (current[key] === undefined) {
                    current[key] = '';
                }
            } else {
                if (typeof current[key] !== 'object' || current[key] === null) {
                    current[key] = {};
                }

                current = current[key];
            }
        }
    }

    /**
     * Copy previously entered values onto the skeleton for every path it still holds
     *
     * @param {Object} target
     * @param {Object} existing
     * @return {void}
     */
    function mergeExistingValues(target, existing) {
        if (!target || typeof target !== 'object' || !existing || typeof existing !== 'object') {
            return;
        }

        Object.keys(target).forEach(function (key) {
            if (existing[key] === undefined) {
                return;
            }

            if (target[key] && typeof target[key] === 'object') {
                if (existing[key] && typeof existing[key] === 'object') {
                    mergeExistingValues(target[key], existing[key]);
                }
            } else if (typeof existing[key] !== 'object') {
                target[key] = existing[key];
            }
        });
    }

    /**
     * Record the variable paths one directive body refers to
     *
     * @param {Object} data
     * @param {string} body
     * @return {void}
     */
    function collectFromBody(data, body) {
        var dollars = new RegExp(DOLLAR.source, 'g'),
            lead = body.match(LEAD),
            dollar;

        if (lead && isPlainVariablePath(lead[1])) {
            assignVariablePath(data, lead[1].split('.'));
        }

        while ((dollar = dollars.exec(body)) !== null) {
            if (body.charAt(dollar.index + dollar[0].length) === '(') {
                continue;
            }

            if (isPlainVariablePath(dollar[1])) {
                assignVariablePath(data, dollar[1].split('.'));
            }
        }
    }

    return {
        /**
         * Build the skeleton for a template, keeping values already entered
         *
         * @param {string} content template text
         * @param {string} existingJson the sample data entered so far, '' when none
         * @return {string} pretty-printed JSON, or `existingJson` when the template asks for nothing
         */
        build: function (content, existingJson) {
            var directives = new RegExp(DIRECTIVE.source, 'g'),
                source = typeof content === 'string' ? content : '',
                data = {},
                existing,
                directive;

            try {
                existing = existingJson ? JSON.parse(existingJson) : {};
            } catch (e) {
                existing = {};
            }

            while ((directive = directives.exec(source)) !== null) {
                collectFromBody(data, directive[1]);
            }

            if (Object.keys(data).length === 0) {
                return existingJson || '';
            }

            mergeExistingValues(data, existing);

            return JSON.stringify(data, null, 2);
        }
    };
});
