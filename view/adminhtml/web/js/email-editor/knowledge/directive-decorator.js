/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Makes every `{{...}}` in the code editor look clickable, and says when one was clicked.
 *
 * An adapter, not a component: it is handed a CodeMirror instance and one callback and owns nothing
 * else. Where a directive is and what its chain says is the scanner's answer; when to redraw, when
 * not to draw at all and whether a press was a click is the decoration policy's answer; this file
 * only carries those answers to and from an editor that neither of them is allowed to know about.
 *
 * Three things about it are load-bearing rather than incidental.
 *
 * The directives are marked with a class and never replaced with a widget. A widget would take the
 * text out of the document and put a rendering of it in the document's place, and an admin could
 * then no longer type inside their own directive - the opposite of what marking it is for. A class
 * leaves every byte where it was and adds nothing but a look.
 *
 * What is handed to the callback alongside the directive is a mark of its own, made at the moment
 * of the click and outliving every redraw. The offsets on a directive are absolute, and they stop
 * describing the document as soon as anything is typed anywhere before them - including a write
 * made through this same activation, since the redraw that would have refreshed them waits for a
 * pause first. A mark is moved by the editor as the document is edited, so asking the mark where
 * the directive is now always answers about the document that exists. The decoration marks
 * themselves cannot serve: they are torn down and rebuilt on every redraw, so one kept from before
 * a keystroke would report the directive as deleted the moment anyone typed anywhere.
 *
 * And every redraw is torn down. A timer that survives teardown wakes up against an editor that is
 * no longer on the page, and marks left behind on an editor being reused are drawn twice.
 */
define([
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/directive-scanner',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/decoration-policy'
], function (scanner, policy) {
    'use strict';

    /**
     * The class every marked directive carries
     *
     * It is both the look and the hit test: a press is only an activation when it landed on an
     * element carrying this, which is what keeps a press in the line-number gutter or in the empty
     * space past the end of a line from opening anything. The nearest character to such a press can
     * easily be inside a directive, so an offset alone would answer yes to both.
     *
     * @type {string}
     */
    var MARK_CLASS = 'ete-directive';

    /**
     * Start marking the directives in an editor
     *
     * @param {Object} editor a CodeMirror instance
     * @param {{onActivate: function(Object, Object)}} [options] called with the directive that was
     *        clicked and the mark that will keep tracking it while the document is edited
     * @return {{refresh: function(), destroy: function()}}
     */
    function create(editor, options) {
        var settings = options || {},
            onActivate = typeof settings.onActivate === 'function' ? settings.onActivate : null,
            wrapper = editor.getWrapperElement(),
            marks = [],
            anchor = null,
            timer = null,
            press = null,
            skipping = false,
            destroyed = false;

        /**
         * Remove every decoration mark
         *
         * @return {void}
         */
        function clearMarks() {
            var index;

            for (index = 0; index < marks.length; index++) {
                marks[index].clear();
            }

            marks = [];
        }

        /**
         * Read the document and mark every directive in it
         *
         * @return {void}
         */
        function decorate() {
            var text,
                occurrences,
                occurrence,
                index;

            if (destroyed) {
                return;
            }

            clearMarks();
            text = editor.getValue();

            if (!policy.shouldDecorate(text.length)) {
                if (!skipping && typeof console !== 'undefined' && console.warn) {
                    console.warn(
                        'Hryvinskyi_EmailTemplateEditor: directives are not being marked in this ' +
                        'template because it is ' + text.length + ' characters long, past the ' +
                        policy.SIZE_CEILING + ' character ceiling for marking them.'
                    );
                }

                skipping = true;

                return;
            }

            skipping = false;
            occurrences = scanner.scan(text);

            for (index = 0; index < occurrences.length; index++) {
                occurrence = occurrences[index];
                marks.push(editor.markText(
                    editor.posFromIndex(occurrence.start),
                    editor.posFromIndex(occurrence.end),
                    {className: MARK_CLASS}
                ));
            }
        }

        /**
         * Forget any redraw that has not happened yet
         *
         * @return {void}
         */
        function cancelPending() {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
        }

        /**
         * Redraw once the document has stopped changing
         *
         * @return {void}
         */
        function scheduleDecorate() {
            if (destroyed) {
                return;
            }

            cancelPending();

            timer = setTimeout(function () {
                timer = null;
                decorate();
            }, policy.RESCAN_DELAY);
        }

        /**
         * Redraw now, instead of waiting for the document to settle
         *
         * For a document that was replaced wholesale rather than typed into: there is no pause to
         * wait for, and waiting for one shows the new content undecorated in the meantime.
         *
         * @return {void}
         */
        function refresh() {
            cancelPending();
            decorate();
        }

        /**
         * Where each marked directive sits in the document right now
         *
         * The marks are asked rather than the last scan's offsets, because the document may have
         * been edited since that scan and will have been if this is a click inside the pause the
         * redraw waits out. A mark whose text has been deleted reports nothing and stays in the
         * list as a hole, so that a position in this list is a position in the list of marks.
         *
         * @return {Array.<{start: number, end: number}|null>}
         */
        function liveSpans() {
            var spans = [],
                range,
                index;

            for (index = 0; index < marks.length; index++) {
                range = marks[index].find();
                spans.push(range ? {
                    start: editor.indexFromPos(range.from),
                    end: editor.indexFromPos(range.to)
                } : null);
            }

            return spans;
        }

        /**
         * Did the press land on a marked directive?
         *
         * @param {Node} node the element the press was reported on
         * @return {boolean}
         */
        function isMarkedTarget(node) {
            var element = node;

            while (element && element !== wrapper) {
                if (element.classList && element.classList.contains(MARK_CLASS)) {
                    return true;
                }

                element = element.parentNode;
            }

            return false;
        }

        /**
         * Announce the directive at this offset
         *
         * The directive announced is read out of the document as it is now rather than taken from
         * the last scan, so an edit made during the pause before a redraw cannot hand a caller a
         * directive that has already moved on. A range that no longer holds exactly one directive
         * announces nothing at all: the mark is stale and a redraw is already on its way.
         *
         * @param {number} offset an absolute offset into the document
         * @return {void}
         */
        function activate(offset) {
            var spans = liveSpans(),
                position = policy.spanIndexAt(spans, offset),
                span,
                found;

            if (position === -1) {
                return;
            }

            span = spans[position];
            found = scanner.scanRange(editor.getValue(), span.start, span.end);

            if (found.length !== 1) {
                return;
            }

            if (anchor) {
                anchor.clear();
            }

            anchor = editor.markText(
                editor.posFromIndex(span.start),
                editor.posFromIndex(span.end),
                {}
            );

            onActivate(found[0], anchor);
        }

        /**
         * Remember a press so the release can be judged against it
         *
         * @param {MouseEvent} event
         * @return {void}
         */
        function onMouseDown(event) {
            press = {
                button: event.button,
                ctrlKey: event.ctrlKey,
                metaKey: event.metaKey,
                altKey: event.altKey,
                shiftKey: event.shiftKey,
                x: event.clientX,
                y: event.clientY
            };
        }

        /**
         * Forget a press whose gesture left the editor
         *
         * A press is only half of a click, and the other half is only ever reported here while the
         * pointer is still inside. A press let go somewhere else would otherwise sit and wait to be
         * paired with an unrelated release later on.
         *
         * @return {void}
         */
        function onMouseLeave() {
            press = null;
        }

        /**
         * Open on a plain click on a marked directive, and leave every other gesture alone
         *
         * Nothing is prevented and nothing is stopped here: the editor goes on placing the caret
         * where the admin clicked, so clicking a directive to type inside it works exactly as it
         * did before it became clickable. The release is watched rather than the click, because a
         * release is reported whatever the editor did with the press that preceded it.
         *
         * @param {MouseEvent} event
         * @return {void}
         */
        function onMouseUp(event) {
            var recorded = press;

            press = null;

            if (destroyed || !onActivate || !isMarkedTarget(event.target)) {
                return;
            }

            if (!policy.isActivatingClick(
                recorded,
                {x: event.clientX, y: event.clientY},
                editor.somethingSelected()
            )) {
                return;
            }

            activate(editor.indexFromPos(
                editor.coordsChar({left: event.clientX, top: event.clientY}, 'window')
            ));
        }

        /**
         * Stop marking, and leave nothing behind that could still run
         *
         * @return {void}
         */
        function destroy() {
            destroyed = true;
            cancelPending();
            editor.off('change', scheduleDecorate);
            wrapper.removeEventListener('mousedown', onMouseDown);
            wrapper.removeEventListener('mouseup', onMouseUp);
            wrapper.removeEventListener('mouseleave', onMouseLeave);
            clearMarks();

            if (anchor) {
                anchor.clear();
                anchor = null;
            }

            press = null;
        }

        editor.on('change', scheduleDecorate);
        wrapper.addEventListener('mousedown', onMouseDown);
        wrapper.addEventListener('mouseup', onMouseUp);
        wrapper.addEventListener('mouseleave', onMouseLeave);
        decorate();

        return {
            refresh: refresh,
            destroy: destroy
        };
    }

    return {
        create: create
    };
});
