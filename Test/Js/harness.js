/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The module's JavaScript test host.
 *
 * Run the whole suite from the module root:
 *
 *     node Test/Js/run.js
 *
 * A browser module that holds logic rather than presentation has to evaluate in plain node, and
 * this is where that is enforced rather than promised. The module's source is read from disk and
 * evaluated in a fresh context whose only global is `define`: there is no window, no document, no
 * require, no jQuery and no Knockout in it, so a module that reached for any of them throws while
 * it loads and the suite goes red. A module that declares a dependency is refused for the same
 * reason - depending on nothing is the property being proved, not a style preference.
 *
 * Values come back from that context carrying its own prototypes, so comparisons here are made by
 * structure rather than by identity. That is also why this file carries its own small assertions
 * instead of using node's, whose strict comparisons check prototypes and would report two
 * identically shaped objects as different.
 */
var fs = require('fs'),
    path = require('path'),
    vm = require('vm'),
    MODULE_ROOT = path.resolve(__dirname, '..', '..'),
    SCRIPT_ROOT = path.join(MODULE_ROOT, 'view', 'adminhtml', 'web', 'js'),
    registered = [];

/**
 * Load a pure browser module and hand back whatever its factory returned
 *
 * @param {string} relativePath path under view/adminhtml/web/js
 * @return {Object} the module's exports
 */
function loadPureModule(relativePath) {
    var file = path.join(SCRIPT_ROOT, relativePath),
        source = fs.readFileSync(file, 'utf8'),
        exported = null,
        defined = false,
        sandbox = {};

    sandbox.define = function (dependencies, factory) {
        if (typeof dependencies === 'function') {
            throw new Error(relativePath + ' must declare its dependency list explicitly.');
        }

        if (!Array.isArray(dependencies) || dependencies.length !== 0) {
            throw new Error(
                relativePath + ' declares dependencies (' + JSON.stringify(dependencies) +
                ') and so is not a pure module.'
            );
        }

        defined = true;
        exported = factory();
    };

    vm.runInNewContext(source, vm.createContext(sandbox), {filename: file});

    if (!defined) {
        throw new Error(relativePath + ' did not call define().');
    }

    return exported;
}

/**
 * Compare two values by structure, ignoring which context they were built in
 *
 * @param {*} actual
 * @param {*} expected
 * @return {boolean}
 */
function looksEqual(actual, expected) {
    var actualKeys,
        expectedKeys,
        index;

    if (actual === expected) {
        return true;
    }

    if (typeof actual !== 'object' || typeof expected !== 'object' ||
        actual === null || expected === null
    ) {
        return false;
    }

    if (Array.isArray(actual) !== Array.isArray(expected)) {
        return false;
    }

    if (Array.isArray(actual)) {
        if (actual.length !== expected.length) {
            return false;
        }

        for (index = 0; index < actual.length; index++) {
            if (!looksEqual(actual[index], expected[index])) {
                return false;
            }
        }

        return true;
    }

    actualKeys = Object.keys(actual).sort();
    expectedKeys = Object.keys(expected).sort();

    if (actualKeys.join(',') !== expectedKeys.join(',')) {
        return false;
    }

    for (index = 0; index < actualKeys.length; index++) {
        if (!looksEqual(actual[actualKeys[index]], expected[actualKeys[index]])) {
            return false;
        }
    }

    return true;
}

/**
 * Render a value for a failure message
 *
 * @param {*} value
 * @return {string}
 */
function show(value) {
    return typeof value === 'string' ? JSON.stringify(value) : JSON.stringify(value, null, 0);
}

/**
 * Fail unless the two values are the same
 *
 * @param {*} actual
 * @param {*} expected
 * @param {string} what
 * @return {void}
 */
function assertSame(actual, expected, what) {
    if (actual !== expected) {
        throw new Error(what + ': expected ' + show(expected) + ', got ' + show(actual));
    }
}

/**
 * Fail unless the two values have the same structure
 *
 * @param {*} actual
 * @param {*} expected
 * @param {string} what
 * @return {void}
 */
function assertLike(actual, expected, what) {
    if (!looksEqual(actual, expected)) {
        throw new Error(what + ': expected ' + show(expected) + ', got ' + show(actual));
    }
}

/**
 * Fail unless the value is true
 *
 * @param {*} value
 * @param {string} what
 * @return {void}
 */
function assertTrue(value, what) {
    if (value !== true) {
        throw new Error(what + ': expected true, got ' + show(value));
    }
}

/**
 * Register a test
 *
 * @param {string} name
 * @param {Function} body
 * @return {void}
 */
function test(name, body) {
    registered.push({name: name, body: body});
}

/**
 * Run every registered test and report
 *
 * @return {number} process exit code
 */
function run() {
    var failures = [],
        passed = 0;

    registered.forEach(function (entry) {
        try {
            entry.body();
            passed++;
        } catch (error) {
            failures.push({name: entry.name, message: error && error.message ? error.message : String(error)});
        }
    });

    failures.forEach(function (failure) {
        process.stdout.write('FAIL ' + failure.name + '\n     ' + failure.message + '\n');
    });

    process.stdout.write(
        'Hryvinskyi_EmailTemplateEditor JavaScript: ' + registered.length + ' tests, ' +
        passed + ' passed, ' + failures.length + ' failed\n'
    );

    return failures.length === 0 ? 0 : 1;
}

module.exports = {
    loadPureModule: loadPureModule,
    assertSame: assertSame,
    assertLike: assertLike,
    assertTrue: assertTrue,
    test: test,
    run: run
};
