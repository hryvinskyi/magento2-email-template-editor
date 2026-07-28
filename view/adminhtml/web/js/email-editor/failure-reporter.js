/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Telling the user that a request failed, in the editor's own voice.
 *
 * Single charter: turn a failed request into a message the user can see. Nothing
 * here knows which request failed or why, and no component's own presentation
 * belongs in this module.
 *
 * A child panel that has no message surface of its own must not grow one. The
 * editor already owns a status indicator and a status line and writes both when
 * one of its own requests fails, so a child's failure is routed to the same two
 * places rather than acquiring a second look for the same event.
 */
define([
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver'
], function (parentResolver) {
    'use strict';

    return {
        /**
         * Whether a jQuery failure describes a request that was deliberately cancelled.
         *
         * A cancelled request is normal, not a fault: it was either superseded by a
         * newer request of its own kind or dropped along with the screen it belonged
         * to. Nothing the user did went wrong, so nothing may be said about it.
         *
         * @param {string} [textStatus] - The status jQuery passes to a fail handler.
         * @return {boolean}
         */
        isAbort: function (textStatus) {
            return textStatus === 'abort';
        },

        /**
         * Report a failed request on behalf of a component.
         *
         * Does nothing for a cancelled request, and nothing while the editor is not
         * registered yet - a message with nowhere to appear is not worth throwing for,
         * and that window closes before any of these panels can be interacted with.
         *
         * @param {Object} component - The uiComponent whose request failed.
         * @param {string} message - What to tell the user; already translated.
         * @param {string} [textStatus] - The status jQuery passes to a fail handler.
         * @return {void}
         */
        report: function (component, message, textStatus) {
            var editor;

            if (this.isAbort(textStatus)) {
                return;
            }

            editor = parentResolver.peek(component);

            if (!editor || typeof editor.setStatus !== 'function') {
                return;
            }

            editor.setStatus('error', 'ERROR');

            if (typeof editor.statusBarText === 'function') {
                editor.statusBarText(message);
            }
        }
    };
});
