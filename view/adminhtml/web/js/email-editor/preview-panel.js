/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'uiComponent',
    'ko',
    'uiRegistry'
], function (Component, ko, registry) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/preview-panel',
            isMobile: false,
            isLoading: false,
            content: '',
            tracks: {
                isMobile: true,
                isLoading: true,
                content: true
            }
        },

        /**
         * Set viewport to desktop mode.
         */
        setDesktop: function () {
            this.isMobile = false;
        },

        /**
         * Set viewport to mobile mode.
         */
        setMobile: function () {
            this.isMobile = true;
        },

        /**
         * Show the loading overlay.
         */
        showLoading: function () {
            this.isLoading = true;
        },

        /**
         * Hide the loading overlay.
         */
        hideLoading: function () {
            this.isLoading = false;
        },

        /**
         * Set the preview HTML content.
         *
         * @param {string} html
         */
        setContent: function (html) {
            this.content = this._withBaseHref(html);
        },

        /**
         * Inject a <base> tag into the preview HTML.
         *
         * The preview is shown in a srcdoc iframe, which inherits the parent
         * admin document's base URL. Without an explicit base, any relative or
         * unresolved URL in the rendered email (e.g. a stray "{{var logo_url}}"
         * or a relative "media/..." path) is resolved against the editor route
         * and fired at "emaileditor/editor/index/...", crashing the admin
         * controller. Anchoring the base at the site origin makes such requests
         * resolve to the frontend (a harmless 404) and lets genuine relative
         * asset paths load correctly.
         *
         * @param {string} html
         * @return {string}
         * @private
         */
        _withBaseHref: function (html) {
            if (typeof html !== 'string' || html === '') {
                return html;
            }

            var base = '<base href="' + window.location.origin + '/">';

            if (/<head[^>]*>/i.test(html)) {
                return html.replace(/<head[^>]*>/i, function (match) {
                    return match + base;
                });
            }

            if (/<html[^>]*>/i.test(html)) {
                return html.replace(/<html[^>]*>/i, function (match) {
                    return match + '<head>' + base + '</head>';
                });
            }

            return base + html;
        },

        /**
         * Trigger the parent component's renderPreview method if available.
         */
        refresh: function () {
            this._getParent(function (parent) {
                if (typeof parent.renderPreview === 'function') {
                    parent.renderPreview();
                }
            });
        },

        /**
         * Open the send test email dialog via the parent component.
         */
        sendTestEmail: function () {
            this._getParent(function (parent) {
                if (typeof parent.openSendTestEmailDialog === 'function') {
                    parent.openSendTestEmailDialog();
                }
            });
        },

        /**
         * Resolve the parent component via the registry.
         *
         * @param {Function} callback
         */
        _getParent: function (callback) {
            if (this._parentRef) {
                callback(this._parentRef);

                return;
            }

            var self = this;

            registry.get(this.parentName, function (parent) {
                self._parentRef = parent;
                callback(parent);
            });
        }
    });
});
