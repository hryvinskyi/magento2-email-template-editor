/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The `|…` suffix of a directive, as an ordered list of modifier calls.
 *
 * Single charter: turn the raw bytes of a chain into calls and back again, and produce new
 * chains from old ones. Nothing here knows which modifier names exist, which are implemented
 * by the renderer, or what any of them should be called on screen — the modifier registry
 * answers the first two and the inspector says the third. This module holds no wording.
 *
 * The grammar is the renderer's, taken from how an email template is actually filtered:
 * the chain is split on `|` into parts and each part is split on `:` into a name followed by
 * its arguments. Empty parts are skipped, because the renderer skips them too.
 *
 * Every operation returns a new array and leaves its input untouched, arguments included.
 * The chain lives inside an editable document and is read by more than one caller; a call
 * that mutated its argument would change what another caller already believed it held.
 */
define([], function () {
    'use strict';

    /**
     * Copy one call so a caller can never reach the arguments of the array it passed in
     *
     * @param {{name: string, args: string[]}} call
     * @return {{name: string, args: string[]}}
     */
    function copyCall(call) {
        return {
            name: call && typeof call.name === 'string' ? call.name : '',
            // Only strings survive. A chain is written into the template as text, so an argument of
            // any other type has no spelling to be written as: it would serialise to whatever its
            // toString happens to produce and turn the whole chain into something that cannot be
            // read back, which downstream shows up as a directive that refuses to be edited at all
            // rather than as the mistake it is.
            args: call && Object.prototype.toString.call(call.args) === '[object Array]'
                ? call.args.filter(function (argument) {
                    return typeof argument === 'string';
                })
                : []
        };
    }

    /**
     * Copy a whole list of calls
     *
     * @param {Array.<{name: string, args: string[]}>} calls
     * @return {Array.<{name: string, args: string[]}>}
     */
    function copyCalls(calls) {
        var copied = [],
            index;

        if (Object.prototype.toString.call(calls) !== '[object Array]') {
            return copied;
        }

        for (index = 0; index < calls.length; index++) {
            copied.push(copyCall(calls[index]));
        }

        return copied;
    }

    /**
     * Split the raw bytes of a chain into modifier calls
     *
     * Tolerant by design: a leading `|` is optional, an empty string yields an empty list, and
     * no name or argument is judged. Deciding whether the result is safe to write back over the
     * document is the scanner's job, not this one's.
     *
     * @param {string} chainText raw chain bytes, with or without the leading `|`
     * @return {Array.<{name: string, args: string[]}>}
     */
    function parse(chainText) {
        var text = typeof chainText === 'string' ? chainText : '',
            body = text.charAt(0) === '|' ? text.slice(1) : text,
            parts,
            segments,
            calls = [],
            index;

        if (body === '') {
            return calls;
        }

        parts = body.split('|');

        for (index = 0; index < parts.length; index++) {
            if (parts[index] === '') {
                continue;
            }

            segments = parts[index].split(':');
            calls.push({
                name: segments[0],
                args: segments.slice(1)
            });
        }

        return calls;
    }

    /**
     * Render modifier calls back into the bytes a template carries
     *
     * @param {Array.<{name: string, args: string[]}>} calls
     * @return {string} `''` for an empty list, otherwise a chain with its leading `|`
     */
    function serialise(calls) {
        var list = copyCalls(calls),
            text = '',
            index;

        for (index = 0; index < list.length; index++) {
            text += '|' + list[index].name;

            if (list[index].args.length > 0) {
                text += ':' + list[index].args.join(':');
            }
        }

        return text;
    }

    /**
     * Add a modifier, or replace the arguments of the one already there
     *
     * A name appears at most once in a chain: the renderer would apply a repeated name twice,
     * which is never what an admin choosing "escape as URL" after "escape as HTML" means.
     *
     * @param {Array.<{name: string, args: string[]}>} calls
     * @param {string} name
     * @param {string[]} [args]
     * @return {Array.<{name: string, args: string[]}>} a new list
     */
    function withModifier(calls, name, args) {
        var list = copyCalls(calls),
            replacement = copyCall({name: name, args: args}),
            index;

        for (index = 0; index < list.length; index++) {
            if (list[index].name === replacement.name) {
                list[index] = replacement;

                return list;
            }
        }

        list.push(replacement);

        return list;
    }

    /**
     * Drop every call carrying the given name
     *
     * @param {Array.<{name: string, args: string[]}>} calls
     * @param {string} name
     * @return {Array.<{name: string, args: string[]}>} a new list
     */
    function withoutModifier(calls, name) {
        var list = copyCalls(calls),
            kept = [],
            index;

        for (index = 0; index < list.length; index++) {
            if (list[index].name !== name) {
                kept.push(list[index]);
            }
        }

        return kept;
    }

    /**
     * Move one call to another position
     *
     * Order matters to the renderer, which applies the calls left to right, so this is a real
     * edit and not a presentation concern. An index outside the list leaves the order alone.
     *
     * @param {Array.<{name: string, args: string[]}>} calls
     * @param {number} from
     * @param {number} to
     * @return {Array.<{name: string, args: string[]}>} a new list
     */
    function reorder(calls, from, to) {
        var list = copyCalls(calls),
            moved;

        if (from < 0 || to < 0 || from >= list.length || to >= list.length || from === to) {
            return list;
        }

        moved = list.splice(from, 1)[0];
        list.splice(to, 0, moved);

        return list;
    }

    return {
        parse: parse,
        serialise: serialise,
        withModifier: withModifier,
        withoutModifier: withoutModifier,
        reorder: reorder
    };
});
