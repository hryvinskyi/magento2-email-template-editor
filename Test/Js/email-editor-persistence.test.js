/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What the editor stores and sends, and the Tailwind CSS that goes with it.
 *
 * Every rule here is about one pairing: the template content and the stylesheet stored beside it
 * must belong together, because that stylesheet is what reaches customers. The ways it used to
 * come apart - a template inheriting the previous template's stylesheet, a reload putting the
 * stored stylesheet back over a fresh compile and saving it again, a save giving up on the compile
 * and storing an older stylesheet as current - are each staged against the real editor component.
 *
 * The component runs against the stand-ins of magento-stub and a compiler whose answers the test
 * delivers by hand. Nothing here renders; what is proved is what the component decides to store,
 * send and report.
 */
var harness = require('./harness'),
    magentoStub = require('./magento-stub'),
    koStub = require('./knockout-stub'),
    browserStub = require('./browser-stub'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertTrue = harness.assertTrue,
    assertFalse = harness.assertFalse;

/**
 * A compiler whose compiles the test answers
 *
 * @return {Object}
 */
function createCompiler() {
    var compiles = [];

    return {
        compiles: compiles,

        /**
         * @return {void}
         */
        setTheme: function () {
            return undefined;
        },

        /**
         * @param {string} html
         * @return {Object} a promise the test settles through `answer`
         */
        compile: function (html) {
            var handlers = [],
                settled = false,
                value,
                promise;

            /**
             * @param {Function} handler
             * @return {Object}
             */
            function register(handler) {
                if (settled) {
                    handler(value);
                } else {
                    handlers.push(handler);
                }

                return promise;
            }

            promise = {done: register, always: register};
            compiles.push({
                html: html,
                promise: promise,

                /**
                 * @param {string} css
                 * @return {void}
                 */
                answer: function (css) {
                    settled = true;
                    value = css;
                    handlers.forEach(function (handler) {
                        handler(css);
                    });
                }
            });

            return promise;
        },

        /**
         * @return {boolean}
         */
        hasCompileFailed: function () {
            return false;
        },

        /**
         * @return {Object} the compile most recently started
         */
        last: function () {
            return compiles[compiles.length - 1];
        }
    };
}

/**
 * Build an editor wired to stand-ins, past the point its initialisation would have reached
 *
 * @param {Object} [options]
 * @param {*} [options.themeId] what the theme editor reports as selected
 * @return {Object}
 */
function setUp(options) {
    var settings = options || {},
        ko = koStub.create(),
        ajax = magentoStub.createAjax(),
        browser = browserStub.create(),
        compiler = createCompiler(),
        alerts = [],
        draftManager = {
            cleaned: [],
            rescheduled: 0,
            generation: 5,
            markClean: function (generation) {
                this.cleaned.push(generation);
            },
            reschedule: function () {
                this.rescheduled++;
            },
            getGeneration: function () {
                return this.generation;
            },
            updateSavedTime: function () {
                return undefined;
            },
            markDirty: function () {
                return undefined;
            }
        },
        content = {value: '<p class="m-2">Hello</p>'},
        jquery = {
            ajax: ajax.jquery.ajax,
            mage: {__: magentoStub.translate}
        },
        Editor = harness.loadStubbedModule(
            'email-editor.js',
            {
                underscore: {},
                uiComponent: magentoStub.createComponentClass(ko),
                ko: ko,
                jquery: jquery,
                uiRegistry: magentoStub.createRegistry(),
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/tailwind-compiler': compiler,
                'Magento_Ui/js/modal/alert': function (config) {
                    alerts.push(config);
                },
                'mage/translate': magentoStub.translate,
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/tailwind/compile-wait':
                    harness.loadPureModule('email-editor/tailwind/compile-wait.js'),
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/custom-data-skeleton':
                    harness.loadPureModule('email-editor/custom-data-skeleton.js'),
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/draft-payload':
                    harness.loadPureModule('email-editor/draft-payload.js'),
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/html-text':
                    harness.loadPureModule('email-editor/html-text.js')
            },
            browser.globals()
        ),
        editor = Object.create(Editor.prototype),
        statuses = [],
        previewContent = [];

    ['statusBarText', 'tailwindCssOutput', 'currentTemplateId', 'viewingDefault', 'currentTemplateStatus',
        'subject', 'selectedProvider', 'selectedEntityId', 'hasDraft', 'sendTestEmailAddress',
        'sendTestEmailFeedback', 'sendTestEmailHasError', 'sendTestEmailSending'
    ].forEach(function (name) {
        editor[name] = ko.observable('');
    });

    editor.currentTemplateId('sales_email_order_template');
    editor.viewingDefault(false);
    editor.currentTemplateStatus('draft');
    editor.selectedProvider('mock');
    editor.hasDraft(false);
    editor.isReadOnly = ko.observable(false);
    editor.urls = {saveDraft: 'save', publish: 'publish', preview: 'preview', sendTestEmail: 'send'};
    editor._lastTailwindCss = '';
    editor._compileKey = null;
    editor._compilePromise = null;
    editor._tailwindCssContent = null;
    editor._previewToken = 0;
    editor._saveDraftInFlight = false;
    editor._currentEntityId = '11';
    editor.draftManager = draftManager;
    editor.templateSidebar = null;
    editor.draftListPanel = null;
    editor.schedulePanel = null;
    editor.customCssEditor = null;
    editor.customDataEditor = null;
    editor.templateEditor = {
        getValue: function () {
            return content.value;
        }
    };
    editor.themeEditor = {
        getCurrentThemeId: function () {
            return Object.prototype.hasOwnProperty.call(settings, 'themeId') ? settings.themeId : 3;
        },
        getThemeCss: function () {
            return '@theme { --color-primary: red; }';
        }
    };
    editor.previewPanel = {
        showLoading: function () {
            return undefined;
        },
        hideLoading: function () {
            return undefined;
        },
        setContent: function (html) {
            previewContent.push(html);
        }
    };
    editor.setStatus = function (status) {
        statuses.push(status);
    };
    editor.updateBadges = function () {
        return undefined;
    };
    editor._ajax = function (url, data, method) {
        return ajax.jquery.ajax({url: url, data: data, type: method});
    };

    return {
        editor: editor,
        ajax: ajax,
        compiler: compiler,
        clock: browser.clock,
        draftManager: draftManager,
        alerts: alerts,
        statuses: statuses,
        previewContent: previewContent,

        /**
         * @param {string} value what the template editor now holds
         * @return {void}
         */
        type: function (value) {
            content.value = value;
        }
    };
}

test('a template stored without Tailwind CSS does not inherit the previous template\'s', function () {
    var env = setUp();

    env.editor._restoreStoredTailwindCss('.previous-template{}');
    env.editor._restoreStoredTailwindCss('');

    assertSame(env.editor._lastTailwindCss, '', 'stylesheet after loading a template without one');
    assertSame(env.editor.tailwindCssOutput(), '', 'stylesheet shown after loading a template without one');
});

test('reloading the same content recompiles instead of keeping the stored stylesheet', function () {
    var env = setUp();

    env.editor._compileTailwind('<p class="m-2">Hello</p>');
    env.compiler.last().answer('.m-2{margin:.5rem}');
    assertSame(env.editor._lastTailwindCss, '.m-2{margin:.5rem}', 'stylesheet after the first compile');

    // Reload: the stored stylesheet - produced earlier, by a compiler that missed classes - is
    // put back for the very same content and theme.
    env.editor._restoreStoredTailwindCss('.stale{}');
    env.editor._compileTailwind('<p class="m-2">Hello</p>');

    assertSame(env.compiler.compiles.length, 2, 'compiles asked for');
    env.compiler.last().answer('.m-2{margin:.5rem}');
    assertSame(env.editor._lastTailwindCss, '.m-2{margin:.5rem}', 'stylesheet after reloading');
});

test('a save sends the stylesheet compiled for its content, and the theme that is selected', function () {
    var env = setUp(),
        sent;

    env.editor.saveDraft();
    env.compiler.last().answer('.m-2{margin:.5rem}');

    sent = env.ajax.last().options.data;
    assertSame(sent.tailwind_css, '.m-2{margin:.5rem}', 'stylesheet sent');
    assertSame(sent.theme_id, 3, 'theme sent');
    assertSame(sent.status, 'draft', 'status sent');
    assertFalse('intent' in sent, 'a draft save does not ask to write the published row');
});

test('a save leaves out a theme that is not known instead of clearing the stored one', function () {
    var env = setUp({themeId: null});

    env.editor.saveDraft();
    env.compiler.last().answer('');

    assertFalse('theme_id' in env.ajax.last().options.data, 'theme id carried');
    assertSame(env.ajax.last().options.data.tailwind_css, '', 'empty stylesheet of this content carried');
});

test('a save whose compile never answers stores nothing and stays dirty', function () {
    var env = setUp();

    env.editor.saveDraft();
    env.clock.tick(2000);
    assertSame(env.ajax.count(), 0, 'requests while the compile is slow');

    env.clock.tick(13000);
    assertSame(env.ajax.count(), 0, 'requests after giving up');
    assertLike(env.draftManager.cleaned, [], 'draft reported clean');
    assertSame(env.draftManager.rescheduled, 1, 'auto-save armed again');
    assertFalse(env.editor._saveDraftInFlight, 'save still claimed in flight');
    assertSame(env.statuses[env.statuses.length - 1], 'modified', 'status after giving up');

    // The compile answering late changes nothing about the save that gave up.
    env.compiler.last().answer('.late{}');
    assertSame(env.ajax.count(), 0, 'requests after the late answer');
});

test('a save answered by the server with a fork points the editor at the working draft', function () {
    var env = setUp(),
        pointedAt = [];

    env.editor.templateSidebar = {
        activeOverrideId: function (id) {
            pointedAt.push(id);
        },
        expandTemplate: function () {
            return undefined;
        },
        markDraft: function () {
            return undefined;
        }
    };

    env.editor.saveDraft();
    env.compiler.last().answer('.m-2{}');
    env.ajax.last().resolve({success: true, entity_id: 27, forked: true});

    assertLike(pointedAt, [27], 'draft the sidebar points at');
    assertSame(env.editor._currentEntityId, 27, 'entity the editor now edits');
    assertSame(env.editor.currentTemplateStatus(), 'draft', 'status of what is edited');
    assertLike(env.draftManager.cleaned, [5], 'revision reported clean');
});

test('the save made on the way to publishing asks to write the published row', function () {
    var env = setUp();

    env.editor.currentTemplateStatus('published');
    env.editor.publishTemplate('ship it');
    env.compiler.last().answer('.m-2{}');

    assertSame(env.ajax.last().options.url, 'save', 'first request of a publish');
    assertSame(env.ajax.last().options.data.intent, 'publish', 'intent of that save');
});

test('a publish whose compile never answers publishes nothing and says why', function () {
    var env = setUp();

    env.editor.publishTemplate('ship it');
    env.clock.tick(15000);

    assertSame(env.ajax.count(), 0, 'requests');
    assertSame(env.alerts.length, 1, 'alerts raised');
    assertSame(env.statuses[env.statuses.length - 1], 'error', 'status');
});

test('the test email is rendered with the theme on screen', function () {
    var env = setUp();

    env.editor.sendTestEmailAddress('qa@example.com');
    env.editor.sendTestEmail();
    env.compiler.last().answer('.m-2{}');

    assertSame(env.ajax.last().options.data.theme_css, '@theme { --color-primary: red; }', 'theme sent');
    assertSame(env.ajax.last().options.data.tailwind_css, '.m-2{}', 'stylesheet sent');
});

test('a test email whose compile never answers is not sent', function () {
    var env = setUp();

    env.editor.sendTestEmailAddress('qa@example.com');
    env.editor.sendTestEmail();
    env.clock.tick(15000);

    assertSame(env.ajax.count(), 0, 'requests');
    assertTrue(env.editor.sendTestEmailHasError(), 'error shown');
    assertFalse(env.editor.sendTestEmailSending(), 'still shown as sending');
});

test('a preview error from the server is shown as text, not run as markup', function () {
    var env = setUp();

    env.editor._sendPreviewRequest('<p>x</p>', undefined);
    env.ajax.last().resolve({success: false, message: '<img src=x onerror=alert(1)>'});

    assertSame(
        env.previewContent[0],
        '<div style="color:#eb5202;padding:20px;">Error: &lt;img src=x onerror=alert(1)&gt;</div>',
        'preview content'
    );
});
