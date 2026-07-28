/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What the browser already knows about the directives on this page.
 *
 * Single charter: remember an answer so the same directive is not asked about twice, and refuse
 * to hand back an answer that was true for a different store view. Nothing here talks to a server,
 * knows what an entry contains, or holds any wording.
 *
 * The store view is not a key, it is the whole cache's scope. An entry carries a value read at a
 * scope, and several of the affordances differ by scope too, so an entry remembered for one store
 * view is not a partial answer for another - it is a wrong answer that looks right. Asking with a
 * different store view therefore empties the cache instead of missing on one key and hitting on
 * the next.
 *
 * The modifier descriptors are the one thing that outlives that. They are the same list for every
 * directive and every scope - what the renderer implements does not change when the admin switches
 * store view - so they are held apart from the entries and survive the emptying.
 */
define([], function () {
    'use strict';

    /**
     * Is this a reference a caller may key on?
     *
     * @param {*} reference
     * @return {boolean}
     */
    function isKeyable(reference) {
        return typeof reference === 'string' && reference !== '';
    }

    /**
     * Read the store view a caller is asking about as a number
     *
     * @param {*} storeId
     * @return {number|null} null when it is not a store view at all
     */
    function normaliseScope(storeId) {
        var value = typeof storeId === 'number' ? storeId : parseInt(storeId, 10);

        return typeof value === 'number' && !isNaN(value) ? value : null;
    }

    /**
     * Start a cache holding nothing
     *
     * @return {{entry: function(*, string): (Object|null),
     *          remember: function(*, string, Object): void,
     *          forget: function(): void,
     *          rememberModifiers: function(Array): void,
     *          modifiers: function(): (Array|null),
     *          scope: function(): (number|null),
     *          size: function(): number}}
     */
    function create() {
        var scope = null,
            entries = {},
            modifiers = null;

        /**
         * Drop every entry, and the store view they were read at
         *
         * The modifier descriptors are deliberately left alone: they describe the renderer, not a
         * scope, and re-fetching them on every store switch would cost a request for an answer that
         * cannot have changed.
         *
         * @return {void}
         */
        function forget() {
            scope = null;
            entries = {};
        }

        /**
         * Point the cache at a store view, emptying it when that is a different one
         *
         * @param {number} storeId
         * @return {void}
         */
        function enter(storeId) {
            if (scope !== storeId) {
                forget();
                scope = storeId;
            }
        }

        return {
            /**
             * What is known about this reference at this store view, or nothing
             *
             * @param {*} storeId
             * @param {string} reference a canonical reference
             * @return {Object|null}
             */
            entry: function (storeId, reference) {
                var asked = normaliseScope(storeId);

                if (asked === null || asked !== scope || !isKeyable(reference)) {
                    return null;
                }

                return Object.prototype.hasOwnProperty.call(entries, reference)
                    ? entries[reference]
                    : null;
            },

            /**
             * Keep an answer, and with it the store view it is an answer for
             *
             * @param {*} storeId
             * @param {string} reference a canonical reference
             * @param {Object} value the entry as it came back
             * @return {void}
             */
            remember: function (storeId, reference, value) {
                var asked = normaliseScope(storeId);

                if (asked === null || !isKeyable(reference) || !value) {
                    return;
                }

                enter(asked);
                entries[reference] = value;
            },

            /**
             * Forget every entry
             *
             * @return {void}
             */
            forget: forget,

            /**
             * Keep the list of modifiers the renderer implements
             *
             * @param {Array} list
             * @return {void}
             */
            rememberModifiers: function (list) {
                if (Object.prototype.toString.call(list) === '[object Array]') {
                    modifiers = list;
                }
            },

            /**
             * The modifiers the renderer implements, or nothing while they have never been seen
             *
             * @return {Array|null}
             */
            modifiers: function () {
                return modifiers;
            },

            /**
             * The store view every held entry was read at, or nothing while none is held
             *
             * @return {number|null}
             */
            scope: function () {
                return scope;
            },

            /**
             * How many entries are held
             *
             * @return {number}
             */
            size: function () {
                return Object.keys(entries).length;
            }
        };
    }

    return {
        create: create
    };
});
