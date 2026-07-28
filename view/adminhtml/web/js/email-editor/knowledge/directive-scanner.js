/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Finding the `{{...}}` directives in a template and saying where each one is.
 *
 * Single charter: text in, occurrences out. Nothing here touches the DOM, CodeMirror or the
 * network, and nothing here holds wording - an occurrence is a location plus a lookup key, and
 * everything an admin reads about that key comes from the server.
 *
 * The boundaries reported here are not a design choice. They are a copy of how an email template
 * is really filtered, and copying it exactly is the whole point: the span reported here is the
 * span a formatting change writes over, so a scanner that is "more correct" than the renderer
 * writes into the wrong bytes and corrupts the template.
 *
 * What the renderer does, and therefore what this module does:
 *
 * - An email template's filter is configured with four directive processors - depend, if,
 *   template and legacy. Every other directive kind therefore reaches the legacy processor,
 *   whose expression is `/{{([a-z]{0,10})(.*?)}}(?:(.*?)(?:{{\/(?:\1)}}))?/si`.
 * - `(.*?)}}` is lazy and blind to quoting, so a directive ends at the FIRST `}}`, even in the
 *   middle of a quoted parameter. `{{trans "a }} b"}}` is, to the renderer, the directive
 *   `{{trans "a }}` followed by the literal text ` b"}}`. Skipping a `}}` inside quotes would put
 *   the modifier span past the directive's real end, and a formatting change would then be
 *   written inside the quoted string.
 * - The directive name is matched case-insensitively and is at most ten characters long. A name
 *   outside the published set, or no name at all, is not an occurrence - which is why a closing
 *   tag such as `{{/depend}}` and a branch marker such as `{{else}}` are skipped.
 * - The modifier chain is everything after the FIRST `|` up to the closing `}}`, whatever
 *   characters it contains, because the legacy path splits a directive body with a two-part
 *   explode on `|` and has no capture group for the chain at all. `{{var x|escape:HTML}}` has a
 *   chain, even though the newer directive path's character class would not have matched it.
 *
 * Enumeration is deliberately per-directive rather than per-construction: the legacy expression
 * also swallows a directive's body and closing tag, but a body is filtered recursively, so the
 * directives nested inside a `{{depend}}` or an `{{if}}` do render and an admin must be able to
 * reach them. Where a directive begins and ends is the renderer's rule; which directives are
 * listed is ours.
 *
 * The lookup key is the server's rule, and this module mirrors it rather than inventing one: the
 * kind folds case-insensitively onto one published spelling, one matched pair of surrounding
 * quotes is removed, whitespace is removed or collapsed per kind, and an expression over 255
 * UTF-8 bytes is cut on a character boundary and flagged rather than dropped. A reference that
 * did not come back unchanged from the server's own normalisation would be answered as "not
 * documented" with nothing to explain why.
 *
 * Two further answers are given here rather than in whatever adapter needs them, because both are
 * questions about text and neither needs a browser to answer: whether a range of the document
 * still holds the directive a caller believes it holds, and whether a chain a caller has built may
 * be written into a template at all. Both guard the one place this feature writes over template
 * bytes, so both are worth being able to prove without a browser in the room.
 */
define([], function () {
    'use strict';

    var /**
         * Leave whitespace inside the expression alone
         *
         * @type {null}
         */
        WHITESPACE_KEEP = null,

        /**
         * Remove every whitespace character inside the expression
         *
         * @type {string}
         */
        WHITESPACE_REMOVE = '',

        /**
         * Collapse each run of whitespace inside the expression to a single space
         *
         * @type {string}
         */
        WHITESPACE_COLLAPSE = ' ',

        /**
         * The whole directive body is the expression
         *
         * @type {string}
         */
        READ_BODY = 'body',

        /**
         * The quoted message is the expression
         *
         * @type {string}
         */
        READ_MESSAGE = 'message',

        /**
         * A named parameter carries the expression
         *
         * @type {string}
         */
        READ_PARAMETER = 'parameter',

        /**
         * The kind carries no identity of its own
         *
         * @type {string}
         */
        READ_NOTHING = 'nothing',

        /**
         * The published directive kinds, keyed by their lower-case spelling
         *
         * The renderer reaches a directive through a reflected method call and PHP method names
         * are case-insensitive, so `{{CustomVar}}` and `{{customVar}}` render identically and must
         * describe the same thing. Folding here onto the one spelling the server publishes is what
         * makes that true of the lookup key as well.
         *
         * `read` says where in the document the raw expression sits; `unquote` and `whitespace`
         * mirror the normalisation the server applies to whatever arrives, so what is sent is
         * already what the server would make of it.
         *
         * @type {Object.<string, {canonical: string, read: string, parameter: string,
         *                         fallback: string, unquote: boolean, whitespace: ?string}>}
         */
        KINDS = {
            'var': {
                canonical: 'var',
                read: READ_BODY,
                parameter: '',
                fallback: '',
                unquote: false,
                whitespace: WHITESPACE_REMOVE
            },
            'config': {
                canonical: 'config',
                read: READ_PARAMETER,
                parameter: 'path',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_KEEP
            },
            'customvar': {
                canonical: 'customVar',
                read: READ_PARAMETER,
                parameter: 'code',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_KEEP
            },
            'trans': {
                canonical: 'trans',
                read: READ_MESSAGE,
                parameter: '',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_COLLAPSE
            },
            'block': {
                canonical: 'block',
                read: READ_PARAMETER,
                parameter: 'class',
                fallback: 'id',
                unquote: true,
                whitespace: WHITESPACE_COLLAPSE
            },
            'layout': {
                canonical: 'layout',
                read: READ_PARAMETER,
                parameter: 'handle',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_COLLAPSE
            },
            'media': {
                canonical: 'media',
                read: READ_PARAMETER,
                parameter: 'url',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_COLLAPSE
            },
            'store': {
                canonical: 'store',
                read: READ_PARAMETER,
                parameter: 'url',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_COLLAPSE
            },
            'css': {
                canonical: 'css',
                read: READ_PARAMETER,
                parameter: 'file',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_COLLAPSE
            },
            'template': {
                canonical: 'template',
                read: READ_PARAMETER,
                parameter: 'config_path',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_COLLAPSE
            },
            'view': {
                canonical: 'view',
                read: READ_PARAMETER,
                parameter: 'url',
                fallback: '',
                unquote: true,
                whitespace: WHITESPACE_COLLAPSE
            },
            'protocol': {
                canonical: 'protocol',
                read: READ_NOTHING,
                parameter: '',
                fallback: '',
                unquote: false,
                whitespace: WHITESPACE_KEEP
            },
            'inlinecss': {
                canonical: 'inlinecss',
                read: READ_NOTHING,
                parameter: '',
                fallback: '',
                unquote: false,
                whitespace: WHITESPACE_KEEP
            },
            'depend': {
                canonical: 'depend',
                read: READ_NOTHING,
                parameter: '',
                fallback: '',
                unquote: false,
                whitespace: WHITESPACE_KEEP
            },
            'if': {
                canonical: 'if',
                read: READ_NOTHING,
                parameter: '',
                fallback: '',
                unquote: false,
                whitespace: WHITESPACE_KEEP
            },
            'for': {
                canonical: 'for',
                read: READ_NOTHING,
                parameter: '',
                fallback: '',
                unquote: false,
                whitespace: WHITESPACE_KEEP
            }
        },

        /**
         * The renderer stops reading a directive name after ten characters
         *
         * @type {number}
         */
        DIRECTIVE_NAME_LIMIT = 10,

        /**
         * An expression longer than this many UTF-8 bytes is truncated, never dropped
         *
         * Dropping it would make every long translated message silently unclickable with no
         * explanation anywhere, and long translated messages are the common case.
         *
         * @type {number}
         */
        EXPRESSION_BYTE_LIMIT = 255,

        /**
         * Characters an expression may never contain
         *
         * Braces would let a reference be mistaken for a directive, and line breaks and NUL bytes
         * break every place a reference is carried as a single line of text. The server refuses a
         * key carrying any of them, so a directive whose key would carry one is not reported here
         * either - a directive the server would refuse must never be shown as answerable.
         *
         * @type {RegExp}
         */
        FORBIDDEN_IN_EXPRESSION = /[{}\r\n\x00]/,

        /**
         * Whitespace the renderer's own tokenizers skip
         *
         * This is the set the renderer trims by, which includes a NUL byte and a vertical tab but
         * not a form feed. It decides where a parameter value ends and where a modifier chain
         * would have to be inserted, so it follows the renderer rather than a tidier definition.
         *
         * @type {RegExp}
         */
        TOKENIZER_WHITESPACE = /[ \t\n\r\x00\x0B]/,

        /**
         * The same set, anchored, for trimming an expression exactly as the server trims it
         *
         * @type {RegExp}
         */
        TRIM_LEADING = /^[ \t\n\r\x00\x0B]+/,

        /**
         * The same set, anchored to the end
         *
         * @type {RegExp}
         */
        TRIM_TRAILING = /[ \t\n\r\x00\x0B]+$/,

        /**
         * Whitespace as the server's normalising expression sees it
         *
         * A slightly different set from the one the tokenizers use: it includes a form feed and
         * excludes a NUL byte. Both sets are carried rather than merged, because merging them
         * would make one of the two behaviours wrong.
         *
         * @type {RegExp}
         */
        NORMALISER_WHITESPACE = /[ \t\n\r\f\v]/,

        /**
         * Runs of that whitespace, for removing or collapsing it
         *
         * @type {RegExp}
         */
        NORMALISER_WHITESPACE_RUN = /[ \t\n\r\f\v]+/g,

        /**
         * The shape a modifier name must have before the chain may be rewritten
         *
         * @type {RegExp}
         */
        MODIFIER_NAME = /^[A-Za-z][A-Za-z0-9_-]*$/,

        /**
         * The shape a modifier argument must have before the chain may be rewritten
         *
         * @type {RegExp}
         */
        MODIFIER_ARGUMENT = /^[A-Za-z0-9_.-]*$/;

    /**
     * Is this character whitespace to the renderer's tokenizers?
     *
     * @param {string} character
     * @return {boolean}
     */
    function isTokenizerWhitespace(character) {
        return character !== '' && TOKENIZER_WHITESPACE.test(character);
    }

    /**
     * Is this character one of the ASCII letters a directive name is built from?
     *
     * @param {string} character
     * @return {boolean}
     */
    function isLetter(character) {
        return (character >= 'a' && character <= 'z') || (character >= 'A' && character <= 'Z');
    }

    /**
     * Trim an expression the way the server trims it
     *
     * @param {string} value
     * @return {string}
     */
    function trimBothEnds(value) {
        return value.replace(TRIM_LEADING, '').replace(TRIM_TRAILING, '');
    }

    /**
     * Trim only the end of an expression, as the server does after cutting an over-long one
     *
     * @param {string} value
     * @return {string}
     */
    function trimEnd(value) {
        return value.replace(TRIM_TRAILING, '');
    }

    /**
     * How many UTF-16 units the code point starting here occupies
     *
     * @param {string} value
     * @param {number} index
     * @return {number} 1, or 2 for a surrogate pair
     */
    function unitsAt(value, index) {
        var first = value.charCodeAt(index),
            second;

        if (first >= 0xD800 && first <= 0xDBFF && index + 1 < value.length) {
            second = value.charCodeAt(index + 1);

            if (second >= 0xDC00 && second <= 0xDFFF) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * How many UTF-8 bytes the code point starting here occupies
     *
     * @param {string} value
     * @param {number} index
     * @param {number} units
     * @return {number}
     */
    function bytesAt(value, index, units) {
        var code;

        if (units === 2) {
            return 4;
        }

        code = value.charCodeAt(index);

        if (code < 0x80) {
            return 1;
        }

        return code < 0x800 ? 2 : 3;
    }

    /**
     * Length of a string in UTF-8 bytes
     *
     * The limit the server applies is a byte limit, so counting characters here would disagree
     * with it about which references exist as soon as a message stops being plain ASCII.
     *
     * @param {string} value
     * @return {number}
     */
    function byteLength(value) {
        var total = 0,
            index = 0,
            units;

        while (index < value.length) {
            units = unitsAt(value, index);
            total += bytesAt(value, index, units);
            index += units;
        }

        return total;
    }

    /**
     * Cut a string down to a byte budget without splitting a character in half
     *
     * @param {string} value
     * @param {number} limit UTF-8 bytes
     * @return {string}
     */
    function truncateToBytes(value, limit) {
        var total = 0,
            index = 0,
            units,
            bytes;

        while (index < value.length) {
            units = unitsAt(value, index);
            bytes = bytesAt(value, index, units);

            if (total + bytes > limit) {
                break;
            }

            total += bytes;
            index += units;
        }

        return value.slice(0, index);
    }

    /**
     * Undo the backslash escaping the renderer undoes on a translated message
     *
     * @param {string} value
     * @return {string}
     */
    function stripSlashes(value) {
        var text = '',
            index = 0,
            next;

        while (index < value.length) {
            if (value.charAt(index) !== '\\') {
                text += value.charAt(index);
                index++;
                continue;
            }

            index++;

            if (index >= value.length) {
                break;
            }

            next = value.charAt(index);
            text += next === '0' ? '\x00' : next;
            index++;
        }

        return text;
    }

    /**
     * Remove one matched pair of surrounding single or double quotes
     *
     * Only a matched pair goes: a stray quote on one side is part of the value. The same directive
     * is written both quoted and bare by different parts of Magento, and both spellings have to
     * arrive at one entry.
     *
     * @param {string} value already trimmed
     * @return {string}
     */
    function stripSurroundingQuotes(value) {
        var first;

        if (value.length < 2) {
            return value;
        }

        first = value.charAt(0);

        if ((first === '"' || first === '\'') && value.charAt(value.length - 1) === first) {
            return value.slice(1, -1);
        }

        return value;
    }

    /**
     * Read one parameter value, starting just past its `=`
     *
     * @param {string} text
     * @param {number} start index just past the `=`
     * @return {{value: string, index: number}} the value and where reading stopped
     */
    function readParameterValue(text, start) {
        var index = start,
            value = '',
            quote = '',
            character,
            escaped;

        if (index >= text.length) {
            return {value: '', index: index};
        }

        character = text.charAt(index);

        if (isTokenizerWhitespace(character)) {
            return {value: '', index: index + 1};
        }

        if (character === '"' || character === '\'') {
            quote = character;
        } else {
            value += character;
        }

        index++;

        while (index < text.length) {
            character = text.charAt(index);

            if (quote === '' && isTokenizerWhitespace(character)) {
                index++;
                break;
            }

            if (quote !== '' && character === quote) {
                index++;
                break;
            }

            if (character === '\\') {
                index++;
                escaped = index < text.length ? text.charAt(index) : '';

                if (escaped !== '\\') {
                    value += '\\';
                }

                value += escaped;
                index++;
                continue;
            }

            value += character;
            index++;
        }

        return {value: value, index: index};
    }

    /**
     * Split a directive body into its named parameters
     *
     * @param {string} text the directive body, chain excluded
     * @return {Object.<string, string>}
     */
    function readParameters(text) {
        var parameters = {},
            name = '',
            index = 0,
            character,
            read;

        while (index < text.length) {
            character = text.charAt(index);

            if (isTokenizerWhitespace(character)) {
                index++;
                continue;
            }

            if (character !== '=') {
                name += character;
                index++;
                continue;
            }

            read = readParameterValue(text, index + 1);
            parameters[name] = read.value;
            name = '';
            index = read.index;
        }

        return parameters;
    }

    /**
     * Read the message of a translation directive, the way the renderer reads it
     *
     * The renderer wants a quoted message followed by nothing at all, or by whitespace and then
     * the message's parameters; anything else renders as an empty string. When the message cannot
     * be read that way the reference carries no expression, because the alternative is inventing
     * one and an admin cannot tell an invented answer from a real one.
     *
     * @param {string} text the directive body, chain excluded
     * @return {string}
     */
    function readTranslationMessage(text) {
        var index = 0,
            message = '',
            quote,
            character,
            closed = false;

        while (index < text.length && NORMALISER_WHITESPACE.test(text.charAt(index))) {
            index++;
        }

        quote = text.charAt(index);

        if (quote !== '"' && quote !== '\'') {
            return '';
        }

        index++;

        while (index < text.length) {
            character = text.charAt(index);

            if (character === quote && text.charAt(index - 1) !== '\\') {
                closed = true;
                index++;
                break;
            }

            message += character;
            index++;
        }

        if (!closed) {
            return '';
        }

        if (index < text.length && !NORMALISER_WHITESPACE.test(text.charAt(index))) {
            return '';
        }

        return stripSlashes(message);
    }

    /**
     * Pull the raw expression out of a directive body
     *
     * @param {Object} rules the kind's entry in the published set
     * @param {string} body the directive body, chain excluded
     * @return {string}
     */
    function readRawExpression(rules, body) {
        var parameters;

        if (rules.read === READ_BODY) {
            return body;
        }

        if (rules.read === READ_MESSAGE) {
            return readTranslationMessage(body);
        }

        if (rules.read !== READ_PARAMETER) {
            return '';
        }

        parameters = readParameters(body);

        if (Object.prototype.hasOwnProperty.call(parameters, rules.parameter)) {
            return parameters[rules.parameter];
        }

        if (rules.fallback !== '' && Object.prototype.hasOwnProperty.call(parameters, rules.fallback)) {
            return parameters[rules.fallback];
        }

        return '';
    }

    /**
     * Normalise a raw expression exactly as the server normalises whatever it receives
     *
     * @param {Object} rules the kind's entry in the published set
     * @param {string} raw
     * @return {string}
     */
    function normaliseExpression(rules, raw) {
        var value;

        if (rules.read === READ_NOTHING) {
            return '';
        }

        value = trimBothEnds(raw);

        if (rules.unquote) {
            value = trimBothEnds(stripSurroundingQuotes(value));
        }

        if (rules.whitespace !== WHITESPACE_KEEP) {
            value = trimBothEnds(value.replace(NORMALISER_WHITESPACE_RUN, rules.whitespace));
        }

        return value;
    }

    /**
     * Render modifier calls back into chain bytes
     *
     * Kept here rather than borrowed so this module depends on nothing; the suite pins it against
     * the chain module so the two spellings of one grammar cannot drift apart unnoticed.
     *
     * @param {Array.<{name: string, args: string[]}>} calls
     * @return {string}
     */
    function serialiseChain(calls) {
        var text = '',
            index;

        for (index = 0; index < calls.length; index++) {
            text += '|' + calls[index].name;

            if (calls[index].args.length > 0) {
                text += ':' + calls[index].args.join(':');
            }
        }

        return text;
    }

    /**
     * Break a chain into calls, and say whether it is safe to write a new chain over it
     *
     * A chain counts as decomposed only when every part carries a plainly shaped name and
     * arguments and when rendering the calls reproduces the original bytes exactly. Anything else
     * is reported undecomposed so the inspector shows it read-only: rewriting a chain that could
     * not be read back is how an admin loses the `|escape:HTML` they typed on purpose.
     *
     * @param {string} chainText raw chain bytes including the leading `|`, or `''`
     * @return {{calls: Array.<{name: string, args: string[]}>, parsed: boolean}}
     */
    function decomposeChain(chainText) {
        var calls = [],
            parsed = true,
            parts,
            segments,
            name,
            args,
            index,
            argument;

        if (chainText === '') {
            return {calls: calls, parsed: parsed};
        }

        parts = chainText.slice(1).split('|');

        for (index = 0; index < parts.length; index++) {
            if (parts[index] === '') {
                parsed = false;
                continue;
            }

            segments = parts[index].split(':');
            name = segments[0];
            args = segments.slice(1);

            if (!MODIFIER_NAME.test(name)) {
                parsed = false;
            }

            for (argument = 0; argument < args.length; argument++) {
                if (!MODIFIER_ARGUMENT.test(args[argument])) {
                    parsed = false;
                }
            }

            calls.push({name: name, args: args});
        }

        if (parsed && serialiseChain(calls) !== chainText) {
            parsed = false;
        }

        return {calls: calls, parsed: parsed};
    }

    /**
     * Where a chain would have to be inserted when the directive has none
     *
     * Just past the last character the renderer would keep, never just before the closing `}}`.
     * A directive body is split on its first `|`, so inserting before a trailing space would hand
     * the variable resolver a path with that space still stuck to the end of it, and the path
     * would no longer resolve.
     *
     * @param {string} body the whole directive body
     * @return {number} an offset within the body
     */
    function insertionPoint(body) {
        var index = body.length;

        while (index > 0 && isTokenizerWhitespace(body.charAt(index - 1))) {
            index--;
        }

        return index;
    }

    /**
     * Turn one matched directive into an occurrence, or reject it
     *
     * @param {string} segment the text being scanned
     * @param {number} open offset of `{{`
     * @param {number} nameEnd offset just past the directive name
     * @param {number} close offset of the closing `}}`
     * @param {Object} rules the kind's entry in the published set
     * @param {number} offset how far the scanned text sits into the document
     * @return {Object|null} null when the server would refuse this reference
     */
    function buildOccurrence(segment, open, nameEnd, close, rules, offset) {
        var interior = segment.slice(nameEnd, close),
            pipe = interior.indexOf('|'),
            body = pipe === -1 ? interior : interior.slice(0, pipe),
            chainText = pipe === -1 ? '' : interior.slice(pipe),
            expression = normaliseExpression(rules, readRawExpression(rules, body)),
            overlong = false,
            chain,
            modifierStart;

        if (FORBIDDEN_IN_EXPRESSION.test(expression)) {
            return null;
        }

        if (byteLength(expression) > EXPRESSION_BYTE_LIMIT) {
            expression = trimEnd(truncateToBytes(expression, EXPRESSION_BYTE_LIMIT));
            overlong = true;
        }

        chain = decomposeChain(chainText);
        modifierStart = pipe === -1 ? nameEnd + insertionPoint(interior) : nameEnd + pipe;

        return {
            reference: rules.canonical + ':' + expression,
            kind: rules.canonical,
            expression: expression,
            modifiers: chain.calls,
            modifierText: chainText,
            chainParsed: chain.parsed,
            overlong: overlong,
            start: offset + open,
            end: offset + close + 2,
            modifierStart: offset + modifierStart,
            modifierEnd: offset + (pipe === -1 ? modifierStart : close)
        };
    }

    /**
     * List the directives inside part of a document
     *
     * Offsets are reported relative to the whole document, not to the range, so a caller can write
     * straight back over what it read. The range is expected to bracket whole directives: one cut
     * in half by either bound simply does not appear, which is the outcome the inspector wants
     * when the document has moved underneath it.
     *
     * @param {string} text the whole document
     * @param {number} [from] first offset to read, defaults to the start of the document
     * @param {number} [to] offset to stop at, defaults to the end of the document
     * @return {Object[]} occurrences in document order
     */
    function scanRange(text, from, to) {
        var content = typeof text === 'string' ? text : '',
            lower = typeof from === 'number' ? Math.max(0, Math.min(content.length, from)) : 0,
            upper = typeof to === 'number' ? Math.max(0, Math.min(content.length, to)) : content.length,
            occurrences = [],
            segment,
            cursor = 0,
            open,
            nameStart,
            nameEnd,
            close,
            rules,
            occurrence;

        if (upper <= lower) {
            return occurrences;
        }

        segment = content.slice(lower, upper);

        while (cursor < segment.length) {
            open = segment.indexOf('{{', cursor);

            if (open === -1) {
                break;
            }

            nameStart = open + 2;
            nameEnd = nameStart;

            while (nameEnd < segment.length &&
                nameEnd - nameStart < DIRECTIVE_NAME_LIMIT &&
                isLetter(segment.charAt(nameEnd))
            ) {
                nameEnd++;
            }

            close = segment.indexOf('}}', nameEnd);

            if (close === -1) {
                break;
            }

            rules = KINDS[segment.slice(nameStart, nameEnd).toLowerCase()];

            if (rules) {
                occurrence = buildOccurrence(segment, open, nameEnd, close, rules, lower);

                if (occurrence) {
                    occurrences.push(occurrence);
                }
            }

            cursor = close + 2;
        }

        return occurrences;
    }

    /**
     * List every directive in a document
     *
     * @param {string} text
     * @return {Object[]} occurrences in document order
     */
    function scan(text) {
        return scanRange(text);
    }

    /**
     * Read a range again and say whether it still holds the directive a caller is looking at
     *
     * The offsets on an occurrence are absolute, and an absolute offset goes stale the moment
     * anything else in the document is edited. A caller's own previous write counts as anything
     * else: the offsets it holds were measured before that write, so a second write made from them
     * lands somewhere the caller never looked at. A caller that means to write over a directive it
     * read earlier therefore asks whatever tracks edits for it where that directive is now, hands
     * those live bounds here, and writes only over what comes back.
     *
     * An occurrence comes back only when the range still holds exactly one directive, that
     * directive points at the same thing the caller last saw, and its chain still carries the same
     * bytes. Anything else yields no occurrence at all, so writing with a span that was never
     * confirmed is not something a caller can do by mistake.
     *
     * @param {string} text the document as it is now
     * @param {number} from live start of the range, an absolute offset
     * @param {number} to live end of the range, an absolute offset
     * @param {{reference: string, modifierText: string}} expected what the caller last saw
     * @return {{occurrence: Object|null, reason: string}} `ok` when the range still holds it,
     *         `gone` when the range holds no directive at all, `changed` when it holds a different
     *         one or one whose chain has moved on. `occurrence` is set for `ok` and for nothing
     *         else. The reasons are codes for a caller to act on and never wording: what an admin
     *         reads about any of them is said elsewhere.
     */
    function rederive(text, from, to, expected) {
        var occurrences = scanRange(text, from, to),
            occurrence;

        if (occurrences.length === 0) {
            return {occurrence: null, reason: 'gone'};
        }

        if (occurrences.length > 1) {
            return {occurrence: null, reason: 'changed'};
        }

        occurrence = occurrences[0];

        if (!expected ||
            occurrence.reference !== expected.reference ||
            occurrence.modifierText !== expected.modifierText
        ) {
            return {occurrence: null, reason: 'changed'};
        }

        return {occurrence: occurrence, reason: 'ok'};
    }

    /**
     * May these bytes be written into a document as a directive's chain?
     *
     * Removing every modifier is a chain, and it is the empty string. Anything else has to begin
     * at a pipe, because that pipe is where the renderer stops reading the directive's own
     * expression and starts reading its formatting - bytes written without one would silently
     * become part of what the directive points at.
     *
     * A chain also has to come back unchanged from being read and written out again. That is a
     * stricter test than it looks: it refuses a repeated pipe, an empty part and any name or
     * argument shaped unlike the grammar, all of which are shapes a caller can only have arrived
     * at by building the chain wrongly. Refusing to write them keeps a malformed chain out of a
     * template rather than leaving an admin to find it later in a sent email.
     *
     * @param {string} chainText the bytes a caller means to write, `''` for no modifiers at all
     * @return {boolean}
     */
    function isWritableChain(chainText) {
        if (typeof chainText !== 'string') {
            return false;
        }

        if (chainText === '') {
            return true;
        }

        if (chainText.charAt(0) !== '|' || FORBIDDEN_IN_EXPRESSION.test(chainText)) {
            return false;
        }

        return decomposeChain(chainText).parsed;
    }

    return {
        scan: scan,
        scanRange: scanRange,
        rederive: rederive,
        isWritableChain: isWritableChain
    };
});
