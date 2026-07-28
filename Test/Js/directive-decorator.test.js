/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What the decorator does to an editor, and what it leaves behind when it is done.
 *
 * The scanner and the decoration policy are the real ones - the questions this file asks are about
 * the carrying between them and a code editor, so standing either in would leave nothing worth
 * asking. The editor is a stand-in, and the one way it differs from the real one is the one that
 * makes the interesting case reachable: its marks do not move when the document is edited, which is
 * exactly the state a click during the pause before a redraw finds them in.
 *
 * Two properties are worth naming because they are the ones that are quiet when they break.
 *
 * A directive is marked, never replaced. A widget would take the admin's own bytes out of the
 * document and put a rendering in their place, and clicking a directive to type inside it would
 * stop working - which nothing would report. So the marks are checked for bracketing the directive
 * exactly and for carrying a class rather than content.
 *
 * Nothing survives a teardown. A timer that outlives it wakes against an editor that has left the
 * page, and marks left on a reused editor are drawn twice; both look like nothing at all until they
 * look like a stall or a doubled highlight.
 *
 * What none of this can say: whether the class makes a directive look clickable, whether a real
 * CodeMirror reports these events in this order, and what its own handlers do with a press first.
 */
var harness = require('./harness'),
    codeMirrorStub = require('./codemirror-stub'),
    browserStub = require('./browser-stub'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertTrue = harness.assertTrue,

    MARK_CLASS = 'ete-directive',

    policy = harness.loadPureModule('email-editor/knowledge/decoration-policy.js'),

    TEMPLATE = 'Hello {{var customer.name}}, order {{var order.increment_id|escape}} is on its way.';

/**
 * Start a decorator on an editor holding this text
 *
 * @param {string} text
 * @return {Object} the editor, the decorator, and what the callback was handed
 */
function setUp(text) {
    var browser = browserStub.create(),
        editor = codeMirrorStub.create(text),
        activations = [],
        decorator = harness.loadStubbedModule(
            'email-editor/knowledge/directive-decorator.js',
            {
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/directive-scanner':
                    harness.loadPureModule('email-editor/knowledge/directive-scanner.js'),
                'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/decoration-policy': policy
            },
            browser.globals()
        ).create(editor, {
            /**
             * @param {Object} occurrence
             * @param {Object} anchor
             * @return {void}
             */
            onActivate: function (occurrence, anchor) {
                activations.push({occurrence: occurrence, anchor: anchor});
            }
        });

    return {
        editor: editor,
        decorator: decorator,
        clock: browser.clock,
        warnings: browser.console.warnings,

        /**
         * @return {Array} every directive that was announced, in order
         */
        activations: function () {
            return activations.slice();
        },

        /**
         * Click a marked directive at an offset
         *
         * @param {number} offset
         * @param {Object} [modifiers] what the press carried
         * @param {number} [releaseOffset] where the pointer was let go, if not where it landed
         * @return {void}
         */
        click: function (offset, modifiers, releaseOffset) {
            editor.pressAt(offset, modifiers);
            editor.releaseAt(
                typeof releaseOffset === 'number' ? releaseOffset : offset,
                editor.targetCarrying(MARK_CLASS)
            );
        }
    };
}

/**
 * Where a directive starts in the template
 *
 * @param {string} text
 * @param {string} directive
 * @return {number}
 */
function offsetOf(text, directive) {
    return text.indexOf(directive);
}

test('every directive in the document is bracketed exactly, and nothing else is', function () {
    var context = setUp(TEMPLATE),
        first = '{{var customer.name}}',
        second = '{{var order.increment_id|escape}}';

    assertLike(context.editor.liveMarks(MARK_CLASS), [
        {start: offsetOf(TEMPLATE, first), end: offsetOf(TEMPLATE, first) + first.length, className: MARK_CLASS},
        {start: offsetOf(TEMPLATE, second), end: offsetOf(TEMPLATE, second) + second.length, className: MARK_CLASS}
    ], 'the bytes of each directive and none of the prose around them');
});

test('a document with nothing in it to mark is left with no marks', function () {
    var context = setUp('Nothing here but a sentence.');

    assertLike(context.editor.liveMarks(), [], 'no marks');
});

test('a document past the size ceiling is left undecorated and said aloud, once', function () {
    var text = new Array(policy.SIZE_CEILING + 2).join('x') + '{{var order.increment_id}}',
        context = setUp(text);

    assertLike(context.editor.liveMarks(), [], 'nothing marked');
    assertSame(context.warnings().length, 1, 'and the reason is stated');
    assertTrue(
        context.warnings()[0].indexOf(String(policy.SIZE_CEILING)) !== -1,
        'the ceiling is named, so the number is not a mystery'
    );

    context.decorator.refresh();

    assertSame(context.warnings().length, 1, 'a redraw does not repeat it');
});

test('a document that comes back under the ceiling is marked again and can complain again', function () {
    var context = setUp(new Array(policy.SIZE_CEILING + 2).join('x'));

    context.editor.setValue('{{var order.increment_id}}');
    context.decorator.refresh();

    assertSame(context.editor.liveMarks(MARK_CLASS).length, 1, 'marked once it fits');
    assertSame(context.warnings().length, 1, 'and nothing new was said about it');
});

test('an edit redraws once the document has stopped changing, not while it is changing', function () {
    var context = setUp(TEMPLATE);

    context.editor.setValue('{{var one}} {{var two}} {{var three}}');

    assertSame(context.editor.liveMarks(MARK_CLASS).length, 2, 'still the marks of the old document');

    context.clock.tick(policy.RESCAN_DELAY - 1);
    assertSame(context.editor.liveMarks(MARK_CLASS).length, 2, 'and still, a moment before the pause is up');

    context.clock.tick(1);
    assertSame(context.editor.liveMarks(MARK_CLASS).length, 3, 'redrawn once the typing stopped');
});

test('a run of edits costs one redraw, not one each', function () {
    var context = setUp(TEMPLATE),
        index;

    for (index = 0; index < 5; index++) {
        context.editor.setValue('{{var one}} ' + new Array(index + 2).join('x'));
    }

    assertSame(context.clock.pending(), 1, 'one redraw is waiting, not five');

    context.clock.tick(policy.RESCAN_DELAY);

    assertSame(context.editor.liveMarks(MARK_CLASS).length, 1, 'and it drew the document that was left');
});

test('a document replaced wholesale is redrawn now rather than waiting for a pause', function () {
    var context = setUp(TEMPLATE);

    context.editor.setValue('{{var one}} {{var two}} {{var three}}');
    context.decorator.refresh();

    assertSame(context.editor.liveMarks(MARK_CLASS).length, 3, 'drawn immediately');
    assertSame(context.clock.pending(), 0, 'and the redraw that was waiting was dropped');
});

test('a plain click on a directive announces that directive and a mark that tracks it', function () {
    var context = setUp(TEMPLATE),
        marksBefore = context.editor.markCount(),
        announced;

    context.click(offsetOf(TEMPLATE, '{{var customer.name}}') + 4);
    announced = context.activations();

    assertSame(announced.length, 1, 'announced once');
    assertSame(announced[0].occurrence.reference, 'var:customer.name', 'the directive that was clicked');
    assertSame(context.editor.markCount(), marksBefore + 1, 'a mark of its own, beside the decoration');
    assertLike(
        announced[0].anchor.find(),
        {
            from: context.editor.posFromIndex(offsetOf(TEMPLATE, '{{var customer.name}}')),
            to: context.editor.posFromIndex(
                offsetOf(TEMPLATE, '{{var customer.name}}') + '{{var customer.name}}'.length
            )
        },
        'bracketing the directive that was clicked'
    );
});

test('the mark handed over outlives the redraw that clears every decoration mark', function () {
    var context = setUp(TEMPLATE),
        anchor;

    context.click(offsetOf(TEMPLATE, '{{var customer.name}}') + 4);
    anchor = context.activations()[0].anchor;

    context.decorator.refresh();

    assertTrue(anchor.find() !== null, 'still bracketing something after every decoration was rebuilt');
});

test('only one directive at a time is tracked', function () {
    var context = setUp(TEMPLATE),
        first;

    context.click(offsetOf(TEMPLATE, '{{var customer.name}}') + 4);
    first = context.activations()[0].anchor;

    context.click(offsetOf(TEMPLATE, '{{var order.increment_id|escape}}') + 4);

    assertSame(first.find(), null, 'the mark of the directive left behind was given up');
    assertTrue(context.activations()[1].anchor.find() !== null, 'and the new one is held');
});

test('the directive announced is read out of the document as it is now', function () {
    var context = setUp(TEMPLATE),
        // The same length, so the mark still brackets exactly one directive - just not the one it
        // was made around.
        edited = TEMPLATE.replace('{{var customer.name}}', '{{var customer.mail}}');

    // The edit happens inside the pause before the redraw, so the marks still describe the document
    // as it was. What is announced has to come from the document, not from the last scan.
    context.editor.setValue(edited);
    context.click(edited.indexOf('{{var customer.mail}}') + 4);

    assertSame(context.activations().length, 1, 'announced');
    assertSame(
        context.activations()[0].occurrence.reference,
        'var:customer.mail',
        'what is there now, not what was there when the mark was made'
    );
});

test('a click on a mark whose bytes are no longer one directive announces nothing', function () {
    var context = setUp(TEMPLATE),
        broken = TEMPLATE.replace('{{var customer.name}}', 'plain words, no directive at all');

    context.editor.setValue(broken);
    context.click(offsetOf(TEMPLATE, '{{var customer.name}}') + 4);

    assertLike(context.activations(), [], 'the mark is stale and a redraw is already on its way');
});

test('a press that landed outside the editor is not half of a click here', function () {
    var context = setUp(TEMPLATE),
        offset = offsetOf(TEMPLATE, '{{var customer.name}}') + 4;

    context.editor.releaseAt(offset, context.editor.targetCarrying(MARK_CLASS));

    assertLike(context.activations(), [], 'a release with no press before it is not a click');
});

test('a press whose gesture left the editor is forgotten rather than paired with a later release', function () {
    var context = setUp(TEMPLATE),
        offset = offsetOf(TEMPLATE, '{{var customer.name}}') + 4;

    context.editor.pressAt(offset);
    context.editor.leave();
    context.editor.releaseAt(offset, context.editor.targetCarrying(MARK_CLASS));

    assertLike(context.activations(), [], 'the two halves belong to different gestures');
});

test('a release anywhere but on a marked directive announces nothing', function () {
    var context = setUp(TEMPLATE),
        offset = offsetOf(TEMPLATE, '{{var customer.name}}') + 4;

    context.editor.pressAt(offset);
    context.editor.releaseAt(offset, context.editor.plainTarget());

    assertLike(
        context.activations(),
        [],
        'the nearest character to a press in the gutter is often inside a directive; the element is not'
    );
});

test('every gesture the editor already owns is left to the editor', function () {
    var offset = offsetOf(TEMPLATE, '{{var customer.name}}') + 4,
        gestures = [
            {name: 'a secondary button', modifiers: {button: 2}},
            {name: 'the platform modifier', modifiers: {metaKey: true}},
            {name: 'control', modifiers: {ctrlKey: true}},
            {name: 'a rectangular selection', modifiers: {altKey: true}},
            {name: 'extending a selection', modifiers: {shiftKey: true}}
        ];

    gestures.forEach(function (gesture) {
        var context = setUp(TEMPLATE);

        context.click(offset, gesture.modifiers);

        assertLike(context.activations(), [], gesture.name + ' is not an activation');
    });
});

test('a pointer that travelled selected text rather than clicking', function () {
    var offset = offsetOf(TEMPLATE, '{{var customer.name}}') + 4,
        near = setUp(TEMPLATE),
        far = setUp(TEMPLATE);

    near.click(offset, {}, offset + policy.DRAG_SLOP);
    assertSame(near.activations().length, 1, 'a hand never lands and lifts on the same pixel');

    far.click(offset, {}, offset + policy.DRAG_SLOP + 1);
    assertLike(far.activations(), [], 'past that it was a selection gesture');
});

test('a click while the editor holds a selection is left alone', function () {
    var context = setUp(TEMPLATE);

    context.editor.setSelected(true);
    context.click(offsetOf(TEMPLATE, '{{var customer.name}}') + 4);

    assertLike(context.activations(), [], 'the editor already owns what this gesture means');
});

test('tearing the decorator down leaves nothing on the editor and nothing waiting to run', function () {
    var context = setUp(TEMPLATE),
        anchor;

    context.click(offsetOf(TEMPLATE, '{{var customer.name}}') + 4);
    anchor = context.activations()[0].anchor;

    context.editor.setValue('{{var one}}');
    assertSame(context.clock.pending(), 1, 'a redraw was waiting');

    context.decorator.destroy();

    assertSame(context.clock.pending(), 0, 'and it was dropped');
    assertLike(context.editor.liveMarks(), [], 'every mark was given back, the tracked one included');
    assertSame(anchor.find(), null, 'including the one that was handed out');
    assertSame(context.editor.listenerCount('change'), 0, 'the editor is not being listened to any more');
});

test('nothing is announced or drawn after a teardown', function () {
    var context = setUp(TEMPLATE);

    context.decorator.destroy();
    context.click(offsetOf(TEMPLATE, '{{var customer.name}}') + 4);
    context.decorator.refresh();

    assertLike(context.activations(), [], 'a click reaches nobody');
    assertLike(context.editor.liveMarks(), [], 'and a redraw draws nothing');
});
