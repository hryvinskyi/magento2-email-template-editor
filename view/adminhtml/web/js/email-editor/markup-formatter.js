/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Line breaks for template markup that leave every directive byte-identical.
 *
 * Single charter: text in, text out. Nothing here touches CodeMirror or the DOM.
 *
 * A directive's interior is not markup, it is an argument. `{{trans "Hello,<br><br>%name"}}` looks
 * a translation up by exactly that string, so breaking the line between its two tags changes the key
 * and the sentence silently stops being translated. A directive spans from `{{` to the first `}}`
 * after it, quoted or not, because that is where the renderer ends it; everything in that span is
 * copied through untouched.
 */
define([], function () {
    'use strict';

    /**
     * Break the line between two adjacent tags in markup that holds no directive
     *
     * @param {string} markup
     * @return {string}
     */
    function breakTags(markup) {
        return markup.replace(/></g, '>\n<');
    }

    return {
        /**
         * Put each tag that directly follows another on a line of its own, outside directives only
         *
         * @param {string} text
         * @return {string}
         */
        breakBetweenTags: function (text) {
            var source = typeof text === 'string' ? text : '',
                result = '',
                cursor = 0,
                open,
                close;

            while (cursor < source.length) {
                open = source.indexOf('{{', cursor);

                if (open === -1) {
                    break;
                }

                close = source.indexOf('}}', open + 2);

                if (close === -1) {
                    break;
                }

                result += breakTags(source.slice(cursor, open)) + source.slice(open, close + 2);
                cursor = close + 2;
            }

            return result + breakTags(source.slice(cursor));
        }
    };
});
