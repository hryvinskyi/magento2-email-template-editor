/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'uiComponent',
    'ko',
    'jquery',
    'mage/translate'
], function (Component, ko, $, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/theme-editor',
            urls: window.emailEditorConfig && window.emailEditorConfig.urls || {},
            formKey: window.emailEditorConfig && window.emailEditorConfig.formKey || '',
            storeId: window.emailEditorConfig && window.emailEditorConfig.storeId || 0,
            themes: [],
            currentThemeId: null,
            showImportModal: false,
            showAddModal: false,
            newThemeName: '',
            importFile: null,
            editor: null
        },

        /** @type {number|null} */
        _autoSaveTimer: null,

        /** @type {HTMLElement|null} */
        _editorElement: null,

        /** @type {string|null} */
        _pendingValue: null,

        /**
         * Initialize the theme editor component.
         *
         * @return {Object}
         */
        initialize: function () {
            this._super();

            this.observe([
                'themes',
                'currentThemeId',
                'showImportModal',
                'showAddModal',
                'newThemeName',
                'importFile'
            ]);

            this.loadThemes();

            return this;
        },

        /**
         * Store the DOM element for deferred CodeMirror initialization.
         * CodeMirror is created lazily when the container becomes visible.
         *
         * @param {HTMLElement} element
         */
        initCodeMirror: function (element) {
            this._editorElement = element;
            this._tryCreateEditor();
        },

        /**
         * Create the CodeMirror editor if the container is visible and not yet initialized.
         */
        _tryCreateEditor: function () {
            var self = this,
                el = this._editorElement;

            if (this.editor || !el || !el.offsetParent) {
                return;
            }

            require([
                'Hryvinskyi_ConfigurationFields/js/codemirror/lib/codemirror',
                'Hryvinskyi_ConfigurationFields/js/codemirror/mode/css/css',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/edit/matchbrackets',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/edit/closebrackets',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/foldcode',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/foldgutter',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/brace-fold'
            ], function (CodeMirror) {
                if (self.editor) {
                    return;
                }

                self.editor = CodeMirror(el, {
                    mode: 'text/css',
                    theme: 'default',
                    lineNumbers: true,
                    lineWrapping: false,
                    matchBrackets: true,
                    autoCloseBrackets: true,
                    foldGutter: true,
                    gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
                    indentUnit: 2,
                    tabSize: 2,
                    indentWithTabs: false
                });

                self.editor.on('change', function () {
                    clearTimeout(self._autoSaveTimer);

                    self._autoSaveTimer = setTimeout(function () {
                        self._autoSaveTheme();
                    }, 1000);
                });

                if (self._pendingValue !== null) {
                    self.editor.setValue(self._pendingValue);
                    self.editor.clearHistory();
                    self._pendingValue = null;
                }
            });
        },

        /**
         * Load the list of available themes from the server.
         */
        loadThemes: function () {
            var self = this;

            this._ajax(this.urls.themeLoadList).done(function (res) {
                if (res.success && res.themes) {
                    self.themes(res.themes);

                    if (self.currentThemeId() === null && res.themes.length > 0) {
                        var defaultTheme = res.themes.find(function (t) {
                            return t.is_default;
                        });

                        self.selectTheme(defaultTheme || res.themes[0]);
                    }
                }
            });
        },

        /**
         * Select a theme and load its JSON into the editor.
         *
         * @param {Object} themeData
         */
        selectTheme: function (themeData) {
            var self = this,
                themeId = themeData.theme_id;

            if (this.currentThemeId() && this.currentThemeId() !== themeId && this.editor && this.editor.historySize().undo > 0) {
                this._autoSaveTheme();
            }

            this._ajax(this.urls.themeLoad, {theme_id: themeId}).done(function (res) {
                if (res.success && res.theme) {
                    // The stored value is a Tailwind v4 CSS-first theme. Rows that pre-date the
                    // storage migration still hold the legacy JSON shape - normalize so the
                    // editor always shows v4 CSS regardless of which form is on disk.
                    var content = self._normalizeThemeForEditor(res.theme.theme_css);

                    self.currentThemeId(themeId);

                    if (self.editor) {
                        self.editor.setValue(content);
                        self.editor.clearHistory();
                    } else {
                        self._pendingValue = content;
                        self._tryCreateEditor();
                    }
                }
            });
        },

        /**
         * Token sections in the legacy JSON shape mapped to v4 @theme namespaces.
         */
        _LEGACY_JSON_TO_V4_PREFIX: {
            colors: 'color',
            spacing: 'spacing',
            fontSize: 'text',
            fontFamily: 'font',
            fontWeight: 'font-weight',
            lineHeight: 'leading',
            letterSpacing: 'tracking',
            borderRadius: 'radius',
            boxShadow: 'shadow',
            opacity: 'opacity',
            maxWidth: 'container',
            zIndex: 'z'
        },

        /**
         * Convert legacy JSON theme storage to a v4 @theme block for display in CodeMirror.
         *
         * @param {string|null|undefined} stored
         * @return {string}
         * @private
         */
        _normalizeThemeForEditor: function (stored) {
            var content = stored == null ? '' : String(stored),
                trimmed = content.replace(/^\s+/, ''),
                data,
                tokens,
                section,
                prefix,
                bucket,
                name,
                lines = [];

            if (trimmed.charAt(0) !== '{') {
                return content || '@theme {\n}\n';
            }

            try {
                data = JSON.parse(trimmed);
            } catch (e) {
                return content;
            }

            tokens = (data && data.tokens) || {};

            for (section in this._LEGACY_JSON_TO_V4_PREFIX) {
                if (!Object.prototype.hasOwnProperty.call(this._LEGACY_JSON_TO_V4_PREFIX, section)) {
                    continue;
                }

                bucket = tokens[section];
                if (!bucket || typeof bucket !== 'object') {
                    continue;
                }

                prefix = this._LEGACY_JSON_TO_V4_PREFIX[section];
                for (name in bucket) {
                    if (!Object.prototype.hasOwnProperty.call(bucket, name)) {
                        continue;
                    }
                    lines.push(
                        '  --' + prefix + '-' + String(name).replace(/[^a-zA-Z0-9_-]/g, '-') +
                        ': ' + String(bucket[name]).replace(/[;{}]/g, '') + ';'
                    );
                }
            }

            return lines.length ? '@theme {\n' + lines.join('\n') + '\n}\n' : '@theme {\n}\n';
        },

        /**
         * Return the current theme CSS string from the editor.
         *
         * @return {string}
         */
        getThemeCss: function () {
            return this.editor ? this.editor.getValue() : '@theme {\n}\n';
        },

        /**
         * Return the currently selected theme ID.
         *
         * @return {number|null}
         */
        getCurrentThemeId: function () {
            return this.currentThemeId();
        },

        /**
         * Refresh the CodeMirror editor layout.
         * Creates the editor if it hasn't been initialized yet (deferred init).
         */
        refresh: function () {
            if (this.editor) {
                this.editor.refresh();
            } else {
                this._tryCreateEditor();
            }
        },

        /**
         * Show the add theme modal.
         */
        addTheme: function () {
            this.showAddModal(true);
        },

        /**
         * Confirm creation of a new theme.
         */
        confirmAdd: function () {
            var self = this,
                name = $.trim(this.newThemeName()),
                defaultCss = '@theme {\n  /* Add Tailwind v4 theme variables here, e.g.: */\n  /* --color-primary: #131CCF; */\n}\n';

            if (!name) {
                return;
            }

            this._ajax(this.urls.themeSave, {
                name: name,
                theme_css: defaultCss,
                store_id: this.storeId
            }, 'POST').done(function (res) {
                if (res.success) {
                    self.closeAddModal();
                    self.loadThemes();
                } else {
                    self._showNotification(res.message || $t('Failed to create theme.'), 'error');
                }
            }).fail(function () {
                self._showNotification($t('Failed to create theme. Please try again.'), 'error');
            });
        },

        /**
         * Close the add theme modal and reset the input.
         */
        closeAddModal: function () {
            this.showAddModal(false);
            this.newThemeName('');
        },

        /**
         * Delete the currently selected theme after confirmation.
         */
        deleteTheme: function () {
            var self = this,
                themeId = this.currentThemeId();

            if (!themeId) {
                return;
            }

            var currentTheme = this.themes().find(function (t) {
                return t.theme_id === themeId;
            });

            if (currentTheme && currentTheme.is_default) {
                return;
            }

            if (!confirm($.mage.__('Delete this theme permanently?'))) {
                return;
            }

            this._ajax(this.urls.themeDelete, {
                theme_id: themeId
            }, 'POST').done(function (res) {
                if (res.success) {
                    self.currentThemeId(null);
                    self.loadThemes();
                }
            });
        },

        /**
         * Show the import theme modal.
         */
        importTheme: function () {
            this.showImportModal(true);
        },

        /**
         * Handle file input change for theme import.
         *
         * @param {Object} vm
         * @param {Event} event
         */
        onImportFileSelect: function (vm, event) {
            var files = event.target.files;

            this.importFile(files && files[0] ? files[0] : null);
        },

        /**
         * Confirm import of the selected theme file.
         */
        confirmImport: function () {
            var self = this,
                file = this.importFile();

            if (!file) {
                return;
            }

            var formData = new FormData();

            formData.append('import_file', file);
            formData.append('form_key', this.formKey);
            formData.append('store_id', this.storeId);

            $.ajax({
                url: this.urls.themeImport,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (res) {
                if (res.success) {
                    self.closeImportModal();
                    self.loadThemes();
                } else {
                    self._showNotification(res.message || $t('Failed to import theme.'), 'error');
                }
            }).fail(function () {
                self._showNotification($t('Failed to import theme. Please try again.'), 'error');
            });
        },

        /**
         * Close the import theme modal.
         */
        closeImportModal: function () {
            this.showImportModal(false);
            this.importFile(null);
        },

        /**
         * Export the currently selected theme by redirecting to the export URL.
         */
        exportTheme: function () {
            var themeId = this.currentThemeId();

            if (!themeId) {
                return;
            }

            window.location.href = this.urls.themeExport +
                '?theme_id=' + themeId +
                '&form_key=' + this.formKey;
        },

        /**
         * Auto-save the current theme to the server and fire a themeChange trigger.
         */
        _autoSaveTheme: function () {
            var self = this,
                themeId = this.currentThemeId();

            if (!themeId) {
                return;
            }

            this._ajax(this.urls.themeSave, {
                theme_id: themeId,
                theme_css: this.getThemeCss(),
                store_id: this.storeId
            }, 'POST').done(function (res) {
                if (res.success) {
                    $('body').trigger('themeChange');
                } else {
                    self._showNotification(res.message || $t('Failed to save theme.'), 'error');
                }
            }).fail(function () {
                self._showNotification($t('Failed to save theme. Please try again.'), 'error');
            });
        },

        /**
         * Show a notification message to the user.
         *
         * @param {string} message
         * @param {string} type
         */
        _showNotification: function (message, type) {
            require(['Magento_Ui/js/lib/view/utils/async'], function () {
                var container = document.querySelector('.page-main-actions') || document.querySelector('.page-content'),
                    existingNotification = document.querySelector('.email-editor-notification'),
                    notification;

                if (existingNotification) {
                    existingNotification.remove();
                }

                if (!container) {
                    return;
                }

                notification = document.createElement('div');
                notification.className = 'email-editor-notification message message-' + (type === 'error' ? 'error' : 'success');
                notification.textContent = message;
                notification.style.cssText = 'margin: 10px 0; padding: 10px 15px;';
                container.insertBefore(notification, container.firstChild);

                setTimeout(function () {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 8000);
            });
        },

        /**
         * Perform an AJAX request with form_key and store_id injected.
         *
         * @param {string} url
         * @param {Object} [data]
         * @param {string} [method]
         * @return {Object}
         */
        _ajax: function (url, data, method) {
            data = data || {};
            data.form_key = this.formKey;
            data.store_id = this.storeId;

            return $.ajax({
                url: url,
                type: method || 'GET',
                data: data,
                dataType: 'json'
            });
        }
    });
});
