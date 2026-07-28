/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Observables and computeds, and nothing else Knockout does.
 *
 * A component's own logic is written against three behaviours: a value that can be read and
 * written, a value derived from others that keeps itself current, and the dependency between them
 * being discovered by reading rather than declared. Those three are what is implemented here, and
 * they are enough to say whether a component recomputes what it should and leaves alone what it
 * should not - which is the difference between a list that keeps its scroll position while a
 * checkbox is ticked and one that jumps away from the control that was just pressed.
 *
 * Bindings are not here, and neither is the DOM half of Knockout. Nothing in this file renders a
 * template, so no test using it can say that a template binds correctly - only that the values a
 * template would bind to are the right ones. What a template does with them is checked by reading
 * the template.
 *
 * Change notification follows the same rule the real one does: a write of a value identical to the
 * one held changes nothing and tells nobody, and identity is what counts, so writing a second empty
 * array is a change.
 */

/**
 * Make an independent Knockout stand-in, with its own dependency stack
 *
 * One per test: a stack shared between two components would let a read in one be recorded as a
 * dependency of the other.
 *
 * @return {Object}
 */
function create() {
    var contexts = [];

    /**
     * Record a read against whatever computed is currently evaluating, if any
     *
     * @param {Function} accessor
     * @return {void}
     */
    function record(accessor) {
        if (contexts.length > 0) {
            contexts[contexts.length - 1].push(accessor);
        }
    }

    /**
     * Somewhere to hang change listeners
     *
     * @return {Object}
     */
    function createSubscribers() {
        var listeners = [];

        return {
            /**
             * @param {Function} listener
             * @return {{dispose: Function}}
             */
            subscribe: function (listener) {
                listeners.push(listener);

                return {
                    /**
                     * @return {void}
                     */
                    dispose: function () {
                        var index = listeners.indexOf(listener);

                        if (index !== -1) {
                            listeners.splice(index, 1);
                        }
                    }
                };
            },

            /**
             * @param {*} value
             * @return {void}
             */
            notify: function (value) {
                listeners.slice().forEach(function (listener) {
                    listener(value);
                });
            },

            /**
             * @return {number}
             */
            count: function () {
                return listeners.length;
            }
        };
    }

    /**
     * A value that can be read, written and watched
     *
     * @param {*} [initial]
     * @return {Function}
     */
    function observable(initial) {
        var value = initial,
            subscribers = createSubscribers();

        /**
         * @param {*} [next] read with no argument, write with one
         * @return {*} the value on a read, the accessor on a write
         */
        function accessor(next) {
            if (arguments.length === 0) {
                record(accessor);

                return value;
            }

            if (next !== value) {
                value = next;
                subscribers.notify(value);
            }

            return accessor;
        }

        accessor.subscribe = subscribers.subscribe;

        /**
         * Read without being recorded as a dependency
         *
         * @return {*}
         */
        accessor.peek = function () {
            return value;
        };

        /**
         * How many listeners are watching this
         *
         * @return {number}
         */
        accessor.subscriberCount = subscribers.count;

        return accessor;
    }

    /**
     * A value worked out from others, kept current as they change
     *
     * @param {Function} evaluator
     * @return {Function}
     */
    function computed(evaluator) {
        var value,
            subscribers = createSubscribers(),
            subscriptions = [],
            evaluations = 0,
            running = false;

        /**
         * @return {*}
         */
        function accessor() {
            record(accessor);

            return value;
        }

        /**
         * Work the value out again and follow whatever it read this time
         *
         * @param {boolean} announce
         * @return {void}
         */
        function evaluate(announce) {
            var collected = [],
                seen = [];

            if (running) {
                return;
            }

            running = true;
            contexts.push(collected);

            try {
                value = evaluator();
                evaluations++;
            } finally {
                contexts.pop();
                running = false;
            }

            subscriptions.forEach(function (subscription) {
                subscription.dispose();
            });
            subscriptions = [];

            collected.forEach(function (dependency) {
                if (seen.indexOf(dependency) !== -1) {
                    return;
                }

                seen.push(dependency);
                subscriptions.push(dependency.subscribe(function () {
                    evaluate(true);
                }));
            });

            if (announce) {
                subscribers.notify(value);
            }
        }

        accessor.subscribe = subscribers.subscribe;

        /**
         * @return {*}
         */
        accessor.peek = function () {
            return value;
        };

        /**
         * How many times this has been worked out
         *
         * The one thing a test cannot ask a real computed, and the one thing worth asking: a list
         * rebuilt when nothing it depends on changed is a list whose controls are torn down and
         * recreated under the pointer.
         *
         * @return {number}
         */
        accessor.evaluations = function () {
            return evaluations;
        };

        evaluate(false);

        return accessor;
    }

    return {
        observable: observable,
        observableArray: observable,
        computed: computed
    };
}

module.exports = {
    create: create
};
