/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Runs the module's JavaScript suite.
 *
 * From the module root:
 *
 *     node Test/Js/run.js
 *
 * Every `*.test.js` beside this file is loaded, in name order, and the process exits non-zero on
 * the first failing assertion in any of them. No dependencies and no configuration: node alone is
 * the whole toolchain, which is what keeps the suite runnable on a checkout that has never had
 * anything installed into it.
 */
var fs = require('fs'),
    path = require('path'),
    harness = require('./harness');

fs.readdirSync(__dirname)
    .filter(function (name) {
        return /\.test\.js$/.test(name);
    })
    .sort()
    .forEach(function (name) {
        require(path.join(__dirname, name));
    });

process.exit(harness.run());
