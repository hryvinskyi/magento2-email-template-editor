/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Parent resolution for uiComponents.
 *
 * Single charter: given a component, hand it its parent. Nothing here knows what a
 * parent is asked for, and no rule about the editor's data belongs in this module.
 *
 * Several children of the editor carry their own private copy of this lookup; this
 * module exists so they can share one implementation, and is intended to replace
 * those copies as each child is migrated to it.
 */
define([
    'uiRegistry'
], function (registry) {
    'use strict';

    /**
     * Property on the component under which the resolved parent is memoised.
     *
     * Only the parent reference is ever remembered here. Anything derived from the
     * parent - ids, configuration, observable values - changes while the page is
     * open, so caching a derivative would hand callers a permanently stale read.
     *
     * @type {string}
     */
    var PARENT_REF = '_parentRef';

    return {
        /**
         * Resolve the parent of a component, waiting for it if it is not registered yet.
         *
         * The callback runs once, as soon as the parent is available; it may run
         * synchronously if the parent is already known.
         *
         * @param {Object} component - The uiComponent asking for its parent.
         * @param {Function} callback - Receives the parent component.
         * @return {void}
         */
        resolve: function (component, callback) {
            if (!component || typeof callback !== 'function') {
                return;
            }

            if (component[PARENT_REF]) {
                callback(component[PARENT_REF]);

                return;
            }

            if (!component.parentName) {
                return;
            }

            registry.get(component.parentName, function (parent) {
                component[PARENT_REF] = parent;
                callback(parent);
            });
        },

        /**
         * Return the parent of a component right now, or null if it is not registered yet.
         *
         * This synchronous form exists for callers that cannot become asynchronous - a
         * method that must keep returning a value (such as the jQuery promise of a
         * request it fires) cannot wait for a callback without changing its contract for
         * everyone chaining onto it. The ui registry supports this: asked for a name
         * without a callback it performs a plain lookup and returns the item or nothing.
         *
         * Never throws, never queues a request, never returns a promise.
         *
         * @param {Object} component - The uiComponent asking for its parent.
         * @return {Object|null} The parent component, or null while it is unavailable.
         */
        peek: function (component) {
            var parent;

            if (!component) {
                return null;
            }

            if (component[PARENT_REF]) {
                return component[PARENT_REF];
            }

            if (!component.parentName) {
                return null;
            }

            parent = registry.get(component.parentName);

            if (!parent) {
                return null;
            }

            component[PARENT_REF] = parent;

            return parent;
        }
    };
});
