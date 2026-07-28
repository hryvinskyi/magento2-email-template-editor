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
    'emailEditorDiffEngine',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/failure-reporter'
], function (Component, ko, $, $t, DiffEngine, parentResolver, failureReporter) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/version-history',
            urls: window.emailEditorConfig && window.emailEditorConfig.urls || {},
            formKey: window.emailEditorConfig && window.emailEditorConfig.formKey || ''
        },

        /**
         * Initialize the version history component.
         *
         * @return {Object}
         */
        initialize: function () {
            this._super();

            this.observe({
                isVisible: false,
                isLoading: false,
                entries: [],
                activePreviewId: null,
                showDiff: false,
                diffHtml: '',
                diffLabel: ''
            });

            this._currentIdentifier = '';
            this._currentStoreId = 0;

            return this;
        },

        /**
         * Show the version history panel and load entries.
         *
         * @param {string} identifier
         * @param {number} storeId
         */
        show: function (identifier, storeId) {
            this._currentIdentifier = identifier;
            this._currentStoreId = storeId;

            // Open on the version list, never on whatever the panel was last showing -
            // a comparison, or a message about a load that failed the time before.
            this.showDiff(false);
            this.isVisible(true);
            this._loadVersions();
        },

        /**
         * Close the version history panel.
         */
        close: function () {
            this.isVisible(false);
            this.showDiff(false);
            this.trigger('historyClose');
        },

        /**
         * Load version entries from the server.
         */
        _loadVersions: function () {
            var self = this;

            this.isLoading(true);

            this._ajax(this.urls.versionLoadList, 'GET', {
                template_identifier: this._currentIdentifier,
                store_id: this._currentStoreId
            }).done(function (res) {
                if (res.success === false) {
                    self.entries([]);
                    self._showMessage(res.message || $t('The version history could not be loaded.'));

                    return;
                }

                self.entries(res.versions || []);
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus)) {
                    return;
                }

                self.entries([]);
                self._showMessage($t('The version history could not be loaded. Please try again.'));
            }).always(function () {
                self.isLoading(false);
            });
        },

        /**
         * Preview a specific version entry.
         *
         * @param {Object} entry
         */
        previewVersion: function (entry) {
            var self = this;

            this.activePreviewId(entry.version_id);
            this.trigger('historyPreviewStart');

            this._ajax(this.urls.versionPreview, 'POST', {
                version_id: entry.version_id
            }).done(function (res) {
                self.trigger('historyPreview', res);

                if (!res.success) {
                    self.activePreviewId(null);
                    self._showMessage(res.message || $t('This version could not be previewed.'));
                }
            }).fail(function (xhr, textStatus) {
                // The preview overlay was raised before this request went out and only
                // this event lowers it again, so it has to be fired on every ending -
                // otherwise a failure leaves the preview spinning with nothing coming.
                self.trigger('historyPreview', {success: false});
                self.activePreviewId(null);

                if (failureReporter.isAbort(textStatus)) {
                    return;
                }

                self._showMessage($t('This version could not be previewed. Please try again.'));
            });
        },

        /**
         * Load and display a diff for a specific version entry against the previous version.
         *
         * @param {Object} entry
         */
        showVersionDiff: function (entry) {
            var self = this,
                entries = this.entries(),
                currentIndex = -1,
                previousEntry = null,
                i;

            for (i = 0; i < entries.length; i++) {
                if (entries[i].version_id === entry.version_id) {
                    currentIndex = i;
                    break;
                }
            }

            if (currentIndex >= 0 && currentIndex < entries.length - 1) {
                previousEntry = entries[currentIndex + 1];
            }

            if (!previousEntry) {
                this.diffHtml('<div class="ete-diff-empty">No previous version to compare against.</div>');
                this.diffLabel('v' + entry.version_number + ' (initial)');
                this.showDiff(true);

                return;
            }

            this._ajax(this.urls.versionDiff, 'POST', {
                version_id_a: previousEntry.version_id,
                version_id_b: entry.version_id
            }).done(function (res) {
                if (!res.success) {
                    self._showMessage(res.message || $t('This comparison could not be built.'));

                    return;
                }

                var oldContent = res.version_a ? (res.version_a.content || '') : '',
                    newContent = res.version_b ? (res.version_b.content || '') : '',
                    hunks = DiffEngine.computeDiff(oldContent, newContent),
                    html = '',
                    i, j, line, lineClass, oldLn, newLn, text, prefix;

                for (i = 0; i < hunks.length; i++) {
                    html += '<div class="ete-diff-hunk">';

                    for (j = 0; j < hunks[i].lines.length; j++) {
                        line = hunks[i].lines[j];

                        if (line.type === 'add') {
                            lineClass = 'ete-diff-line-add';
                            oldLn = '';
                            newLn = line.newLine;
                            prefix = '+';
                        } else if (line.type === 'remove') {
                            lineClass = 'ete-diff-line-remove';
                            oldLn = line.oldLine;
                            newLn = '';
                            prefix = '-';
                        } else {
                            lineClass = 'ete-diff-line-equal';
                            oldLn = line.oldLine;
                            newLn = line.newLine;
                            prefix = ' ';
                        }

                        text = line.text;

                        html += '<div class="ete-diff-line ' + lineClass + '">' +
                            '<span class="ete-diff-line-number ete-diff-ln-old">' + oldLn + '</span>' +
                            '<span class="ete-diff-line-number ete-diff-ln-new">' + newLn + '</span>' +
                            '<span class="ete-diff-line-prefix">' + prefix + '</span>' +
                            '<span class="ete-diff-line-text">' + self._escapeHtml(text) + '</span>' +
                            '</div>';
                    }

                    html += '</div>';
                }

                self.diffHtml(html);
                self.diffLabel('v' + entry.version_number + ' changes');
                self.showDiff(true);
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus)) {
                    return;
                }

                self._showMessage($t('This comparison could not be built. Please try again.'));
            });
        },

        /**
         * Hide the diff view.
         */
        hideDiff: function () {
            this.showDiff(false);
        },

        /**
         * Restore a specific version after user confirmation.
         *
         * @param {Object} entry
         */
        restoreVersion: function (entry) {
            var self = this;

            this.trigger('confirmAction', {
                title: $.mage.__('Restore Version'),
                message: $.mage.__('Restore version v') + entry.version_number + $.mage.__('? This will create a new draft with this content.'),
                detail: entry.version_comment
                    ? '<strong>' + $.mage.__('Comment:') + '</strong> ' + entry.version_comment
                    : '',
                actionLabel: $.mage.__('Restore'),
                type: 'primary',
                onConfirm: function () {
                    self._ajax(self.urls.versionRestore, 'POST', {
                        version_id: entry.version_id,
                        template_identifier: self._currentIdentifier,
                        store_id: self._currentStoreId
                    }).done(function (res) {
                        if (!res.success) {
                            self._showMessage(res.message || $t('This version could not be restored.'));

                            return;
                        }

                        self.trigger('historyRestore', res);
                        self.close();
                    }).fail(function (xhr, textStatus) {
                        if (failureReporter.isAbort(textStatus)) {
                            return;
                        }

                        self._showMessage($t('This version could not be restored. Please try again.'));
                    });
                }
            });
        },

        /**
         * Show a message inside this panel.
         *
         * This panel is a modal that covers the whole screen, the editor's status line
         * included, so a message about it has to be rendered where the user is actually
         * looking. The panel already has one place for text that is not a version list -
         * the pane that says a version has nothing to be compared against - and that is
         * the place reused here, so there is one message surface rather than two.
         *
         * @param {string} message - What to tell the user; already translated.
         * @return {void}
         * @private
         */
        _showMessage: function (message) {
            this.diffHtml('<div class="ete-diff-empty">' + this._escapeHtml(message) + '</div>');
            this.diffLabel('');
            this.showDiff(true);
        },

        /**
         * Perform an AJAX request with automatic form_key injection.
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
        },

        /**
         * Escape HTML entities in a string.
         *
         * @param {string} str
         * @return {string}
         */
        _escapeHtml: function (str) {
            var div = document.createElement('div');

            div.appendChild(document.createTextNode(str));

            return div.innerHTML;
        }
    });
});
