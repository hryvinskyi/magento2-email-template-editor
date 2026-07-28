/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'uiComponent',
    'ko',
    'jquery',
    'mage/translate',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/failure-reporter'
], function (Component, ko, $, $t, parentResolver, failureReporter) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/draft-list-panel',
            urls: window.emailEditorConfig && window.emailEditorConfig.urls || {},
            formKey: window.emailEditorConfig && window.emailEditorConfig.formKey || ''
        },

        /**
         * Initialize component and set up observables.
         *
         * @return {Object}
         */
        initialize: function () {
            this._super();

            this.observe(['drafts', 'activeDraftId']);

            this.drafts([]);
            this.activeDraftId(null);

            return this;
        },

        /**
         * Load drafts for a given template identifier and store.
         *
         * @param {string} templateIdentifier
         * @param {number} storeId
         * @return {jQuery.jqXHR}
         */
        loadDrafts: function (templateIdentifier, storeId) {
            var self = this;

            return this._ajax(this.urls.loadDrafts, 'GET', {
                template_identifier: templateIdentifier,
                store_id: storeId
            }).done(function (res) {
                if (res.success === false) {
                    failureReporter.report(self, res.message || $t('The drafts could not be loaded.'));

                    return;
                }

                if (res.drafts) {
                    self.setDrafts(res.drafts);
                }
            }).fail(function (xhr, textStatus) {
                failureReporter.report(
                    self,
                    $t('The drafts could not be loaded. Please try again.'),
                    textStatus
                );
            });
        },

        /**
         * Select a draft and fire the draftSelect event.
         *
         * @param {Object} draftData
         */
        selectDraft: function (draftData) {
            this.activeDraftId(draftData.entity_id);
            this.trigger('draftSelect', draftData);
        },

        /**
         * Fire the draftCreate event.
         */
        createDraft: function () {
            this.trigger('draftCreate');
        },

        /**
         * Prompt for a new name and rename the draft on the server.
         *
         * @param {Object} draftData
         */
        renameDraft: function (draftData) {
            var newName = prompt('Enter new draft name:', draftData.draft_name || ''),
                self = this;

            if (newName === null || newName === '') {
                return;
            }

            this._ajax(this.urls.renameDraft, 'POST', {
                entity_id: draftData.entity_id,
                draft_name: newName
            }).done(function (res) {
                if (res && res.success === false) {
                    failureReporter.report(self, res.message || $t('The draft could not be renamed.'));

                    return;
                }

                self._reloadDraftsFromParent();
            }).fail(function (xhr, textStatus) {
                failureReporter.report(
                    self,
                    $t('The draft could not be renamed. Please try again.'),
                    textStatus
                );
            });
        },

        /**
         * Duplicate a draft on the server.
         *
         * @param {Object} draftData
         */
        duplicateDraft: function (draftData) {
            var self = this;

            this._ajax(this.urls.duplicateDraft, 'POST', {
                entity_id: draftData.entity_id
            }).done(function (res) {
                if (res && res.success === false) {
                    failureReporter.report(self, res.message || $t('The draft could not be duplicated.'));

                    return;
                }

                self._reloadDraftsFromParent();
            }).fail(function (xhr, textStatus) {
                failureReporter.report(
                    self,
                    $t('The draft could not be duplicated. Please try again.'),
                    textStatus
                );
            });
        },

        /**
         * Delete a draft after confirmation.
         *
         * @param {Object} draftData
         */
        deleteDraft: function (draftData) {
            var self = this;

            this.trigger('confirmAction', {
                title: $.mage.__('Delete Draft'),
                message: $.mage.__('Are you sure you want to delete this draft? This action cannot be undone.'),
                detail: draftData.draft_name || $.mage.__('Untitled Draft'),
                actionLabel: $.mage.__('Delete'),
                type: 'danger',
                onConfirm: function () {
                    self._ajax(self.urls.deleteDraft, 'POST', {
                        entity_id: draftData.entity_id
                    }).done(function (res) {
                        if (res && res.success === false) {
                            failureReporter.report(
                                self,
                                res.message || $t('The draft could not be deleted.')
                            );

                            return;
                        }

                        self._reloadDraftsFromParent();
                    }).fail(function (xhr, textStatus) {
                        failureReporter.report(
                            self,
                            $t('The draft could not be deleted. Please try again.'),
                            textStatus
                        );
                    });
                }
            });
        },

        /**
         * Set the drafts array and reconcile the active draft ID.
         *
         * @param {Array} draftsArray
         */
        setDrafts: function (draftsArray) {
            var currentId = this.activeDraftId(),
                found = false,
                i;

            this.drafts(draftsArray);

            if (currentId !== null) {
                for (i = 0; i < draftsArray.length; i++) {
                    if (draftsArray[i].entity_id === currentId) {
                        found = true;
                        break;
                    }
                }
            }

            if (!found) {
                this.activeDraftId(draftsArray.length > 0 ? draftsArray[0].entity_id : null);
            }
        },

        /**
         * Reload drafts via the editor, which knows which template and store view the
         * list belongs to.
         *
         * @return {void}
         */
        _reloadDraftsFromParent: function () {
            var editor = parentResolver.peek(this);

            if (editor && typeof editor.loadDrafts === 'function') {
                editor.loadDrafts();
            }
        },

        /**
         * Perform an AJAX request with form_key injection.
         *
         * The request is handed to the editor so that it counts towards the one busy
         * state the whole screen shares and can be reached by its cancellation sweep.
         * The jqXHR itself is what comes back, synchronously, so every caller keeps
         * chaining .done/.fail on the request exactly as before. While the editor is
         * not registered yet the request simply goes out untracked - invisible is far
         * better than not sent at all, and that window closes during page load.
         *
         * @param {string} url
         * @param {string} method
         * @param {Object} data
         * @return {jQuery.jqXHR}
         */
        _ajax: function (url, method, data) {
            var editor = parentResolver.peek(this),
                xhr;

            data.form_key = this.formKey;

            xhr = $.ajax({
                url: url,
                type: method,
                data: data,
                dataType: 'json'
            });

            return editor && typeof editor.trackRequest === 'function'
                ? editor.trackRequest(xhr)
                : xhr;
        }
    });
});
