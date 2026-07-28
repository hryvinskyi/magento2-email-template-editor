/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The four things a uiComponent of this module is handed by the platform.
 *
 * A base class that merges its defaults and chains to its parent, a registry components find each
 * other through, a way to make a request, and a way to word something. None of it is Magento; all
 * of it behaves the way Magento's does in the respects these components depend on, and the places
 * where it deliberately does not are named below.
 *
 * The class stand-in keeps the two rules that decide whether a component initialises at all:
 * `defaults` belong to the constructor and are merged with the configuration onto the instance, and
 * `this._super()` called with no arguments passes on the arguments its caller received - which is
 * the only reason a configuration reaches a component whose own initialize takes none. It does not
 * evaluate string templates in the configuration, it does not link, and its `observe` only
 * understands a list of names.
 *
 * The request stand-in is a deferred that never touches a network. It is settled by the test, in
 * the order the test chooses, which is the whole point: the questions worth asking here are about
 * an answer that arrives after the screen moved on, an answer to a request that was cancelled, and
 * two answers racing - none of which can be staged against a real server.
 *
 * Wording is passed through unchanged. A translation is a fact about a locale file, not about this
 * code, and asserting on English sentences here would only pin down the wording; what these tests
 * assert is that two different situations do not produce one sentence.
 */

/**
 * Is this an array, whichever context it was built in?
 *
 * @param {*} value
 * @return {boolean}
 */
function isArray(value) {
    return Object.prototype.toString.call(value) === '[object Array]';
}

/**
 * Wrap a method so it can call the one it overrides
 *
 * @param {Function} parent
 * @param {Function} method
 * @return {Function}
 */
function wrapSuper(parent, method) {
    return function () {
        var previous = this._super,
            args = arguments,
            result;

        /**
         * @return {*}
         */
        this._super = function () {
            return parent.apply(this, arguments.length > 0 ? arguments : args);
        };

        result = method.apply(this, args);
        this._super = previous;

        return result;
    };
}

/**
 * Copy every own property of the source onto the target
 *
 * @param {Object} target
 * @param {Object} source
 * @return {Object} the target
 */
function assign(target, source) {
    Object.keys(source || {}).forEach(function (key) {
        target[key] = source[key];
    });

    return target;
}

/**
 * Build a constructor around a prototype
 *
 * @param {Object} prototype
 * @return {Function}
 */
function createConstructor(prototype) {
    /**
     * @return {Object}
     */
    function Constructor() {
        var instance = this;

        if (!instance || Object.getPrototypeOf(instance) !== Constructor.prototype) {
            instance = Object.create(Constructor.prototype);
        }

        instance.initialize.apply(instance, arguments);

        return instance;
    }

    Constructor.prototype = prototype;
    prototype.constructor = Constructor;

    return Constructor;
}

/**
 * Extend a constructor, wrapping every overriding method so it can reach the one below it
 *
 * @param {Object} members
 * @return {Function}
 */
function extend(members) {
    var parent = this,
        parentPrototype = parent.prototype,
        childPrototype = Object.create(parentPrototype),
        child;

    Object.keys(members || {}).forEach(function (name) {
        if (name === 'defaults') {
            return;
        }

        childPrototype[name] = typeof members[name] === 'function' &&
            typeof parentPrototype[name] === 'function'
            ? wrapSuper(parentPrototype[name], members[name])
            : members[name];
    });

    child = createConstructor(childPrototype);
    child.defaults = assign(assign({}, parent.defaults), members ? members.defaults : {});
    child.extend = extend;

    return child;
}

/**
 * Make the base class a component of this module extends
 *
 * @param {Object} ko the Knockout stand-in the observables come from
 * @return {Function}
 */
function createComponentClass(ko) {
    var base = createConstructor({
        /**
         * @param {Object} [options]
         * @return {Object} this
         */
        initialize: function (options) {
            return this.initConfig(options);
        },

        /**
         * @param {Object} [options]
         * @return {Object} this
         */
        initConfig: function (options) {
            assign(this, this.constructor.defaults);
            assign(this, options);

            return this;
        },

        /**
         * Turn each named property into an observable holding whatever it held
         *
         * @param {string[]|string} properties
         * @return {Object} this
         */
        observe: function (properties) {
            var self = this,
                names = isArray(properties) ? properties : String(properties).split(' ');

            names.forEach(function (name) {
                if (typeof self[name] === 'function' && typeof self[name].subscribe === 'function') {
                    return;
                }

                self[name] = ko.observable(self[name]);
            });

            return this;
        },

        /**
         * @return {void}
         */
        destroy: function () {
            return undefined;
        }
    });

    base.defaults = {};
    base.extend = extend;

    return base;
}

/**
 * Make a registry components resolve each other through
 *
 * @return {Object}
 */
function createRegistry() {
    var components = {},
        waiting = {};

    return {
        /**
         * @param {string} name
         * @param {Object} component
         * @return {void}
         */
        set: function (name, component) {
            components[name] = component;

            (waiting[name] || []).forEach(function (callback) {
                callback(component);
            });

            waiting[name] = [];
        },

        /**
         * @param {string} name
         * @param {Function} [callback] called as soon as the component exists, now or later
         * @return {Object|undefined} the component, when asked without a callback
         */
        get: function (name, callback) {
            if (typeof callback !== 'function') {
                return components[name];
            }

            if (Object.prototype.hasOwnProperty.call(components, name)) {
                callback(components[name]);

                return undefined;
            }

            waiting[name] = waiting[name] || [];
            waiting[name].push(callback);

            return undefined;
        }
    };
}

/**
 * Make a stand-in for jQuery's ajax, whose requests are settled by the test
 *
 * @return {Object}
 */
function createAjax() {
    var requests = [];

    /**
     * @param {Object} options
     * @return {Object} the record a test drives the request through
     */
    function createRequest(options) {
        var handlers = {done: [], fail: [], always: []},
            settled = null,
            xhr = {readyState: 1};

        /**
         * @param {string} type
         * @param {Array} args
         * @return {void}
         */
        function settle(type, args) {
            if (settled) {
                return;
            }

            settled = {type: type, args: args};
            xhr.readyState = 4;

            handlers[type].slice().forEach(function (handler) {
                handler.apply(null, args);
            });
            handlers.always.slice().forEach(function (handler) {
                handler.apply(null, args);
            });
        }

        /**
         * @param {string} type
         * @param {Function} handler
         * @return {Object} the request object, so calls chain
         */
        function register(type, handler) {
            if (typeof handler !== 'function') {
                return xhr;
            }

            if (!settled) {
                handlers[type].push(handler);
            } else if (settled.type === type || type === 'always') {
                handler.apply(null, settled.args);
            }

            return xhr;
        }

        /**
         * @param {Function} handler
         * @return {Object}
         */
        xhr.done = function (handler) {
            return register('done', handler);
        };

        /**
         * @param {Function} handler
         * @return {Object}
         */
        xhr.fail = function (handler) {
            return register('fail', handler);
        };

        /**
         * @param {Function} handler
         * @return {Object}
         */
        xhr.always = function (handler) {
            return register('always', handler);
        };

        /**
         * @return {void}
         */
        xhr.abort = function () {
            settle('fail', [xhr, 'abort']);
        };

        return {
            options: options,
            xhr: xhr,

            /**
             * @param {Object} response
             * @return {void}
             */
            resolve: function (response) {
                settle('done', [response, 'success', xhr]);
            },

            /**
             * @param {string} [status]
             * @return {void}
             */
            reject: function (status) {
                settle('fail', [xhr, status || 'error']);
            },

            /**
             * Answer the request even though it was cancelled
             *
             * Cancelling a request is best effort: it stops the caller waiting, but a response
             * already travelling is not recalled, and nothing on this side can tell the difference
             * between one that had left and one that had not. That is the case a generation guard
             * exists for and the case a cancellation can never rule out, so it is staged here
             * rather than waited for - a suite that only ever cancelled requests that obeyed would
             * be proving the cancellation and not the guard behind it.
             *
             * @param {Object} response
             * @return {void}
             */
            deliverLate: function (response) {
                settled = null;
                settle('done', [response, 'success', xhr]);
            },

            /**
             * @return {boolean}
             */
            wasAborted: function () {
                return settled !== null && settled.type === 'fail' && settled.args[1] === 'abort';
            },

            /**
             * @return {boolean}
             */
            isSettled: function () {
                return settled !== null;
            }
        };
    }

    return {
        jquery: {
            /**
             * @param {Object} options
             * @return {Object} the request object the caller holds on to
             */
            ajax: function (options) {
                var request = createRequest(options);

                requests.push(request);

                return request.xhr;
            }
        },

        /**
         * @return {number}
         */
        count: function () {
            return requests.length;
        },

        /**
         * @param {number} index
         * @return {Object|null}
         */
        at: function (index) {
            return requests[index] || null;
        },

        /**
         * @return {Object|null} the request most recently made
         */
        last: function () {
            return requests.length > 0 ? requests[requests.length - 1] : null;
        }
    };
}

/**
 * Wording, passed through
 *
 * @param {string} text
 * @return {string}
 */
function translate(text) {
    return text;
}

module.exports = {
    createComponentClass: createComponentClass,
    createRegistry: createRegistry,
    createAjax: createAjax,
    translate: translate
};
