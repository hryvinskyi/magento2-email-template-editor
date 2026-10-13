/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The class names a template's source text asks for.
 *
 * Single charter: read every token of every `class` attribute out of template source, as text.
 * Nothing here parses markup, escapes anything for a document, or knows what Tailwind does with
 * the names.
 *
 * Reading the text rather than a parsed tree is the point. A template is very often not a document
 * but a fragment of one: a footer opens with `</td></tr><tr><td class="footer ...">`, closing what
 * the header left open. An HTML parser handed that fragment drops the stray row and cell outright,
 * class attribute and all, so every class on them vanishes before anything is asked to look for it.
 * A template whose `{{if ...}}` directives sit inside a tag's attribute list loses its classes the
 * same way. The text keeps all of them.
 */
define([], function () {
    'use strict';

    /**
     * A `class` attribute, its value in group 1 (double quotes), 2 (single quotes) or 3 (unquoted)
     *
     * A directive inside a quoted value is consumed whole, because it may carry the same quote
     * itself - `class="{{var row.css|escape:"html"}} p-4"` is one value, not one that ends early.
     *
     * @type {RegExp}
     */
    var CLASS_ATTRIBUTE = /(?<![\w:-])class\s*=\s*(?:"((?:\{\{[\s\S]*?\}\}|[^"])*)"|'((?:\{\{[\s\S]*?\}\}|[^'])*)'|([^\s"'=<>`{]+))/gi,

        /**
         * A Magento directive inside an attribute value: it names no class until it is rendered
         *
         * @type {RegExp}
         */
        DIRECTIVE = /\{\{[\s\S]*?\}\}/g;

    return {
        /**
         * Every distinct class name in the source, in the order it first appears
         *
         * @param {string} source template source text
         * @return {Array<string>}
         */
        collect: function (source) {
            var pattern = new RegExp(CLASS_ATTRIBUTE.source, CLASS_ATTRIBUTE.flags),
                seen = Object.create(null),
                names = [],
                match,
                tokens,
                index;

            if (typeof source !== 'string' || source === '') {
                return names;
            }

            while ((match = pattern.exec(source)) !== null) {
                tokens = (match[1] || match[2] || match[3] || '').replace(DIRECTIVE, ' ').split(/\s+/);

                for (index = 0; index < tokens.length; index++) {
                    if (tokens[index] !== '' && !seen[tokens[index]]) {
                        seen[tokens[index]] = true;
                        names.push(tokens[index]);
                    }
                }
            }

            return names;
        }
    };
});
