/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Which values a draft save may claim to know.
 *
 * Single charter: decide, for the fields the editor can be unsure of, whether to send them at all.
 *
 * The server keeps a stored value for a field the request leaves out and clears it for a field the
 * request carries empty. So an empty string sent for something the editor simply does not know yet
 * is not "nothing" - it wipes what is stored. A theme that has not finished loading would switch
 * the template to the store's default theme, and a stylesheet the compiler never produced for this
 * content would strip every utility class from the email. Both are left off instead.
 */
define([], function () {
    'use strict';

    return {
        /**
         * Carry the theme id only when one is selected
         *
         * @param {Object} data the payload, changed in place
         * @param {*} themeId
         * @return {Object} the payload
         */
        withThemeId: function (data, themeId) {
            if (themeId !== null && themeId !== undefined && themeId !== '') {
                data.theme_id = themeId;
            }

            return data;
        },

        /**
         * Carry the Tailwind CSS only when it was compiled for exactly the content being sent
         *
         * An empty stylesheet compiled for this content is known - the template has no utility
         * classes - and is sent, because it is what clears the styles of classes since removed.
         *
         * @param {Object} data the payload, carrying `template_content`; changed in place
         * @param {*} css the last stylesheet the compiler produced
         * @param {*} compiledFor the content that stylesheet was produced for, null when none was
         * @return {Object} the payload
         */
        withTailwindCss: function (data, css, compiledFor) {
            if (typeof css === 'string' && typeof compiledFor === 'string' && compiledFor === data.template_content) {
                data.tailwind_css = css;
            }

            return data;
        }
    };
});
