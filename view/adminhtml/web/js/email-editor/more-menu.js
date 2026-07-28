/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'uiComponent',
    'ko',
    'jquery'
], function (Component, ko, $) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/more-menu',
            isOpen: false,
            hasDraft: false
        },

        /** @type {Function|null} The document handler as it was actually registered. */
        _documentClickHandler: null,

        /**
         * @inheritDoc
         */
        initialize: function () {
            this._super();
            this.observe(['isOpen', 'hasDraft']);

            // jQuery removes a handler by identity, and .bind() returns a different
            // function every time it is called - so the registered one has to be kept.
            // Without it the teardown below matches nothing, the document keeps calling
            // into a component that is gone, and that component can never be collected.
            this._documentClickHandler = this._onDocumentClick.bind(this);
            $(document).on('click', this._documentClickHandler);

            return this;
        },

        /**
         * Toggle the menu visibility
         */
        toggle: function () {
            this.isOpen(!this.isOpen());
        },

        /**
         * Close the menu
         */
        close: function () {
            this.isOpen(false);
        },

        /**
         * Open preview in new tab
         */
        previewInNewTab: function () {
            this.close();
            this.trigger('menuAction', 'previewInNewTab');
        },

        /**
         * Open version history
         */
        openVersionHistory: function () {
            this.close();
            this.trigger('menuAction', 'openVersionHistory');
        },

        /**
         * Delete draft action
         */
        deleteDraft: function () {
            this.close();
            this.trigger('menuAction', 'deleteDraft');
        },

        /**
         * Reset template to default
         */
        resetTemplate: function () {
            this.close();
            this.trigger('menuAction', 'resetTemplate');
        },

        /**
         * Close menu when clicking outside
         *
         * @param {Event} e
         * @private
         */
        _onDocumentClick: function (e) {
            if (this.isOpen() && !$(e.target).closest('.ete-more-menu, .ete-toolbar-action-more').length) {
                this.close();
            }
        },

        /**
         * @inheritDoc
         */
        destroy: function () {
            if (this._documentClickHandler) {
                $(document).off('click', this._documentClickHandler);
                this._documentClickHandler = null;
            }

            this._super();
        }
    });
});
