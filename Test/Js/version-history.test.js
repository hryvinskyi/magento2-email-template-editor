/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The version history panel: which answer may change the screen, and what the restore dialog says.
 *
 * An administrator clicks through versions faster than the server renders them, so the answers
 * arrive out of order often enough. The rule being proved is that only the answer to the latest
 * click is shown - the preview of a version the user has moved past must not replace the one they
 * are looking at, and the same for a comparison.
 */
var harness = require('./harness'),
    magentoStub = require('./magento-stub'),
    koStub = require('./knockout-stub'),
    browserStub = require('./browser-stub'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertFalse = harness.assertFalse;

/**
 * Build the panel against stand-ins and record what it announces
 *
 * @return {Object}
 */
function setUp() {
    var ko = koStub.create(),
        ajax = magentoStub.createAjax(),
        registry = magentoStub.createRegistry(),
        browser = browserStub.create({emailEditorConfig: {urls: {}, formKey: 'FORMKEY'}}),
        jquery = {ajax: ajax.jquery.ajax, mage: {__: magentoStub.translate}},
        parentResolver = harness.loadStubbedModule('email-editor/parent-resolver.js', {uiRegistry: registry}),
        failureReporter = harness.loadStubbedModule(
            'email-editor/failure-reporter.js',
            {'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver': parentResolver}
        ),
        VersionHistory = harness.loadStubbedModule(
            'email-editor/version-history.js',
            {
                uiComponent: magentoStub.createComponentClass(ko),
                ko: ko,
                jquery: jquery,
                'mage/translate': magentoStub.translate,
                emailEditorDiffEngine: {
                    computeDiff: function (oldContent, newContent) {
                        return [{lines: [{type: 'add', newLine: 1, text: newContent}]}];
                    }
                },
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver': parentResolver,
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/failure-reporter': failureReporter,
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/html-text':
                    harness.loadPureModule('email-editor/html-text.js')
            },
            browser.globals()
        ),
        panel = new VersionHistory({
            name: 'editor.versionHistory',
            parentName: 'editor',
            urls: {versionPreview: 'preview', versionDiff: 'diff'}
        }),
        events = [];

    panel.trigger = function (name, payload) {
        events.push({name: name, payload: payload});
    };

    return {panel: panel, ajax: ajax, events: events};
}

/**
 * The payloads of one announced event, in order
 *
 * @param {Array} events
 * @param {string} name
 * @return {Array}
 */
function payloadsOf(events, name) {
    return events.filter(function (event) {
        return event.name === name;
    }).map(function (event) {
        return event.payload;
    });
}

test('the preview of a version clicked past does not replace the one being looked at', function () {
    var env = setUp(),
        first,
        second;

    env.panel.previewVersion({version_id: 1});
    first = env.ajax.last();
    env.panel.previewVersion({version_id: 2});
    second = env.ajax.last();

    second.resolve({success: true, html: 'version two'});
    first.resolve({success: true, html: 'version one'});

    assertLike(payloadsOf(env.events, 'historyPreview'), [{success: true, html: 'version two'}], 'previews shown');
    assertSame(env.panel.activePreviewId(), 2, 'version marked as previewed');
});

test('a superseded preview that fails leaves the newer one alone', function () {
    var env = setUp(),
        first;

    env.panel.previewVersion({version_id: 1});
    first = env.ajax.last();
    env.panel.previewVersion({version_id: 2});

    first.reject('error');

    assertLike(payloadsOf(env.events, 'historyPreview'), [], 'previews announced');
    assertSame(env.panel.activePreviewId(), 2, 'version marked as previewed');
});

test('the comparison of a version clicked past does not replace the one being looked at', function () {
    var env = setUp(),
        first,
        second;

    env.panel.entries([
        {version_id: 3, version_number: 3},
        {version_id: 2, version_number: 2},
        {version_id: 1, version_number: 1}
    ]);

    env.panel.showVersionDiff({version_id: 2, version_number: 2});
    first = env.ajax.last();
    env.panel.showVersionDiff({version_id: 3, version_number: 3});
    second = env.ajax.last();

    second.resolve({success: true, version_a: {content: 'b'}, version_b: {content: 'c'}});
    first.resolve({success: true, version_a: {content: 'a'}, version_b: {content: 'b'}});

    assertSame(env.panel.diffLabel(), 'v3 changes', 'comparison shown');
});

test('the restore dialog carries the version comment as plain text', function () {
    var env = setUp(),
        detail;

    env.panel.restoreVersion({version_id: 4, version_number: 4, version_comment: 'Fix <b>footer</b>'});
    detail = payloadsOf(env.events, 'confirmAction')[0].detail;

    assertSame(detail, 'Comment: Fix <b>footer</b>', 'dialog detail');
    assertFalse(detail.indexOf('<strong>') !== -1, 'markup added around the comment');
});
