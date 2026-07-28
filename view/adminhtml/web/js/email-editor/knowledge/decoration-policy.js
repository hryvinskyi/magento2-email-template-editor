/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The rules the directive decoration obeys, and the numbers behind them.
 *
 * Single charter: decide, from plain data alone, when to redraw the marks, whether to draw them at
 * all, which mark a pointer is over and whether a press and a release together were a click rather
 * than a gesture the editor already owns. Nothing here touches the DOM or CodeMirror; the decorator
 * reads the pointer and the document and asks these questions, and every one of them can therefore
 * be answered - and proved - without a browser.
 *
 * These are judgements rather than arithmetic, and that is exactly why they are here: a judgement
 * left inline in an adapter is a judgement nobody can check.
 */
define([], function () {
    'use strict';

    var /**
         * How long the document must sit still before the marks are redrawn, in milliseconds
         *
         * Redrawing means reading the whole document and marking every directive in it again, so
         * doing it per keystroke would put the cost of the whole document into the gap between two
         * characters. Waiting for a pause instead spends it once, when nobody is typing.
         *
         * The wait is also why nothing may hold on to an offset across it: for this long after an
         * edit, every offset the decorator last reported describes a document that no longer
         * exists.
         *
         * @type {number}
         */
        RESCAN_DELAY = 150,

        /**
         * The document size above which no directive is marked at all, in characters
         *
         * Redrawing is linear in the length of the document, so there is a size at which a pause
         * in typing becomes a visible stall. Past that size the decoration is dropped outright and
         * said aloud, rather than left to make the editor feel broken for a reason nobody can see.
         *
         * Characters, not bytes: this is a guard on how much work a redraw is, and the work is per
         * character. Nothing about correctness turns on where exactly the line falls.
         *
         * An email template on this install is a few kilobytes, so this ceiling is not expected to
         * be met. It exists so that meeting it is survivable.
         *
         * @type {number}
         */
        SIZE_CEILING = 204800,

        /**
         * How far a pointer may travel between press and release and still be a click, in pixels
         *
         * A pointer never lands and lifts on exactly the same pixel, and a hand that meant to
         * select text moves much further than a hand that meant to click. Anything past this is
         * read as a selection gesture.
         *
         * @type {number}
         */
        DRAG_SLOP = 3,

        /**
         * The mouse button an activation may come from
         *
         * @type {number}
         */
        PRIMARY_BUTTON = 0;

    /**
     * Is this document small enough to mark every directive in?
     *
     * @param {number} length the document's length in characters
     * @return {boolean}
     */
    function shouldDecorate(length) {
        return typeof length === 'number' && length >= 0 && length <= SIZE_CEILING;
    }

    /**
     * Which of these spans holds this offset?
     *
     * A span runs from its start up to but not including its end, which is what makes the position
     * just past a directive belong to the text after it rather than to the directive. A span may be
     * missing, because the thing tracking it may have been removed from the document since the list
     * was built; a missing span holds nothing.
     *
     * @param {Array.<{start: number, end: number}|null>} spans in document order
     * @param {number} offset an absolute offset into the document
     * @return {number} the position of the span in the list, or -1 when no span holds the offset
     */
    function spanIndexAt(spans, offset) {
        var index;

        if (Object.prototype.toString.call(spans) !== '[object Array]' || typeof offset !== 'number') {
            return -1;
        }

        for (index = 0; index < spans.length; index++) {
            if (spans[index] && offset >= spans[index].start && offset < spans[index].end) {
                return index;
            }
        }

        return -1;
    }

    /**
     * Did this press and release together mean "tell me about this directive"?
     *
     * Everything refused here is refused because the editor already owns it. Holding shift extends
     * a selection, holding alt starts a rectangular one, the platform's own modifier means "open
     * this elsewhere", a second button opens a context menu, and a pointer that travelled selected
     * text. Answering on top of any of those would take a gesture away from the admin rather than
     * adding one, and the directive has to stay ordinarily editable by clicking into it.
     *
     * A release with nothing recorded before it is refused as well: the press happened somewhere
     * this decorator was not watching, so the two are not one gesture.
     *
     * @param {{button: number, ctrlKey: boolean, metaKey: boolean, altKey: boolean,
     *          shiftKey: boolean, x: number, y: number}|null} press what was recorded on the press
     * @param {{x: number, y: number}} release where the pointer was let go
     * @param {boolean} hasSelection whether the editor holds a selection now
     * @return {boolean}
     */
    function isActivatingClick(press, release, hasSelection) {
        if (!press || !release || hasSelection === true) {
            return false;
        }

        if (press.button !== PRIMARY_BUTTON) {
            return false;
        }

        if (press.ctrlKey || press.metaKey || press.altKey || press.shiftKey) {
            return false;
        }

        return Math.abs(release.x - press.x) <= DRAG_SLOP &&
            Math.abs(release.y - press.y) <= DRAG_SLOP;
    }

    return {
        RESCAN_DELAY: RESCAN_DELAY,
        SIZE_CEILING: SIZE_CEILING,
        DRAG_SLOP: DRAG_SLOP,
        shouldDecorate: shouldDecorate,
        spanIndexAt: spanIndexAt,
        isActivatingClick: isActivatingClick
    };
});
