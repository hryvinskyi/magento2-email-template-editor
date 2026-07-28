/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * A code editor with a document, marks and no rendering.
 *
 * The decorator is written against ten calls of CodeMirror's and nothing else, so those ten are
 * what is here: the document text, the two-way conversion between an absolute offset and a
 * line/character position, making a mark and asking it where it is now, the wrapper element the
 * pointer listeners hang on, whether anything is selected, and the position under a pointer. Each
 * one behaves the way the real editor's does; none of them draws anything.
 *
 * Two differences are deliberate and both make a test possible rather than convenient.
 *
 * A mark here never moves. In the real editor a mark is carried along by the text it brackets,
 * which is the whole reason the decorator holds marks rather than offsets - but a mark that
 * followed the text would make the interesting case unreachable, because the interesting case is a
 * mark that has been left behind by an edit and a redraw that has not caught up yet. So an edit
 * leaves the marks where they were, which is exactly the state the decorator has to survive: it
 * re-reads the document at the mark and refuses when what is there is no longer one directive.
 *
 * A pointer's horizontal position is an offset into the document, one character to the pixel. It
 * means a test says where a click landed in the same units the assertions are written in, and it
 * costs nothing, because nothing here has a font.
 *
 * What this cannot say anything about: whether the real editor reports these events in this order,
 * what a mark's class does to the rendered line, and whether a directive ends up looking clickable.
 */
var browserStub = require('./browser-stub');

/**
 * Make an editor holding this text
 *
 * @param {string} text
 * @return {Object}
 */
function create(text) {
    var value = text,
        wrapper = browserStub.createElement('CodeMirror'),
        handlers = {},
        marks = [],
        selected = false;

    /**
     * Where an offset sits, as a line and a character within it
     *
     * @param {number} index
     * @return {{line: number, ch: number}}
     */
    function posFromIndex(index) {
        var bounded = Math.max(0, Math.min(value.length, index)),
            before = value.slice(0, bounded),
            lines = before.split('\n');

        return {line: lines.length - 1, ch: lines[lines.length - 1].length};
    }

    /**
     * What offset a line and character is at
     *
     * @param {{line: number, ch: number}} position
     * @return {number}
     */
    function indexFromPos(position) {
        var lines = value.split('\n'),
            line = Math.max(0, Math.min(lines.length - 1, position.line)),
            offset = 0,
            index;

        for (index = 0; index < line; index++) {
            offset += lines[index].length + 1;
        }

        return offset + Math.max(0, Math.min(lines[line].length, position.ch));
    }

    /**
     * @param {string} type
     * @param {Object} [event]
     * @return {void}
     */
    function fire(type, event) {
        (handlers[type] || []).slice().forEach(function (handler) {
            handler(event);
        });
    }

    return {
        /**
         * @return {string}
         */
        getValue: function () {
            return value;
        },

        /**
         * @return {Object} the element the pointer listeners hang on
         */
        getWrapperElement: function () {
            return wrapper;
        },

        posFromIndex: posFromIndex,
        indexFromPos: indexFromPos,

        /**
         * Bracket a stretch of the document
         *
         * @param {{line: number, ch: number}} from
         * @param {{line: number, ch: number}} to
         * @param {Object} [options]
         * @return {{find: Function, clear: Function}}
         */
        markText: function (from, to, options) {
            var mark = {
                start: indexFromPos(from),
                end: indexFromPos(to),
                className: options && options.className ? options.className : '',
                cleared: false
            };

            marks.push(mark);

            return {
                /**
                 * @return {{from: Object, to: Object}|null}
                 */
                find: function () {
                    return mark.cleared
                        ? null
                        : {from: posFromIndex(mark.start), to: posFromIndex(mark.end)};
                },

                /**
                 * @return {void}
                 */
                clear: function () {
                    mark.cleared = true;
                }
            };
        },

        /**
         * @param {string} type
         * @param {Function} handler
         * @return {void}
         */
        on: function (type, handler) {
            handlers[type] = handlers[type] || [];
            handlers[type].push(handler);
        },

        /**
         * @param {string} type
         * @param {Function} handler
         * @return {void}
         */
        off: function (type, handler) {
            var registered = handlers[type] || [],
                index = registered.indexOf(handler);

            if (index !== -1) {
                registered.splice(index, 1);
            }
        },

        /**
         * @return {boolean}
         */
        somethingSelected: function () {
            return selected;
        },

        /**
         * Where the pointer is, reading its horizontal position as an offset
         *
         * @param {{left: number, top: number}} coordinates
         * @return {{line: number, ch: number}}
         */
        coordsChar: function (coordinates) {
            return posFromIndex(coordinates.left);
        },

        /**
         * Replace the document, as an edit from outside would
         *
         * @param {string} next
         * @return {void}
         */
        setValue: function (next) {
            value = next;
            fire('change', {});
        },

        /**
         * Say that the editor holds a selection
         *
         * @param {boolean} holds
         * @return {void}
         */
        setSelected: function (holds) {
            selected = holds === true;
        },

        /**
         * The marks that are still on the document, in the order they were made
         *
         * @param {string} [className] only the marks carrying this class
         * @return {Array.<{start: number, end: number, className: string}>}
         */
        liveMarks: function (className) {
            return marks.filter(function (mark) {
                return !mark.cleared &&
                    (typeof className !== 'string' || mark.className === className);
            }).map(function (mark) {
                return {start: mark.start, end: mark.end, className: mark.className};
            });
        },

        /**
         * How many marks have ever been made, cleared or not
         *
         * @return {number}
         */
        markCount: function () {
            return marks.length;
        },

        /**
         * How many listeners the editor holds for a kind of event
         *
         * @param {string} type
         * @return {number}
         */
        listenerCount: function (type) {
            return (handlers[type] || []).length;
        },

        /**
         * An element standing for a rendered directive: it carries the class and sits in the editor
         *
         * @param {string} className
         * @return {Object}
         */
        targetCarrying: function (className) {
            return browserStub.createElement(className).appendTo(wrapper);
        },

        /**
         * An element standing for anything else inside the editor - a gutter, blank space past a line
         *
         * @return {Object}
         */
        plainTarget: function () {
            return browserStub.createElement('CodeMirror-linenumber').appendTo(wrapper);
        },

        /**
         * Press the pointer at an offset in the document
         *
         * @param {number} offset
         * @param {Object} [modifiers] button and modifier keys, as a mouse event carries them
         * @return {void}
         */
        pressAt: function (offset, modifiers) {
            wrapper.dispatch('mousedown', Object.assign({
                button: 0,
                ctrlKey: false,
                metaKey: false,
                altKey: false,
                shiftKey: false,
                clientX: offset,
                clientY: 0
            }, modifiers || {}));
        },

        /**
         * Let the pointer go at an offset, on a given element
         *
         * @param {number} offset
         * @param {Object} target the element the release is reported on
         * @return {void}
         */
        releaseAt: function (offset, target) {
            wrapper.dispatch('mouseup', {clientX: offset, clientY: 0, target: target});
        },

        /**
         * Take the pointer out of the editor
         *
         * @return {void}
         */
        leave: function () {
            wrapper.dispatch('mouseleave', {});
        }
    };
}

module.exports = {
    create: create
};
