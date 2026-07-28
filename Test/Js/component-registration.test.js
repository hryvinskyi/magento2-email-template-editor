/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The wiring a component needs before it can render at all.
 *
 * A child of the editor is declared in three separate files and needs all three: the layout says
 * which script it is and which region it belongs in, the script must be where the layout says, and
 * some template must actually draw that region. Get two of the three right and the result is not an
 * error anywhere - the component is built, it initialises, it makes its requests, and nothing is
 * ever on screen. Nothing in a browser console says so either, which is what makes it worth
 * checking here instead.
 *
 * The same silence is what a missing configuration key sounds like. The page publishes three things
 * to its scripts and the block behind it builds a much larger array; a script reading a key from the
 * published object that only exists in the larger one gets `undefined`, and the usual `|| []` or
 * `|| {}` beside it turns that into something that works and is empty. A list that is empty because
 * it was never published looks exactly like a list that is empty because there is nothing in it.
 *
 * All of it is read as text. Nothing here loads a layout, renders a template or runs PHP, so what
 * is proved is that the names on both sides of each of these joins agree - not that the join does
 * what it is for.
 */
var fs = require('fs'),
    path = require('path'),
    harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertTrue = harness.assertTrue,

    MODULE_NAME = 'Hryvinskyi_EmailTemplateEditor',
    ADMINHTML = path.join(harness.MODULE_ROOT, 'view', 'adminhtml'),
    SCRIPT_ROOT = path.join(ADMINHTML, 'web', 'js'),
    TEMPLATE_ROOT = path.join(ADMINHTML, 'web', 'template'),

    LAYOUT = harness.readModuleFile('view', 'adminhtml', 'layout', 'emaileditor_editor_index.xml'),
    PAGE = harness.readModuleFile('view', 'adminhtml', 'templates', 'editor.phtml'),
    BLOCK = harness.readModuleFile('Block', 'Adminhtml', 'Editor.php');

/**
 * Every file below a directory, recursively
 *
 * @param {string} directory
 * @param {RegExp} pattern which names count
 * @return {string[]} absolute paths
 */
function filesUnder(directory, pattern) {
    var found = [];

    fs.readdirSync(directory, {withFileTypes: true}).forEach(function (entry) {
        var full = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            found = found.concat(filesUnder(full, pattern));
        } else if (pattern.test(entry.name)) {
            found.push(full);
        }
    });

    return found;
}

/**
 * Every match of a pattern's first group
 *
 * @param {string} text
 * @param {RegExp} pattern with the global flag and one group
 * @return {string[]}
 */
function allOf(text, pattern) {
    var found = [],
        match;

    pattern.lastIndex = 0;

    while ((match = pattern.exec(text)) !== null) {
        found.push(match[1]);
    }

    return found;
}

/**
 * The same list with every duplicate dropped, in first-seen order
 *
 * @param {string[]} values
 * @return {string[]}
 */
function unique(values) {
    return values.filter(function (value, index) {
        return values.indexOf(value) === index;
    });
}

/**
 * The children the layout declares, each with whatever it says about them
 *
 * A child is a block of the jsLayout array; the blocks are siblings rather than nested, so each one
 * runs from where it opens to wherever the next one does.
 *
 * @return {Array.<{name: string, component: string|null, displayArea: string|null}>}
 */
function declaredChildren() {
    var opener = /<item name="([A-Za-z0-9_]+)" xsi:type="array">/g,
        starts = [],
        match;

    while ((match = opener.exec(LAYOUT)) !== null) {
        starts.push({name: match[1], at: match.index});
    }

    return starts.map(function (start, index) {
        var body = LAYOUT.slice(
                start.at,
                index + 1 < starts.length ? starts[index + 1].at : LAYOUT.length
            ),
            stated = function (key) {
                var found = body.match(
                    new RegExp('<item name="' + key + '" xsi:type="string">([^<]+)</item>')
                );

                return found ? found[1] : null;
            };

        return {name: start.name, component: stated('component'), displayArea: stated('displayArea')};
    }).filter(function (child) {
        return child.component !== null || child.displayArea !== null;
    });
}

/**
 * Every region some template of this module actually draws
 *
 * @return {string[]}
 */
function drawnRegions() {
    return unique(filesUnder(TEMPLATE_ROOT, /\.html$/).reduce(function (names, file) {
        return names.concat(
            allOf(fs.readFileSync(file, 'utf8'), /getRegion\(\s*'([^']+)'\s*\)/g)
        );
    }, []));
}

/**
 * Where a component path published by this module lives on disk
 *
 * @param {string} published as the layout spells it
 * @return {string|null} an absolute path, or nothing when the path is not this module's
 */
function scriptFor(published) {
    var prefix = MODULE_NAME + '/js/';

    return published.indexOf(prefix) === 0
        ? path.join(SCRIPT_ROOT, published.slice(prefix.length) + '.js')
        : null;
}

/**
 * The template a component declares as its default, if it declares one
 *
 * @param {string} source
 * @return {string|null}
 */
function declaredTemplate(source) {
    var found = source.match(/template:\s*'([^']+)'/);

    return found ? found[1] : null;
}

/**
 * The body of a PHP array literal opened at a key
 *
 * @param {string} source
 * @param {string} key
 * @return {string}
 */
function phpArrayAt(source, key) {
    var opened = source.indexOf("'" + key + "' => ["),
        depth = 0,
        index;

    if (opened === -1) {
        return '';
    }

    for (index = source.indexOf('[', opened); index < source.length; index++) {
        if (source.charAt(index) === '[') {
            depth++;
        } else if (source.charAt(index) === ']') {
            depth--;

            if (depth === 0) {
                return source.slice(opened, index);
            }
        }
    }

    return '';
}

/**
 * Every script of this module, with its source
 *
 * @return {Array.<{path: string, source: string}>}
 */
function scripts() {
    return filesUnder(SCRIPT_ROOT, /\.js$/).map(function (file) {
        return {path: path.relative(harness.MODULE_ROOT, file), source: fs.readFileSync(file, 'utf8')};
    });
}

test('every child the layout declares says both what it is and where it goes', function () {
    declaredChildren().forEach(function (child) {
        assertTrue(
            typeof child.component === 'string' && child.component !== '',
            child.name + ' names a script'
        );
        assertTrue(
            typeof child.displayArea === 'string' && child.displayArea !== '',
            child.name + ' names the region it belongs in'
        );
    });
});

test('every script the layout names is on disk where it says', function () {
    declaredChildren().forEach(function (child) {
        var file = scriptFor(child.component);

        assertTrue(
            file === null || fs.existsSync(file),
            child.name + ' points at ' + child.component + ', which is not there'
        );
    });
});

test('every region the layout puts a child in is drawn by some template', function () {
    var drawn = drawnRegions();

    declaredChildren().forEach(function (child) {
        assertTrue(
            drawn.indexOf(child.displayArea) !== -1,
            child.name + ' is put in the region ' + JSON.stringify(child.displayArea) +
                ', which nothing draws, so it would build and initialise and never appear'
        );
    });
});

test('every region a template draws has a child put in it', function () {
    var declared = declaredChildren().map(function (child) {
            return child.displayArea;
        });

    drawnRegions().forEach(function (region) {
        assertTrue(
            declared.indexOf(region) !== -1,
            'the region ' + JSON.stringify(region) + ' is drawn but nothing is declared into it'
        );
    });
});

test('every template a component asks for by name is on disk', function () {
    var prefix = MODULE_NAME + '/';

    scripts().forEach(function (script) {
        var named = declaredTemplate(script.source),
            file;

        if (named === null || named.indexOf(prefix) !== 0) {
            return;
        }

        file = path.join(TEMPLATE_ROOT, named.slice(prefix.length) + '.html');

        assertTrue(
            fs.existsSync(file),
            script.path + ' asks for the template ' + named + ', which is not there'
        );
    });
});

test('every child the layout declares is a script that declares itself a component', function () {
    declaredChildren().forEach(function (child) {
        var file = scriptFor(child.component);

        if (file === null || !fs.existsSync(file)) {
            return;
        }

        assertTrue(
            /define\(\s*\[[\s\S]*?['"]uiComponent['"]/.test(fs.readFileSync(file, 'utf8')),
            child.name + ' is put in the layout as a component but ' + child.component +
                ' does not extend one'
        );
    });
});

test('every key a script reads from the published configuration is a key the page publishes', function () {
    var published = allOf(
            PAGE.slice(PAGE.indexOf('window.emailEditorConfig')),
            /'([A-Za-z_][A-Za-z0-9_]*)'\s*=>/g
        ),
        read = [];

    assertTrue(published.length > 0, 'the page publishes something');

    scripts().forEach(function (script) {
        unique(
            allOf(script.source, /emailEditorConfig\s*\.\s*([A-Za-z_][A-Za-z0-9_]*)/g).concat(
                allOf(script.source, /emailEditorConfig\s*\[\s*'([^']+)'\s*\]/g)
            )
        ).forEach(function (key) {
            read.push({key: key, script: script.path});
        });
    });

    assertTrue(read.length > 0, 'something reads it, or this check is watching nothing');

    read.forEach(function (usage) {
        assertTrue(
            published.indexOf(usage.key) !== -1,
            usage.script + ' reads ' + JSON.stringify(usage.key) + ' from the published ' +
                'configuration, which the page does not publish - it would come out undefined and ' +
                'whatever fallback stands beside it would look like a real, empty answer'
        );
    });
});

test('every address a script asks for by name is an address the block builds', function () {
    var built = allOf(phpArrayAt(BLOCK, 'urls'), /'([A-Za-z][A-Za-z0-9_]*)'\s*=>/g),
        asked = [];

    assertTrue(built.length > 0, 'the block builds some addresses');

    scripts().forEach(function (script) {
        unique(
            allOf(script.source, /\burls\s*\.\s*([A-Za-z_][A-Za-z0-9_]*)/g).concat(
                allOf(script.source, /\burls\s*\[\s*'([^']+)'\s*\]/g)
            )
        ).forEach(function (key) {
            asked.push({key: key, script: script.path});
        });
    });

    assertTrue(asked.length > 0, 'something asks for one, or this check is watching nothing');

    asked.forEach(function (usage) {
        assertTrue(
            built.indexOf(usage.key) !== -1,
            usage.script + ' asks for the address ' + JSON.stringify(usage.key) +
                ', which the block does not build - the request would go to the page it is on'
        );
    });
});

test('the popover is wired into all three places rather than two of them', function () {
    var inspector = declaredChildren().filter(function (child) {
        return child.name === 'variableInspector';
    });

    assertSame(inspector.length, 1, 'declared once in the layout');
    assertSame(
        inspector[0].component,
        MODULE_NAME + '/js/email-editor/knowledge/variable-inspector',
        'pointing at the script'
    );
    assertTrue(
        fs.existsSync(scriptFor(inspector[0].component)),
        'and the script is there'
    );
    assertTrue(
        drawnRegions().indexOf(inspector[0].displayArea) !== -1,
        'and its region is drawn'
    );
});

test('the layout declares the children it is read for, so a check on none of them cannot pass', function () {
    var names = declaredChildren().map(function (child) {
        return child.name;
    });

    assertTrue(names.length > 5, 'the editor has children, so the parsing found them');
    assertLike(
        names.filter(function (name) {
            return name === 'children' || name === 'jsLayout';
        }),
        [],
        'and did not mistake the array holding them for one of them'
    );
});
