/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What the directive popover decides.
 *
 * The component is an adapter, so what is checked here is the deciding rather than the drawing:
 * which answer is allowed to write the panel, what is remembered and for how long, what a rewrite
 * that was refused is turned into, which way a modifier toggle goes, and what is promised about the
 * scope a value lands in. All of that is reachable without a browser because the component asks
 * plain questions of the things around it, and every one of those is stood in for here.
 *
 * The directives are produced by the real scanner rather than written out by hand, so the shapes
 * the popover is handed are the shapes it is handed on the page. The chain module, the cache and
 * the placement module are the real ones too: the point of the exercise is what happens between
 * them, and standing them in would only prove the stand-ins agree with each other.
 *
 * What no test here can say: whether any of it renders. The panel's own template is checked by
 * reading it, and how a real Knockout binding behaves against these values is outside this file.
 */
var harness = require('./harness'),
    browserStub = require('./browser-stub'),
    koStub = require('./knockout-stub'),
    magentoStub = require('./magento-stub'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertTrue = harness.assertTrue,
    assertFalse = harness.assertFalse,

    PARENT = 'emailTemplateEditor',

    scanner = harness.loadPureModule('email-editor/knowledge/directive-scanner.js'),

    DESCRIBE_URL = 'https://example.test/emaileditor/knowledge/describe',
    SAVE_URL = 'https://example.test/emaileditor/knowledge/saveValue',

    MODIFIERS = [
        {name: 'escape', label: 'Escape as HTML', description: 'The default.'},
        {name: 'nl2br', label: 'Line breaks as markup'},
        {
            name: 'date',
            label: 'Format as a date',
            arguments: [{'default': 'medium', options: ['short', 'medium', 'long']}]
        },
        {name: 'raw', label: 'No escaping', implemented: false}
    ];

/**
 * The one directive in this template, as the scanner reads it
 *
 * @param {string} text
 * @return {Object}
 */
function directive(text) {
    var found = scanner.scan(text);

    if (found.length !== 1) {
        throw new Error('the fixture ' + JSON.stringify(text) + ' is not one directive');
    }

    return found[0];
}

/**
 * An entry as the knowledge base sends one
 *
 * @param {Object} [overrides]
 * @return {Object}
 */
function entry(overrides) {
    return Object.assign({
        known: true,
        title: 'Order number',
        summary: 'The number the order was placed under.',
        outputKind: 'text',
        defaultModifier: 'escape',
        origin: {kind: 'template_var', locator: 'order.increment_id', explanation: 'Set by the order.'},
        caveats: [],
        affordance: {kind: 'none'},
        value: {available: true, exact: true, preview: '000000123', truncated: false, scopeLabel: 'Default'}
    }, overrides || {});
}

/**
 * A successful answer to a lookup
 *
 * @param {string} reference
 * @param {Object} [entryOverrides]
 * @param {Array} [modifiers]
 * @return {Object}
 */
function answerFor(reference, entryOverrides, modifiers) {
    var entries = {};

    entries[reference] = entry(entryOverrides);

    return {
        success: true,
        modifiers: modifiers || MODIFIERS,
        entries: entries
    };
}

/**
 * The editor the popover hangs off: the store view, the template, and the request bookkeeping
 *
 * @param {Object} ko
 * @param {Object} [options]
 * @return {Object}
 */
function createOrchestrator(ko, options) {
    var settings = options || {},
        tracked = [],
        statuses = [];

    return {
        storeId: ko.observable(typeof settings.storeId === 'number' ? settings.storeId : 1),
        statusBarText: ko.observable(''),

        /**
         * @return {number}
         */
        getEffectiveStoreId: function () {
            return this.storeId();
        },

        /**
         * @return {string}
         */
        getCurrentStoreName: function () {
            return settings.storeName || 'Danish';
        },

        /**
         * @return {string}
         */
        currentTemplateId: function () {
            return settings.templateId || 'sales_email_order_template';
        },

        /**
         * @param {Object} xhr
         * @return {Object} the same request, now counted
         */
        trackRequest: function (xhr) {
            tracked.push(xhr);

            return xhr;
        },

        /**
         * @param {string} kind
         * @param {string} text
         * @return {void}
         */
        setStatus: function (kind, text) {
            statuses.push({kind: kind, text: text});
        },

        /**
         * @return {number}
         */
        trackedCount: function () {
            return tracked.length;
        },

        /**
         * @return {Array} every status the editor was asked to show
         */
        statuses: function () {
            return statuses.slice();
        }
    };
}

/**
 * The code editor: it announces directives and owns the only route to a formatting change
 *
 * @return {Object}
 */
function createTemplateEditor() {
    var listeners = {},
        calls = [],
        answer = {written: true};

    return {
        /**
         * @param {string} type
         * @param {Function} handler
         * @return {void}
         */
        on: function (type, handler) {
            listeners[type] = listeners[type] || [];
            listeners[type].push(handler);
        },

        /**
         * @param {Object} anchor
         * @param {string} chainText
         * @param {Object} expected
         * @return {Object} whatever the test said the rewrite answers
         */
        replaceModifierSpan: function (anchor, chainText, expected) {
            calls.push({anchor: anchor, chainText: chainText, expected: expected});

            return typeof answer === 'function' ? answer(chainText) : answer;
        },

        /**
         * Say a directive was clicked
         *
         * @param {Object} payload
         * @return {void}
         */
        activate: function (payload) {
            (listeners.directiveActivate || []).forEach(function (handler) {
                handler(payload);
            });
        },

        /**
         * @param {Object|Function} next what the next rewrite answers
         * @return {void}
         */
        answerWith: function (next) {
            answer = next;
        },

        /**
         * @return {Array} every rewrite that was asked for
         */
        calls: function () {
            return calls.slice();
        }
    };
}

/**
 * The variable chooser: it names a variable that is not in the template yet
 *
 * @return {Object}
 */
function createChooser() {
    var listeners = {};

    return {
        /**
         * @param {string} type
         * @param {Function} handler
         * @return {void}
         */
        on: function (type, handler) {
            listeners[type] = listeners[type] || [];
            listeners[type].push(handler);
        },

        /**
         * @param {string} reference
         * @return {void}
         */
        describe: function (reference) {
            (listeners.describeVariable || []).forEach(function (handler) {
                handler(reference);
            });
        }
    };
}

/**
 * Build a popover with everything around it standing in
 *
 * @param {Object} [options]
 * @param {Object} [options.urls] what the page published as addresses
 * @param {number} [options.storeId] the store view the editor is working in
 * @param {boolean} [options.withTemplateEditor] whether a code editor is registered at all
 * @return {Object} the popover and everything a test drives it through
 */
function setUp(options) {
    var settings = options || {},
        urls = Object.prototype.hasOwnProperty.call(settings, 'urls')
            ? settings.urls
            : {knowledgeDescribe: DESCRIBE_URL, knowledgeSaveValue: SAVE_URL},
        browser = browserStub.create({
            emailEditorConfig: {urls: urls, formKey: 'FORMKEY', storeId: 1}
        }),
        ko = koStub.create(),
        registry = magentoStub.createRegistry(),
        ajax = magentoStub.createAjax(),
        orchestrator = createOrchestrator(ko, settings),
        templateEditor = createTemplateEditor(),
        chooser = createChooser(),
        parentResolver = harness.loadStubbedModule(
            'email-editor/parent-resolver.js',
            {uiRegistry: registry}
        ),
        failureReporter = harness.loadStubbedModule(
            'email-editor/failure-reporter.js',
            {'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver': parentResolver}
        ),
        Inspector,
        inspector;

    registry.set(PARENT, orchestrator);
    registry.set(PARENT + '.variableChooser', chooser);

    if (settings.withTemplateEditor !== false) {
        registry.set(PARENT + '.templateEditor', templateEditor);
    }

    Inspector = harness.loadStubbedModule(
        'email-editor/knowledge/variable-inspector.js',
        {
            uiComponent: magentoStub.createComponentClass(ko),
            ko: ko,
            jquery: ajax.jquery,
            uiRegistry: registry,
            'mage/translate': magentoStub.translate,
            'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver': parentResolver,
            'Hryvinskyi_EmailTemplateEditor/js/email-editor/failure-reporter': failureReporter,
            'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/modifier-chain':
                harness.loadPureModule('email-editor/knowledge/modifier-chain.js'),
            'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/modifier-rows':
                harness.loadPureModule('email-editor/knowledge/modifier-rows.js'),
            'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/entry-cache':
                harness.loadPureModule('email-editor/knowledge/entry-cache.js'),
            'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/inspector-placement':
                harness.loadPureModule('email-editor/knowledge/inspector-placement.js')
        },
        browser.globals()
    );

    inspector = new Inspector({name: PARENT + '.variableInspector', parentName: PARENT});

    return {
        inspector: inspector,
        browser: browser,
        clock: browser.clock,
        ko: ko,
        ajax: ajax,
        registry: registry,
        orchestrator: orchestrator,
        templateEditor: templateEditor,
        chooser: chooser,

        /**
         * Click a directive in the template
         *
         * @param {string} text the template holding exactly one directive
         * @param {Object} [payload] anything else the code editor reports with it
         * @return {Object} the occurrence that was announced
         */
        activate: function (text, payload) {
            var occurrence = directive(text);

            templateEditor.activate(Object.assign(
                {occurrence: occurrence, anchor: {mark: text}},
                payload || {}
            ));

            return occurrence;
        },

        /**
         * Answer the lookup that is on the wire
         *
         * @param {Object} response
         * @return {void}
         */
        answer: function (response) {
            ajax.last().resolve(response);
        },

        /**
         * The row for a modifier, as the list holds it
         *
         * @param {string} name
         * @return {Object}
         */
        row: function (name) {
            var found = inspector.modifierRows().filter(function (candidate) {
                return candidate.name === name;
            });

            if (found.length !== 1) {
                throw new Error('no single row for ' + name);
            }

            return found[0];
        },

        /**
         * The chain the last rewrite was asked to write
         *
         * @return {string|null}
         */
        lastChainWritten: function () {
            var calls = templateEditor.calls();

            return calls.length > 0 ? calls[calls.length - 1].chainText : null;
        }
    };
}

/**
 * Open a directive, answer the lookup, and hand back the fixture
 *
 * @param {Object} context
 * @param {string} text
 * @param {Object} [payload]
 * @return {Object} the occurrence that was opened
 */
function openAnswered(context, text, payload) {
    var occurrence = context.activate(text, payload);

    context.answer(answerFor(occurrence.reference));

    return occurrence;
}

test('a click carrying no directive opens nothing and asks nothing', function () {
    var context = setUp();

    context.templateEditor.activate({anchor: {}});
    context.templateEditor.activate({occurrence: {}});

    assertSame(context.inspector.isOpen(), false, 'still closed');
    assertSame(context.ajax.count(), 0, 'nothing was looked up');
});

test('the lookup a new directive replaces is cancelled', function () {
    var context = setUp(),
        stale;

    context.activate('{{var order.increment_id}}');
    stale = context.ajax.at(0);

    context.activate('{{config path="general/store_information/name"}}');

    assertTrue(stale.wasAborted(), 'the one nobody is waiting for any more');
    assertSame(context.ajax.count(), 2, 'and the new one went out');
});

test('an answer for a directive the admin has moved on from never reaches the panel', function () {
    var context = setUp(),
        stale;

    context.activate('{{var order.increment_id}}');
    stale = context.ajax.at(0);

    context.activate('{{config path="general/store_information/name"}}');
    stale.deliverLate(answerFor('var:order.increment_id', {title: 'Order number'}));

    assertSame(context.inspector.hasEntry(), false, 'the superseded answer was dropped');
    assertSame(context.inspector.reference(), 'config:general/store_information/name', 'still the new one');
});

test('closing cancels the lookup on the wire and a late answer changes nothing', function () {
    var context = setUp(),
        request;

    context.activate('{{var order.increment_id}}');
    request = context.ajax.at(0);

    context.inspector.close();

    assertTrue(request.wasAborted(), 'the request was cancelled');

    request.deliverLate(answerFor('var:order.increment_id'));

    assertSame(context.inspector.hasEntry(), false, 'nothing was filled in');
    assertSame(context.inspector.isOpen(), false, 'and it stayed closed');
});

test('a cancelled lookup is never reported as a fault', function () {
    var context = setUp();

    context.activate('{{var order.increment_id}}');
    context.inspector.close();

    assertLike(context.orchestrator.statuses(), [], 'the editor was told nothing');
    assertSame(context.inspector.loadError(), '', 'and nothing was shown either');
});

test('an answer read from what is already known supersedes a lookup still on the wire', function () {
    var context = setUp(),
        stale;

    openAnswered(context, '{{var order.increment_id}}');

    context.activate('{{config path="general/store_information/name"}}');
    stale = context.ajax.last();

    context.activate('{{var order.increment_id}}');

    assertSame(context.inspector.hasEntry(), true, 'answered from what was already known');
    assertSame(context.inspector.reference(), 'var:order.increment_id', 'the directive just clicked');

    stale.deliverLate(answerFor('config:general/store_information/name', {title: 'Store name'}));

    assertSame(context.inspector.title(), 'Order number', 'the older answer did not land');
});

test('every lookup and every write is handed to the editor to count and to cancel', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id}}', {});
    assertSame(context.orchestrator.trackedCount(), 1, 'the lookup');

    context.inspector.affordanceKind('inline');
    context.inspector.startSave();

    assertSame(context.orchestrator.trackedCount(), 2, 'and the write');
});

test('an answer to a write made on the directive before this one never lands here', function () {
    var context = setUp(),
        firstWrite;

    // A second write cannot begin while one is in flight, so the way one is superseded is the way
    // an admin meets it: the write is still travelling when the next directive is clicked, and the
    // panel it would fill is now describing something else.
    openAnswered(context, '{{var order.increment_id}}');
    context.inspector.affordanceKind('inline');
    context.inspector.inlineValue('one');
    context.inspector.startSave();
    firstWrite = context.ajax.last();

    openAnswered(context, '{{config path="general/store_information/name"}}');
    context.inspector.affordanceKind('inline');
    context.inspector.inlineValue('two');
    context.inspector.startSave();

    context.ajax.last().resolve({
        success: true,
        value: {available: true, exact: true, preview: 'two', scopeLabel: 'Danish'}
    });
    firstWrite.resolve({
        success: true,
        value: {available: true, exact: true, preview: 'one', scopeLabel: 'Danish'}
    });

    assertSame(context.inspector.valuePreview(), 'two', 'the superseded answer was dropped');
    assertSame(context.inspector.savedScopeLabel(), 'Danish', 'and the panel still describes its own write');
});

test('a value already on the wire is left to finish when the panel closes', function () {
    var context = setUp({storeId: 3}),
        write;

    openAnswered(context, '{{var order.increment_id}}');
    context.inspector.affordanceKind('inline');
    context.inspector.startSave();
    write = context.ajax.last();

    context.inspector.close();

    assertFalse(
        write.wasAborted(),
        'it has reached the server either way, and cancelling would throw away the answer saying so'
    );
});

test('the same directive asked about twice at one store view is looked up once', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id}}');
    assertSame(context.ajax.count(), 1, 'one lookup');

    context.activate('{{var order.increment_id|escape}}');

    assertSame(context.ajax.count(), 1, 'the second click asked nothing');
    assertSame(context.inspector.hasEntry(), true, 'and was answered anyway');
});

test('switching the store view takes the popover away and forgets what was read there', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id}}');

    context.orchestrator.storeId(4);

    assertSame(context.inspector.isOpen(), false, 'the popover went with the old store view');

    context.activate('{{var order.increment_id}}');

    assertSame(context.ajax.count(), 2, 'the value is read again at the new store view');
    assertSame(context.inspector.hasEntry(), false, 'and nothing is claimed until it comes back');
});

test('the modifier list is not fetched again after a store switch', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id}}');
    context.orchestrator.storeId(4);
    context.activate('{{var order.increment_id}}');

    assertSame(
        context.inspector.modifierRows().length,
        MODIFIERS.length,
        'the published list is still on screen while the value is being read again'
    );
});

test('a value written empties everything that was read before it', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id}}');

    context.inspector.affordanceKind('inline');
    context.inspector.startSave();
    context.ajax.last().resolve({
        success: true,
        value: {available: true, exact: true, preview: 'written', scopeLabel: 'Danish'}
    });

    context.inspector.close();
    context.activate('{{var order.increment_id}}');

    assertSame(context.ajax.count(), 3, 'the entry is read again rather than reused');
});

test('a lookup that fails says so on the panel and in the editor', function () {
    var context = setUp();

    context.activate('{{var order.increment_id}}');
    context.ajax.last().reject('error');

    assertTrue(context.inspector.loadError() !== '', 'the panel says something went wrong');
    assertSame(context.inspector.isLoading(), false, 'and stops waiting');
    assertSame(context.orchestrator.statuses().length, 1, 'the editor was told once');
});

test('an answer that refuses is shown in the words the server refused in', function () {
    var context = setUp();

    context.activate('{{var order.increment_id}}');
    context.ajax.last().resolve({success: false, message: 'This store view is not yours to read.'});

    assertSame(
        context.inspector.loadError(),
        'This store view is not yours to read.',
        'the server said something specific and it was not flattened'
    );
});

test('an answer carrying no entry for the directive is said, not shown as an empty entry', function () {
    var context = setUp();

    context.activate('{{var order.increment_id}}');
    context.ajax.last().resolve({success: true, modifiers: MODIFIERS, entries: {}});

    assertSame(context.inspector.hasEntry(), false, 'nothing is claimed');
    assertTrue(context.inspector.loadError() !== '', 'and the gap is stated');
});

test('no address for the knowledge base is stated rather than requested', function () {
    var context = setUp({urls: {}});

    context.activate('{{var order.increment_id}}');

    assertSame(context.ajax.count(), 0, 'nothing was sent');
    assertTrue(context.inspector.loadError() !== '', 'and the reason is on screen');
    assertSame(context.inspector.isLoading(), false, 'nothing is being waited for');
});

test('every reason a rewrite can refuse for gets a sentence of its own', function () {
    var reasons = ['gone', 'changed', 'readOnly', 'noEditor', 'invalidChain', 'something-new'],
        said = reasons.map(function (reason) {
            var context = setUp();

            openAnswered(context, '{{var order.increment_id|escape}}');
            context.templateEditor.answerWith({written: false, reason: reason});
            context.inspector.clearModifiers();

            return context.inspector.noticeText();
        });

    said.forEach(function (sentence, index) {
        assertTrue(sentence !== '', 'the reason ' + reasons[index] + ' was worded');
    });

    assertSame(
        said.slice(0, 5).filter(function (sentence, index) {
            return said.indexOf(sentence) !== index;
        }).length,
        0,
        'no two of the reasons the rewrite names share a sentence'
    );
    assertTrue(
        said[0] !== said[1],
        'a directive that is gone and one that changed are not told the same thing'
    );
    assertTrue(
        said.indexOf(said[5]) === 5 || said[5] !== said[0],
        'a reason nobody has met yet still says something'
    );
});

test('a read-only version never reaches the code editor at all', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id|escape}}', {readOnly: true});
    context.inspector.clearModifiers();

    assertLike(context.templateEditor.calls(), [], 'no rewrite was even attempted');
    assertTrue(context.inspector.noticeText() !== '', 'and the reason is on screen');
});

test('a formatting change with no code editor registered refuses instead of throwing', function () {
    var context = setUp({withTemplateEditor: false});

    context.inspector.hasOccurrence(true);
    context.inspector.clearModifiers();

    assertTrue(context.inspector.noticeText() !== '', 'it says the editor is not ready');
});

test('a rewrite that reports no directive back refuses rather than keeping the old one', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id|escape}}');
    context.templateEditor.answerWith({written: true});
    context.inspector.clearModifiers();

    assertTrue(context.inspector.noticeText() !== '', 'the change is not claimed to have landed');
    assertLike(
        context.inspector.chainCalls().map(function (call) {
            return call.name;
        }),
        ['escape'],
        'and the chain on screen is the one that was there'
    );
});

test('a refusal replaces the body and drops a confirmation that was waiting', function () {
    var context = setUp({storeId: 0});

    openAnswered(context, '{{var order.increment_id|escape}}', {});
    context.inspector.affordanceKind('inline');
    context.inspector.startSave();

    assertSame(context.inspector.confirmVisible(), true, 'the default scope was asked about');

    context.templateEditor.answerWith({written: false, reason: 'gone'});
    context.inspector.clearModifiers();

    assertSame(context.inspector.confirmVisible(), false, 'the question went with the body');
    assertTrue(context.inspector.noticeText() !== '', 'and the reason took its place');
});

test('the chain kept after a write is the one that came back, not the one that was sent', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id|escape}}');
    context.templateEditor.answerWith({
        written: true,
        occurrence: directive('{{var order.increment_id|escape|nl2br}}')
    });
    context.inspector.clearModifiers();

    assertSame(context.inspector.modifierText(), '|escape|nl2br', 'read back off the document');
});

test('which way a modifier toggle goes is read from the document, not from the checkbox', function () {
    var context = setUp(),
        row;

    openAnswered(context, '{{var order.increment_id|escape}}');
    context.templateEditor.answerWith(function (chainText) {
        return {
            written: true,
            occurrence: directive('{{var order.increment_id' + chainText + '}}')
        };
    });

    row = context.row('escape');
    assertSame(row.applied(), true, 'the document has it');

    // The two-way binding writes the row's flag before the click handler beside it is called, so
    // by the time the handler runs the flag already says what the click is asking for.
    row.applied(false);
    context.inspector.toggleModifier(row);

    assertSame(context.lastChainWritten(), '', 'the modifier the document had was taken off');

    row = context.row('escape');
    row.applied(true);
    context.inspector.toggleModifier(row);

    assertSame(context.lastChainWritten(), '|escape', 'and putting it back adds it once');
});

test('a modifier is never written twice, however often it is toggled on', function () {
    var context = setUp(),
        row;

    openAnswered(context, '{{var order.increment_id|escape}}');
    context.templateEditor.answerWith({written: true, occurrence: directive('{{var order.increment_id|escape}}')});

    row = context.row('escape');
    row.applied(true);
    context.inspector.toggleModifier(row);

    assertSame(context.lastChainWritten(), '', 'a modifier the document already has is taken off');
});

test('the argument written is the one chosen, not the control holding it', function () {
    var context = setUp(),
        row;

    openAnswered(context, '{{var order.increment_id}}');
    context.templateEditor.answerWith({written: true, occurrence: directive('{{var order.increment_id|date:long}}')});

    row = context.row('date');
    row.argument('long');
    row.applied(true);
    context.inspector.toggleModifier(row);

    assertSame(context.lastChainWritten(), '|date:long', 'the value, written as text');
});

test('choosing the argument the server publishes as its default writes no argument at all', function () {
    var context = setUp(),
        row;

    openAnswered(context, '{{var order.increment_id}}');
    context.templateEditor.answerWith({written: true, occurrence: directive('{{var order.increment_id|date}}')});

    row = context.row('date');
    row.applied(true);
    context.inspector.toggleModifier(row);

    assertSame(context.lastChainWritten(), '|date', 'the shorter spelling an admin would have typed');
});

test('choosing an argument applies the modifier even when it was not applied yet', function () {
    var context = setUp(),
        row;

    openAnswered(context, '{{var order.increment_id}}');
    context.templateEditor.answerWith({written: true, occurrence: directive('{{var order.increment_id|date:short}}')});

    row = context.row('date');
    context.inspector.setModifierArgument(row, {target: {value: 'short'}});

    assertSame(context.lastChainWritten(), '|date:short', 'applied with what was chosen');
});

test('clearing takes every modifier off in one write', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id|escape|nl2br}}');
    context.templateEditor.answerWith({written: true, occurrence: directive('{{var order.increment_id}}')});
    context.inspector.clearModifiers();

    assertSame(context.lastChainWritten(), '', 'nothing left');
    assertSame(context.templateEditor.calls().length, 1, 'in one rewrite, not one per modifier');
});

test('the rows are not rebuilt when the chain changes', function () {
    var context = setUp(),
        built,
        before,
        after;

    openAnswered(context, '{{var order.increment_id|escape}}');
    before = context.inspector.modifierRows();
    built = context.inspector.modifierRows.evaluations();

    context.templateEditor.answerWith({
        written: true,
        occurrence: directive('{{var order.increment_id|escape|nl2br}}')
    });
    context.inspector.toggleModifier(context.row('nl2br'));
    after = context.inspector.modifierRows();

    assertTrue(before === after, 'the same list, so nothing under it is torn down');
    assertTrue(before[0] === after[0], 'and the same row, so the control keeps its focus');
    assertSame(context.inspector.modifierRows.evaluations(), built, 'the list was not worked out again');
    assertSame(context.row('nl2br').applied(), true, 'though what the row says did change');
});

test('the rows are rebuilt when the server publishes a different list, and only then', function () {
    var context = setUp(),
        built;

    openAnswered(context, '{{var order.increment_id}}');
    assertSame(context.inspector.modifierRows().length, MODIFIERS.length, 'what was published');
    built = context.inspector.modifierRows.evaluations();

    context.inspector.chainCalls([{name: 'escape', args: []}]);
    assertSame(context.inspector.modifierRows.evaluations(), built, 'a chain change is not a new list');

    context.inspector.modifierDescriptors([{name: 'escape'}]);

    assertSame(context.inspector.modifierRows().length, 1, 'the new list');
    assertSame(context.inspector.modifierRows.evaluations(), built + 1, 'worked out once more');
});

test('after a write the rows say what the document says, not what the click asked for', function () {
    var context = setUp(),
        row;

    openAnswered(context, '{{var order.increment_id}}');

    // A rewrite that lands but leaves the directive without the modifier - the document is the
    // only thing that decides what the checkbox shows afterwards.
    context.templateEditor.answerWith({written: true, occurrence: directive('{{var order.increment_id}}')});

    row = context.row('nl2br');
    row.applied(true);
    context.inspector.toggleModifier(row);

    assertSame(context.row('nl2br').applied(), false, 'put back the way the document has it');
});

test('opening another directive brings every row in line with that directive', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id|escape|date:short}}');

    assertSame(context.row('escape').applied(), true, 'in the chain');
    assertSame(context.row('date').applied(), true, 'also in the chain');
    assertSame(context.row('date').argument(), 'short', 'with the argument it was written with');
    assertSame(context.row('nl2br').applied(), false, 'not in the chain');

    context.inspector.close();
    context.activate('{{var order.increment_id}}');

    assertSame(context.row('escape').applied(), false, 'the new directive has no chain');
    assertSame(context.row('date').argument(), 'medium', 'and the argument falls back to the default');
});

test('a value written at the default scope is asked about before it is sent', function () {
    var context = setUp({storeId: 0});

    openAnswered(context, '{{var order.increment_id}}');
    context.inspector.affordanceKind('inline');

    assertSame(context.inspector.scopeIsDefault(), true, 'the panel knows which scope it is in');

    context.inspector.startSave();

    assertSame(context.inspector.confirmVisible(), true, 'and asks first');
    assertSame(context.ajax.count(), 1, 'nothing has been sent');

    context.inspector.confirmSave();

    assertSame(context.inspector.confirmVisible(), false, 'the question is done with');
    assertSame(context.ajax.count(), 2, 'and the write went out');
});

test('abandoning the question about the default scope sends nothing', function () {
    var context = setUp({storeId: 0});

    openAnswered(context, '{{var order.increment_id}}');
    context.inspector.affordanceKind('inline');
    context.inspector.startSave();
    context.inspector.cancelSave();

    assertSame(context.inspector.confirmVisible(), false, 'the question is gone');
    assertSame(context.ajax.count(), 1, 'and nothing was written');
});

test('a value written at a store view names that store view and goes straight out', function () {
    var context = setUp({storeId: 3, storeName: 'Danish'});

    openAnswered(context, '{{var order.increment_id}}');
    context.inspector.affordanceKind('inline');

    assertSame(context.inspector.scopeIsDefault(), false, 'not the default scope');
    assertSame(context.inspector.scopeStoreName(), 'Danish', 'the name the editor spells');

    context.inspector.startSave();

    assertSame(context.inspector.confirmVisible(), false, 'nothing to agree to');
    assertSame(context.ajax.count(), 2, 'the write went out');
    assertSame(context.ajax.last().options.data.store_id, 3, 'at the store view on screen');
});

test('the scope the panel reports afterwards is the one the answer named', function () {
    var context = setUp({storeId: 3});

    openAnswered(context, '{{var order.increment_id}}');
    context.inspector.affordanceKind('inline');
    context.inspector.startSave();
    context.ajax.last().resolve({
        success: true,
        value: {available: true, exact: true, preview: 'written', scopeLabel: 'Default Config'}
    });

    assertSame(context.inspector.savedScopeLabel(), 'Default Config', 'reported, not assumed');
    assertSame(context.inspector.valuePreview(), 'written', 'and the value came back with it');
    assertSame(context.inspector.inlineMessageIsError(), false, 'it landed');
});

test('a write the server refuses is shown in the words it refused in', function () {
    var context = setUp({storeId: 3});

    openAnswered(context, '{{var order.increment_id}}');
    context.inspector.affordanceKind('inline');
    context.inspector.startSave();
    context.ajax.last().resolve({success: false, message: 'That configuration path is locked.'});

    assertSame(context.inspector.inlineMessage(), 'That configuration path is locked.', 'as sent');
    assertSame(context.inspector.inlineMessageIsError(), true, 'and marked as a refusal');
    assertSame(context.inspector.savedScopeLabel(), '', 'nothing is claimed to have been written');
});

test('a write that never arrives says so and tells the editor', function () {
    var context = setUp({storeId: 3});

    openAnswered(context, '{{var order.increment_id}}');
    context.inspector.affordanceKind('inline');
    context.inspector.startSave();
    context.ajax.last().reject('timeout');

    assertTrue(context.inspector.inlineMessage() !== '', 'the panel says so');
    assertSame(context.inspector.inlineMessageIsError(), true, 'as a fault');
    assertSame(context.inspector.inlineSaving(), false, 'and it stops waiting');
    assertSame(context.orchestrator.statuses().length, 1, 'the editor was told');
});

test('the value editor is filled only from a value that is whole and real', function () {
    var shortened = setUp(),
        sampled = setUp(),
        whole = setUp();

    shortened.activate('{{var order.increment_id}}');
    shortened.answer(answerFor('var:order.increment_id', {
        value: {available: true, exact: true, preview: 'a long value cut short', truncated: true}
    }));
    assertSame(shortened.inspector.inlineValue(), '', 'a preview that was cut is not the value');

    sampled.activate('{{var order.increment_id}}');
    sampled.answer(answerFor('var:order.increment_id', {
        value: {available: true, exact: false, preview: '000000123'}
    }));
    assertSame(sampled.inspector.inlineValue(), '', 'sample data is not the value either');

    whole.activate('{{var order.increment_id}}');
    whole.answer(answerFor('var:order.increment_id', {
        value: {available: true, exact: true, preview: '000000123', truncated: false}
    }));
    assertSame(whole.inspector.inlineValue(), '000000123', 'the whole of a real value is offered');
});

test('a variable named by the chooser has no formatting to change and says so if asked', function () {
    var context = setUp();

    context.chooser.describe('var:customer.name');
    context.answer(answerFor('var:customer.name', {title: 'Customer name'}));

    assertSame(context.inspector.isOpen(), true, 'the panel opened');
    assertSame(context.inspector.reference(), 'var:customer.name', 'on the variable that was named');
    assertSame(context.inspector.hasOccurrence(), false, 'there is nothing in the template yet');
    assertSame(context.inspector.showFormatting(), false, 'so no formatting section is offered');

    context.inspector.clearModifiers();

    assertLike(context.templateEditor.calls(), [{
        anchor: null,
        chainText: '',
        expected: {reference: 'var:customer.name', modifierText: ''}
    }], 'a rewrite aimed at nothing is what the editor refuses, and it is the editor that refuses it');
});

test('a lookup states the store view, the template and the form key it belongs to', function () {
    var context = setUp({storeId: 3, templateId: 'sales_email_order_template'});

    context.activate('{{var order.increment_id}}');

    assertLike(context.ajax.last().options.data, {
        form_key: 'FORMKEY',
        store_id: 3,
        template_id: 'sales_email_order_template',
        references: ['var:order.increment_id']
    }, 'everything the server needs to answer at the right scope');
});

test('Escape closes the panel, and only while it is open', function () {
    var context = setUp();

    context.browser.document.dispatch('keydown', {key: 'Escape'});
    openAnswered(context, '{{var order.increment_id}}');

    assertSame(context.inspector.isOpen(), true, 'open');

    context.browser.document.dispatch('keydown', {key: 'a'});
    assertSame(context.inspector.isOpen(), true, 'another key is not a dismissal');

    context.browser.document.dispatch('keydown', {key: 'Escape'});
    assertSame(context.inspector.isOpen(), false, 'closed');
});

test('a press inside the panel leaves it alone and a press outside dismisses it', function () {
    var context = setUp(),
        root = browserStub.createElement('ete-inspector'),
        inside = browserStub.createElement('ete-inspector-input').appendTo(root),
        outside = browserStub.createElement('ete-toolbar');

    context.inspector.onRootRendered(root);
    openAnswered(context, '{{var order.increment_id}}');

    context.browser.document.dispatch('mousedown', {target: inside});
    assertSame(context.inspector.isOpen(), true, 'a press on its own controls is not a dismissal');

    context.browser.document.dispatch('mousedown', {target: outside});
    assertSame(context.inspector.isOpen(), false, 'a press anywhere else is');
});

test('the scroll that brings the clicked line into view does not take the panel away again', function () {
    var context = setUp(),
        root = browserStub.createElement('ete-inspector'),
        elsewhere = browserStub.createElement('ete-editor-pane');

    context.inspector.onRootRendered(root);
    openAnswered(context, '{{var order.increment_id}}');

    context.browser.document.dispatch('scroll', {target: elsewhere});
    assertSame(context.inspector.isOpen(), true, 'the scroll belonging to the click is part of opening');

    context.clock.tick(1);

    context.browser.document.dispatch('scroll', {target: elsewhere});
    assertSame(context.inspector.isOpen(), false, 'a later scroll moves the directive out from under it');
});

test('the panel is placed inside the window even before it exists', function () {
    var context = setUp();

    openAnswered(context, '{{var order.increment_id}}');

    assertTrue(/^\d+px$/.test(context.inspector.panelLeft()), 'a left edge in pixels');
    assertTrue(/^\d+px$/.test(context.inspector.panelMaxHeight()), 'a height it may not grow past');
    assertTrue(
        context.inspector.panelTop() === '' || context.inspector.panelBottom() === '',
        'pinned by one edge, never by both'
    );
});

test('tearing the panel down takes back every listener it put on the page', function () {
    var context = setUp(),
        document = context.browser.document,
        window = context.browser.window;

    assertSame(document.listenerCount('mouseup'), 1, 'watching for where a gesture ended');
    assertSame(document.listenerCount('mousedown'), 1, 'watching for a press outside');
    assertSame(document.listenerCount('keydown'), 1, 'watching for Escape');
    assertSame(document.listenerCount('scroll'), 1, 'watching for a scroll');
    assertSame(window.listenerCount('resize'), 1, 'watching for a resize');

    context.activate('{{var order.increment_id}}');
    context.inspector.destroy();

    assertSame(document.listenerCount('mouseup'), 0, 'given back');
    assertSame(document.listenerCount('mousedown'), 0, 'given back');
    assertSame(document.listenerCount('keydown'), 0, 'given back');
    assertSame(document.listenerCount('scroll'), 0, 'given back');
    assertSame(window.listenerCount('resize'), 0, 'given back');
    assertSame(context.clock.pending(), 0, 'and nothing is left waiting to run');
});

test('what an entry says is put on screen exactly as it arrived', function () {
    var context = setUp();

    context.activate('{{var order.increment_id}}');
    context.answer(answerFor('var:order.increment_id', {
        known: true,
        title: 'Order number',
        summary: 'The number the order was placed under.',
        caveats: ['Empty until the order is placed.'],
        affordance: {kind: 'link', label: 'Order settings', url: 'https://example.test/admin/order'}
    }));

    assertSame(context.inspector.title(), 'Order number', 'the title');
    assertSame(context.inspector.summary(), 'The number the order was placed under.', 'the summary');
    assertLike(context.inspector.caveats(), ['Empty until the order is placed.'], 'the caveats');
    assertSame(context.inspector.affordanceKind(), 'link', 'the kind of affordance');
    assertSame(context.inspector.affordanceUrl(), 'https://example.test/admin/order', 'its address');
    assertSame(context.inspector.showValueEditor(), false, 'a link is not an editor');
    assertFalse(context.inspector.isLoading(), 'and the wait is over');
});

test('an entry the knowledge base does not have is said to be undocumented rather than left blank', function () {
    var context = setUp();

    context.activate('{{var something.nobody.wrote.down}}');
    context.answer(answerFor('var:something.nobody.wrote.down', {
        known: false,
        title: '',
        summary: '',
        origin: {kind: 'nothing-like-this'}
    }));

    assertSame(context.inspector.known(), false, 'not documented');
    assertTrue(context.inspector.title() !== '', 'and it still has a heading');
    assertTrue(context.inspector.originLabel() !== '', 'an origin nobody recognises is still worded');
});
