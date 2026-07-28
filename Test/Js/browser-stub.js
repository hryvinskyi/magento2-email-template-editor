/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Just enough of a browser for an adapter to run against, and no more.
 *
 * What is here is what the module's adapters actually call: adding and removing a listener, walking
 * up from a node to its parent, asking an element whether it carries a class, the two window
 * measurements the popover is placed against, and the timer the redraw waits on. Nothing lays
 * anything out, nothing draws, no listener is ever invoked except by a test saying so, and the
 * element tree is whatever a test builds by hand.
 *
 * So the whole of what this can prove is what an adapter decides and what it leaves behind. That an
 * element ends up in the right place, that a real browser dispatches in this order, and that a
 * Knockout binding against these objects renders at all, it cannot say anything about.
 *
 * The timer is deliberately not the real one. Every wait in the module is a wait for a pause in
 * typing, and a test that slept through them would be slow and would still only prove that the
 * sleep was long enough. Here time moves when a test moves it, so "the redraw has not happened yet"
 * is as checkable as "it has", and a timer left running past a teardown is visible rather than a
 * stray callback somewhere later in the run.
 */

/**
 * Make something listeners can be added to and events pushed at
 *
 * @return {Object}
 */
function createEventTarget() {
    var listeners = {};

    return {
        /**
         * Register a listener
         *
         * The capture flag is kept alongside the listener rather than acted on: nothing here
         * propagates, so capture has no meaning except that a removal must present the same flag
         * the registration did, which is exactly the mistake worth catching.
         *
         * @param {string} type
         * @param {Function} listener
         * @param {boolean} [capture]
         * @return {void}
         */
        addEventListener: function (type, listener, capture) {
            listeners[type] = listeners[type] || [];
            listeners[type].push({listener: listener, capture: capture === true});
        },

        /**
         * Drop a listener registered with the same function and the same capture flag
         *
         * @param {string} type
         * @param {Function} listener
         * @param {boolean} [capture]
         * @return {void}
         */
        removeEventListener: function (type, listener, capture) {
            var registered = listeners[type] || [],
                index;

            for (index = 0; index < registered.length; index++) {
                if (registered[index].listener === listener &&
                    registered[index].capture === (capture === true)
                ) {
                    registered.splice(index, 1);

                    return;
                }
            }
        },

        /**
         * How many listeners are registered for a type
         *
         * @param {string} type
         * @return {number}
         */
        listenerCount: function (type) {
            return (listeners[type] || []).length;
        },

        /**
         * Hand an event to every listener registered for its type
         *
         * @param {string} type
         * @param {Object} [event]
         * @return {Object} the event, as the listeners left it
         */
        dispatch: function (type, event) {
            var payload = event || {},
                registered = (listeners[type] || []).slice(),
                index;

            for (index = 0; index < registered.length; index++) {
                registered[index].listener(payload);
            }

            return payload;
        }
    };
}

/**
 * Make an element: a class list, a parent and somewhere to hang listeners
 *
 * @param {string} [className] the classes it carries, separated by spaces
 * @return {Object}
 */
function createElement(className) {
    var element = createEventTarget(),
        classes = (className || '').split(' ').filter(function (name) {
            return name !== '';
        });

    element.parentNode = null;

    element.classList = {
        /**
         * @param {string} name
         * @return {boolean}
         */
        contains: function (name) {
            return classes.indexOf(name) !== -1;
        },

        /**
         * @param {string} name
         * @return {void}
         */
        add: function (name) {
            if (classes.indexOf(name) === -1) {
                classes.push(name);
            }
        },

        /**
         * @param {string} name
         * @return {void}
         */
        remove: function (name) {
            var index = classes.indexOf(name);

            if (index !== -1) {
                classes.splice(index, 1);
            }
        }
    };

    /**
     * Put this element under another one
     *
     * @param {Object} parent
     * @return {Object} this element
     */
    element.appendTo = function (parent) {
        element.parentNode = parent;

        return element;
    };

    return element;
}

/**
 * Make a clock that only moves when a test moves it
 *
 * @return {Object}
 */
function createClock() {
    var scheduled = [],
        now = 0,
        nextId = 1;

    return {
        /**
         * @param {Function} body
         * @param {number} [delay]
         * @return {number} the handle to cancel it with
         */
        setTimeout: function (body, delay) {
            var id = nextId++;

            scheduled.push({
                id: id,
                due: now + (typeof delay === 'number' ? delay : 0),
                body: body
            });

            return id;
        },

        /**
         * @param {number} id
         * @return {void}
         */
        clearTimeout: function (id) {
            var index;

            for (index = 0; index < scheduled.length; index++) {
                if (scheduled[index].id === id) {
                    scheduled.splice(index, 1);

                    return;
                }
            }
        },

        /**
         * Move time forward, running whatever comes due, in the order it comes due
         *
         * @param {number} milliseconds
         * @return {void}
         */
        tick: function (milliseconds) {
            var target = now + milliseconds,
                due;

            while (true) {
                due = scheduled.filter(function (entry) {
                    return entry.due <= target;
                }).sort(function (left, right) {
                    return left.due - right.due || left.id - right.id;
                })[0];

                if (!due) {
                    break;
                }

                scheduled.splice(scheduled.indexOf(due), 1);
                now = due.due;
                due.body();
            }

            now = target;
        },

        /**
         * How many timers are waiting to run
         *
         * @return {number}
         */
        pending: function () {
            return scheduled.length;
        }
    };
}

/**
 * Make a console that records rather than prints
 *
 * @return {Object}
 */
function createConsole() {
    var warnings = [];

    return {
        /**
         * @param {string} message
         * @return {void}
         */
        warn: function (message) {
            warnings.push(message);
        },

        /**
         * @return {string[]} every warning, in order
         */
        warnings: function () {
            return warnings.slice();
        }
    };
}

/**
 * Make a browser: a document, a window, a clock and a console
 *
 * @param {Object} [options] what the window carries besides its listeners
 * @param {number} [options.innerWidth]
 * @param {number} [options.innerHeight]
 * @param {Object} [options.emailEditorConfig] what the page publishes to its scripts
 * @return {Object}
 */
function create(options) {
    var settings = options || {},
        window = createEventTarget(),
        document = createEventTarget(),
        clock = createClock(),
        console = createConsole();

    window.innerWidth = typeof settings.innerWidth === 'number' ? settings.innerWidth : 1400;
    window.innerHeight = typeof settings.innerHeight === 'number' ? settings.innerHeight : 900;

    if (Object.prototype.hasOwnProperty.call(settings, 'emailEditorConfig')) {
        window.emailEditorConfig = settings.emailEditorConfig;
    }

    return {
        window: window,
        document: document,
        clock: clock,
        console: console,

        /**
         * What a module evaluated against this browser is allowed to see
         *
         * @return {Object}
         */
        globals: function () {
            return {
                window: window,
                document: document,
                console: console,
                setTimeout: clock.setTimeout,
                clearTimeout: clock.clearTimeout
            };
        }
    };
}

module.exports = {
    create: create,
    createElement: createElement,
    createEventTarget: createEventTarget
};
