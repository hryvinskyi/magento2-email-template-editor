/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What can be said about a Knockout template without rendering one.
 *
 * Nothing here binds anything. These are checks on the text of a template, and they exist because
 * the two mistakes they catch are the two that a template makes silently.
 *
 * The first is a name bound inside a list that the rows of that list do not carry. A binding is an
 * expression evaluated in the row's own scope, so an absent property is an unresolved name and the
 * expression throws - and a binding that throws does not lose a row or a line, it takes down the
 * component the list was drawn in. The list looks fine in every row that happens to carry the
 * property, which is how such a thing survives being looked at. So the shape of a row is declared
 * below, taken from the module that builds it rather than written out again here, and every name
 * bound inside the list is checked against it.
 *
 * The second is a containerless binding that is never closed. Knockout reads `<!-- ko ... -->` and
 * `<!-- /ko -->` as a pair, and an unmatched one either swallows the rest of the template or ends a
 * section that was not open. Both are quiet: the markup is a comment, so nothing about it is
 * ill-formed to anything else that reads the file.
 *
 * What these checks are not: they do not render, so they cannot say that a binding produces the
 * right thing, that a control is reachable, or that the panel looks like anything. The reading of
 * an expression here is deliberately shallow - it finds the names an expression looks up in its
 * scope, and it errs towards ignoring a name rather than towards inventing one, so it can miss
 * something. What it never does is report a name that is not there.
 */
var fs = require('fs'),
    path = require('path'),
    harness = require('./harness'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertTrue = harness.assertTrue,

    TEMPLATE_ROOT = path.join(harness.MODULE_ROOT, 'view', 'adminhtml', 'web', 'template'),
    KNOWLEDGE_ROOT = path.join(TEMPLATE_ROOT, 'email-editor', 'knowledge'),

    INSPECTOR = harness.readModuleFile(
        'view', 'adminhtml', 'web', 'template', 'email-editor', 'knowledge', 'variable-inspector.html'
    ),

    modifierRows = harness.loadPureModule('email-editor/knowledge/modifier-rows.js'),

    /**
     * What the rows of each list in the popover carry.
     *
     * The modifier rows are asked of the module that builds them, so the two cannot drift apart.
     * The other two lists are lists of sentences: a row is a string and carries no property at all,
     * which is why the only thing that may be bound in them is the row itself.
     *
     * A list not named here fails rather than passing, because a list whose row shape nobody has
     * written down is exactly the one whose bindings nobody can check.
     */
    ROW_SHAPES = {
        modifierRows: modifierRows.PROPERTIES,
        caveats: [],
        affordanceSteps: []
    },

    /**
     * Words that are the language rather than a lookup in the row's scope
     */
    KEYWORDS = [
        'function', 'return', 'true', 'false', 'null', 'undefined', 'typeof', 'new', 'in',
        'this', 'var', 'if', 'else', 'instanceof', 'void', 'delete', 'Math', 'JSON', 'String',
        'Number', 'Boolean', 'Object', 'Array'
    ];

/**
 * Every template of this module, or of one part of it
 *
 * @param {string} directory
 * @return {Array.<{path: string, source: string}>}
 */
function templatesUnder(directory) {
    var found = [];

    fs.readdirSync(directory, {withFileTypes: true}).forEach(function (item) {
        var full = path.join(directory, item.name);

        if (item.isDirectory()) {
            found = found.concat(templatesUnder(full));
        } else if (/\.html$/.test(item.name)) {
            found.push({
                path: path.relative(harness.MODULE_ROOT, full),
                source: fs.readFileSync(full, 'utf8')
            });
        }
    });

    return found;
}

/**
 * Where the opening tag that starts at this position ends, ignoring anything inside quotes
 *
 * @param {string} text
 * @param {number} from the position of the `<`
 * @return {number} the position of the `>`, or -1
 */
function endOfOpeningTag(text, from) {
    var quote = null,
        index,
        character;

    for (index = from; index < text.length; index++) {
        character = text.charAt(index);

        if (quote !== null) {
            if (character === quote) {
                quote = null;
            }
        } else if (character === '"' || character === "'") {
            quote = character;
        } else if (character === '>') {
            return index;
        }
    }

    return -1;
}

/**
 * The content of the element whose opening tag holds this position
 *
 * @param {string} text
 * @param {number} index a position inside the opening tag
 * @return {string}
 */
function elementBody(text, index) {
    var open = text.lastIndexOf('<', index),
        name = /^<([A-Za-z][A-Za-z0-9-]*)/.exec(text.slice(open))[1],
        bodyStart = endOfOpeningTag(text, open) + 1,
        pattern = new RegExp('<(/?)' + name + '\\b', 'g'),
        depth = 1,
        match;

    pattern.lastIndex = bodyStart;

    while ((match = pattern.exec(text)) !== null) {
        depth += match[1] === '/' ? -1 : 1;

        if (depth === 0) {
            return text.slice(bodyStart, match.index);
        }
    }

    throw new Error('the <' + name + '> opened at ' + open + ' is never closed');
}

/**
 * The content between a containerless binding and the comment that closes it
 *
 * @param {string} text
 * @param {number} from the position just past the opening comment
 * @return {string}
 */
function containerlessBody(text, from) {
    var pattern = /<!--\s*(\/?)ko\b[\s\S]*?-->/g,
        depth = 1,
        match;

    pattern.lastIndex = from;

    while ((match = pattern.exec(text)) !== null) {
        depth += match[1] === '/' ? -1 : 1;

        if (depth === 0) {
            return text.slice(from, match.index);
        }
    }

    throw new Error('a containerless binding opened at ' + from + ' is never closed');
}

/**
 * Every list in a template, with what it iterates and what is inside it
 *
 * @param {string} text
 * @return {Array.<{source: string, body: string}>}
 */
function listsIn(text) {
    var lists = [],
        inAttribute = /foreach:\s*([A-Za-z_$][A-Za-z0-9_$]*)/g,
        containerless = /<!--\s*ko\s+foreach:\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*-->/g,
        match;

    while ((match = containerless.exec(text)) !== null) {
        lists.push({
            source: match[1],
            body: containerlessBody(text, match.index + match[0].length)
        });
    }

    while ((match = inAttribute.exec(text)) !== null) {
        if (text.lastIndexOf('<!--', match.index) > text.lastIndexOf('<', match.index)) {
            continue;
        }

        lists.push({source: match[1], body: elementBody(text, match.index)});
    }

    return lists;
}

/**
 * Every binding expression written in a stretch of a template
 *
 * @param {string} text
 * @return {string[]}
 */
function bindingsIn(text) {
    var found = [],
        attributes = /data-bind="([\s\S]*?)"/g,
        containerless = /<!--\s*ko\s+([\s\S]*?)-->/g,
        match;

    while ((match = attributes.exec(text)) !== null) {
        found.push(match[1]);
    }

    while ((match = containerless.exec(text)) !== null) {
        found.push(match[1]);
    }

    return found;
}

/**
 * The names a binding expression looks up in the scope it is evaluated in
 *
 * Shallow on purpose. A name reached through a dot belongs to something else, a name followed by a
 * colon is the binding it introduces or a key of an object literal, a name declared as a parameter
 * of an inline function is that parameter, and a name beginning with `$` is Knockout's own context.
 * Everything left is a name looked up where the binding stands.
 *
 * @param {string} expression
 * @return {string[]}
 */
function lookupsIn(expression) {
    var withoutText = expression.replace(/'[^']*'|"[^"]*"/g, ' '),
        parameters = [],
        found = [],
        declarations = /function\s*\(([^)]*)\)/g,
        names = /[A-Za-z_$][A-Za-z0-9_$]*/g,
        match,
        before,
        after;

    while ((match = declarations.exec(withoutText)) !== null) {
        match[1].split(',').forEach(function (parameter) {
            var trimmed = parameter.trim();

            if (trimmed !== '') {
                parameters.push(trimmed);
            }
        });
    }

    while ((match = names.exec(withoutText)) !== null) {
        before = withoutText.slice(0, match.index).replace(/\s+$/, '');
        after = withoutText.slice(match.index + match[0].length).replace(/^\s+/, '');

        if (before.charAt(before.length - 1) === '.' ||
            after.charAt(0) === ':' ||
            match[0].charAt(0) === '$' ||
            KEYWORDS.indexOf(match[0]) !== -1 ||
            parameters.indexOf(match[0]) !== -1
        ) {
            continue;
        }

        found.push(match[0]);
    }

    return found.filter(function (name, index) {
        return found.indexOf(name) === index;
    });
}

/**
 * Every name a template binds inside a list that the rows of that list do not carry
 *
 * @param {string} text a template
 * @param {Object} shapes what the rows of each list carry, by the name of the list
 * @return {Array.<{list: string, name: string}>}
 */
function unresolvableNames(text, shapes) {
    var found = [];

    listsIn(text).forEach(function (list) {
        var carried = Object.prototype.hasOwnProperty.call(shapes, list.source)
            ? shapes[list.source]
            : [];

        bindingsIn(list.body).forEach(function (binding) {
            lookupsIn(binding).forEach(function (name) {
                if (carried.indexOf(name) === -1) {
                    found.push({list: list.source, name: name});
                }
            });
        });
    });

    return found;
}

test('the reading of a binding finds the names it looks up and no others', function () {
    assertLike(lookupsIn('text: label'), ['label'], 'a plain lookup');
    assertLike(lookupsIn("css: {'ete-applied': applied}"), ['applied'], 'a quoted key is not a lookup');
    assertLike(lookupsIn('if: options.length > 0'), ['options'], 'the root of a member expression');
    assertLike(
        lookupsIn('click: function (row) { return $parent.toggleModifier(row); }'),
        [],
        'a parameter and a context name are neither of them lookups'
    );
    assertLike(
        lookupsIn("attr: {title: $t('Close')}, text: reference"),
        ['reference'],
        'an object key is not a lookup'
    );
});

test('every list in the popover has had the shape of its rows written down', function () {
    listsIn(INSPECTOR).forEach(function (list) {
        assertTrue(
            Object.prototype.hasOwnProperty.call(ROW_SHAPES, list.source),
            'the list over ' + JSON.stringify(list.source) + ' has no declared row shape, so ' +
                'nothing can say whether what it binds is there'
        );
    });

    assertSame(listsIn(INSPECTOR).length, Object.keys(ROW_SHAPES).length, 'and every declared shape is used');
});

test('every name bound inside a list is a name the rows of that list carry', function () {
    assertLike(
        unresolvableNames(INSPECTOR, ROW_SHAPES),
        [],
        'a name a row does not carry is unresolved in the row scope, so the binding throws and ' +
            'takes the whole popover with it'
    );
});

test('a name a row does not carry is found rather than passed over', function () {
    var withStrayName = INSPECTOR.replace(
            '<span class="ete-inspector-modifier-name" data-bind="text: label"></span>',
            '<!-- ko if: reference --><span data-bind="text: reference"></span><!-- /ko -->'
        );

    assertTrue(withStrayName !== INSPECTOR, 'the popover still has the row this is planted in');
    assertLike(
        unresolvableNames(withStrayName, ROW_SHAPES),
        [{list: 'modifierRows', name: 'reference'}, {list: 'modifierRows', name: 'reference'}],
        'a property of the popover named inside a row is reported, once per binding'
    );
});

test('a list whose row shape nobody wrote down fails rather than passing quietly', function () {
    assertTrue(
        unresolvableNames(INSPECTOR, {}).length > 0,
        'with nothing declared, every name bound in every list is unaccounted for'
    );
});

test('the list of modifiers really is read against the module that builds its rows', function () {
    assertTrue(modifierRows.PROPERTIES.length > 0, 'there is a shape to read against');
    assertTrue(
        listsIn(INSPECTOR).some(function (list) {
            return list.source === 'modifierRows';
        }),
        'and the popover really draws that list, so the check has something to check'
    );
    assertTrue(
        bindingsIn(
            listsIn(INSPECTOR).filter(function (list) {
                return list.source === 'modifierRows';
            })[0].body
        ).length > 3,
        'with more than a couple of bindings in it'
    );
});

test('every containerless binding in every template of this module is closed', function () {
    templatesUnder(TEMPLATE_ROOT).forEach(function (template) {
        var pattern = /<!--\s*(\/?)ko\b[\s\S]*?-->/g,
            depth = 0,
            match;

        while ((match = pattern.exec(template.source)) !== null) {
            depth += match[1] === '/' ? -1 : 1;

            assertTrue(
                depth >= 0,
                template.path + ' closes a containerless binding that was never opened, at ' +
                    match.index
            );
        }

        assertSame(depth, 0, template.path + ' leaves a containerless binding open');
    });
});

test('nothing an admin or a merchant wrote is rendered as markup in the popover', function () {
    templatesUnder(KNOWLEDGE_ROOT).forEach(function (template) {
        assertLike(
            (template.source.match(/\bhtml:/g) || []),
            [],
            template.path + ' renders a value as markup; every value that reaches it was written ' +
                'by an admin, by a merchant or by the server, and all of them are bound as text'
        );
    });
});

test('every value the popover shows that came from outside this repository is bound as text', function () {
    var boundAsText = (INSPECTOR.match(/text:\s*[A-Za-z_$][A-Za-z0-9_$]*/g) || []).map(function (binding) {
            return binding.replace(/^text:\s*/, '');
        });

    [
        'title',
        'summary',
        'reference',
        'originLocator',
        'originExplanation',
        'valuePreview',
        'valueScopeLabel',
        'savedScopeLabel',
        'scopeStoreName',
        'affordanceLabel',
        'loadError',
        'inlineMessage',
        'modifierText'
    ].forEach(function (name) {
        assertTrue(
            boundAsText.indexOf(name) !== -1,
            name + ' carries something someone else wrote and must be bound as text'
        );
    });
});

test('a containerless binding left open is found rather than passed over', function () {
    var unclosed = INSPECTOR.replace('<!-- /ko -->', ''),
        depth = 0,
        pattern = /<!--\s*(\/?)ko\b[\s\S]*?-->/g,
        match;

    while ((match = pattern.exec(unclosed)) !== null) {
        depth += match[1] === '/' ? -1 : 1;
    }

    assertSame(depth, 1, 'one section is left open, and the count says so');
});

test('every html: binding in the module is one that was deliberately allowed', function () {
    // A Knockout `html:` binding renders its value as markup, so one pointed at anything an
    // administrator or a merchant typed is a stored cross-site-scripting hole that fires on a
    // routine click. The module had exactly that in the confirmation dialog: three call sites
    // concatenated a draft name and a version comment into the value, so publishing with a script
    // tag in the comment and then opening Delete on that row ran it.
    //
    // The two that remain are safe by construction, not by review: one renders a diff whose every
    // line of content is escaped as it is assembled, the other renders an icon that is a literal
    // in this module's own source. Both are named here with the reason, so a third one has to be
    // argued for rather than merely added.
    var allowed = {
        'version-history.html': 'a diff whose content is escaped line by line while it is built',
        'publish-dialog.html': 'an icon that is a character entity written in this module'
    };

    templatesUnder(TEMPLATE_ROOT).forEach(function (template) {
        var found = (template.source.match(/\bhtml:/g) || []).length;

        if (found === 0) {
            return;
        }

        assertTrue(
            Object.keys(allowed).some(function (name) {
                return template.path.slice(-name.length) === name;
            }),
            template.path + ' renders a value as markup and is not one of the bindings allowed to'
        );
    });
});
