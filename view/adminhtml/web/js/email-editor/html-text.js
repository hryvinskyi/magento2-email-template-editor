/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Text made safe to place inside HTML the editor builds as a string.
 *
 * Single charter: escape. Whatever a server message or a stored value says is shown as words, never
 * read as markup - the preview frame it lands in runs with the admin's origin.
 */
define([], function () {
    'use strict';

    var ENTITIES = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        '\'': '&#39;'
    };

    return {
        /**
         * Escape text for element content or a quoted attribute value
         *
         * @param {*} text anything; null and undefined become the empty string
         * @return {string}
         */
        escape: function (text) {
            return String(text === null || text === undefined ? '' : text).replace(/[&<>"']/g, function (character) {
                return ENTITIES[character];
            });
        }
    };
});
