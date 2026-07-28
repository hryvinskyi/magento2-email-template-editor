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
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/theme-editor',
            urls: window.emailEditorConfig && window.emailEditorConfig.urls || {},
            formKey: window.emailEditorConfig && window.emailEditorConfig.formKey || '',
            storeId: window.emailEditorConfig && window.emailEditorConfig.storeId || 0,
            themes: [],
            scopeOptions: [],
            currentThemeId: null,
            currentThemeScope: 0,
            showImportModal: false,
            showAddModal: false,
            newThemeName: '',
            importFile: null,
            editor: null
        },

        /** @type {number|null} */
        _autoSaveTimer: null,

        /**
         * Monotonic generation of theme-load requests.
         *
         * A response may only be applied if it is still the newest request of its kind.
         * This one guards a write into the editor itself, so an overtaken response that
         * was allowed through would not merely show the wrong theme - it would replace
         * whatever the admin has typed since.
         *
         * @type {number}
         */
        _themeLoadToken: 0,

        /** @type {jQuery.jqXHR|null} The theme-load request currently on the wire. */
        _themeLoadXhr: null,

        /** @type {HTMLElement|null} */
        _editorElement: null,

        /** @type {string|null} */
        _pendingValue: null,

        /**
         * Set while the component writes a whole document into CodeMirror itself.
         *
         * CodeMirror reports a programmatic setValue exactly like typing, and this
         * component's save is silent - there is no indicator anywhere in its template -
         * so without this flag the theme would be saved as a side effect of merely
         * being rendered.
         *
         * @type {boolean}
         */
        _applyingRemoteValue: false,

        /**
         * Set while the component writes a store scope that came from the server.
         *
         * The scope select cannot tell an admin's choice apart from a value the component
         * loaded, and a choice is what sends the change to the server. Every write that is
         * not a choice therefore goes through _applyStoredScope() and is ignored here.
         *
         * @type {boolean}
         */
        _applyingStoredScope: false,

        /**
         * Initialize the theme editor component.
         *
         * @return {Object}
         */
        initialize: function () {
            var self = this;

            this._super();

            this.observe([
                'themes',
                'scopeOptions',
                'currentThemeId',
                'currentThemeScope',
                'showImportModal',
                'showAddModal',
                'newThemeName',
                'importFile'
            ]);

            this.scopeOptions(this._buildScopeOptions());

            this.currentThemeScope.subscribe(function (storeId) {
                if (self._applyingStoredScope) {
                    return;
                }

                self.changeScope(parseInt(storeId, 10) || 0);
            });

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
                    if (self._applyingRemoteValue) {
                        // Every programmatic write replaces the whole document, so an
                        // autosave still pending for the previous one is owed to content
                        // that no longer exists - drop it instead of letting it fire and
                        // save the document that just replaced it.
                        clearTimeout(self._autoSaveTimer);
                        self._autoSaveTimer = null;

                        return;
                    }

                    clearTimeout(self._autoSaveTimer);

                    self._autoSaveTimer = setTimeout(function () {
                        self._autoSaveTheme();
                    }, 1000);
                });

                if (self._pendingValue !== null) {
                    self._applyingRemoteValue = true;

                    try {
                        self.editor.setValue(self._pendingValue);
                        self.editor.clearHistory();
                        self._pendingValue = null;
                    } finally {
                        self._applyingRemoteValue = false;
                    }
                }
            });
        },

        /**
         * Load the list of available themes from the server.
         */
        loadThemes: function () {
            var self = this;

            this._ajax(this.urls.themeLoadList).done(function (res) {
                if (!res.success || !res.themes) {
                    self._showNotification(res.message || $t('Failed to load themes.'), 'error');

                    return;
                }

                self.themes(res.themes);

                if (self.currentThemeId() === null) {
                    if (res.themes.length > 0) {
                        var defaultTheme = res.themes.find(function (t) {
                            return t.is_default;
                        });

                        self.selectTheme(defaultTheme || res.themes[0]);
                    }
                } else {
                    // The list that just arrived is the authority on where the theme
                    // already on screen is scoped.
                    self._applyStoredScope(self._storedScopeOf(self.currentThemeId()));
                }
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus)) {
                    return;
                }

                self._showNotification($t('Failed to load themes. Please try again.'), 'error');
            });
        },

        /**
         * Select a theme and load its JSON into the editor.
         *
         * @param {Object} themeData
         */
        selectTheme: function (themeData) {
            var self = this,
                themeId = themeData.theme_id,
                token;

            if (this.currentThemeId() && this.currentThemeId() !== themeId && this.editor && this.editor.historySize().undo > 0) {
                this._autoSaveTheme();

                // The outgoing theme has just been sent, so nothing is owed. Leaving the
                // timer armed would let it fire after the incoming theme is loaded and
                // save that one instead.
                clearTimeout(this._autoSaveTimer);
                this._autoSaveTimer = null;
            }

            // Claim the newest generation before cancelling, so the request being dropped
            // here can tell that a newer theme took its place rather than treating its own
            // cancellation as a fault worth reporting.
            token = ++this._themeLoadToken;

            if (this._themeLoadXhr && this._themeLoadXhr.readyState !== 4) {
                this._themeLoadXhr.abort();
            }

            this._themeLoadXhr = this._ajax(this.urls.themeLoad, {theme_id: themeId}).done(function (res) {
                // Cancelling is best effort - a response already on the wire still
                // arrives. This is the check that actually keeps a superseded theme out
                // of the editor, and it has to sit in front of the write and not merely
                // in front of the observables: setValue replaces the whole document.
                if (token !== self._themeLoadToken) {
                    return;
                }

                if (!res.success || !res.theme) {
                    self._showNotification(res.message || $t('Failed to load the theme.'), 'error');

                    return;
                }

                // The stored value is a Tailwind v4 CSS-first theme. Rows that pre-date the
                // storage migration still hold the legacy JSON shape - normalize so the
                // editor always shows v4 CSS regardless of which form is on disk.
                var content = self._normalizeThemeForEditor(res.theme.theme_css);

                self.currentThemeId(themeId);
                self._applyStoredScope(parseInt(themeData.store_id, 10) || 0);

                if (self.editor) {
                    self._applyingRemoteValue = true;

                    try {
                        self.editor.setValue(content);
                        self.editor.clearHistory();
                    } finally {
                        self._applyingRemoteValue = false;
                    }
                } else {
                    self._pendingValue = content;
                    self._tryCreateEditor();
                }
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus) || token !== self._themeLoadToken) {
                    return;
                }

                self._showNotification($t('Failed to load the theme. Please try again.'), 'error');
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
                theme_css: defaultCss
            }, 'POST').done(function (res) {
                if (res.success) {
                    self.closeAddModal();
                    self.loadThemes();
                } else {
                    self._showNotification(res.message || $t('Failed to create theme.'), 'error');
                }
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus)) {
                    return;
                }

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
                if (!res.success) {
                    self._showNotification(res.message || $t('Failed to delete the theme.'), 'error');

                    return;
                }

                self.currentThemeId(null);
                self.loadThemes();
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus)) {
                    return;
                }

                self._showNotification($t('Failed to delete the theme. Please try again.'), 'error');
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
            formData.append('store_id', this._currentStoreId());

            // A multipart upload cannot go through _ajax, which serialises a plain object,
            // so the request is built here and handed to the editor directly - being the
            // odd one out is no reason to be the one request nothing on screen reports.
            this._track($.ajax({
                url: this.urls.themeImport,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            })).done(function (res) {
                if (res.success) {
                    self.closeImportModal();
                    self.loadThemes();
                } else {
                    self._showNotification(res.message || $t('Failed to import theme.'), 'error');
                }
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus)) {
                    return;
                }

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
         * Move the currently selected theme to another store scope.
         *
         * The scope is an invariant of the theme rather than part of what is being edited, so
         * it travels on its own request and under its own parameter - the store view chosen in
         * the toolbar rides along on every request and means something else entirely.
         *
         * @param {number} storeId
         */
        changeScope: function (storeId) {
            var self = this,
                themeId = this.currentThemeId(),
                previousScope;

            if (!themeId) {
                return;
            }

            previousScope = this._storedScopeOf(themeId);

            this._ajax(this.urls.themeChangeScope, {
                theme_id: themeId,
                target_store_id: storeId
            }, 'POST').done(function (res) {
                if (res.success && res.theme) {
                    self._rememberScope(themeId, res.theme.store_id);
                    self._applyStoredScope(parseInt(res.theme.store_id, 10) || 0);
                    self._showNotification(res.message || $t('Theme scope updated.'), 'success');
                } else {
                    self._showNotification(res.message || $t('Failed to change the theme scope.'), 'error');

                    // The server kept the theme where it was, so the control must go back to
                    // showing that rather than a scope nothing was moved to.
                    self._applyStoredScope(previousScope);
                }
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus)) {
                    // Cancelled, so whether the server moved the theme is unknown. Saying
                    // either thing here would risk being wrong; the next theme list load
                    // reports the scope the server actually holds.
                    return;
                }

                self._showNotification($t('Failed to change the theme scope. Please try again.'), 'error');
                self._applyStoredScope(previousScope);
            });
        },

        /**
         * Store views a theme can be scoped to, the global scope always among them.
         *
         * The list is asked of the orchestrator, which owns the store switcher. It is not in the
         * global configuration object the page writes - that carries only the addresses, the form
         * key and the store id - so reading it from there yielded an empty list and left this
         * control offering the global scope and nothing else. Returning a theme to the global scope
         * is the only way to undo one scoped to a single store view by mistake, so that entry is
         * supplied here on the installations whose list omits it.
         *
         * @return {Array}
         * @private
         */
        _buildScopeOptions: function () {
            var editor = parentResolver.peek(this),
                stores = (editor && typeof editor.getStores === 'function' ? editor.getStores() : []).slice(),
                hasGlobal = stores.some(function (store) {
                    return parseInt(store.id, 10) === 0;
                });

            if (!hasGlobal) {
                stores.unshift({id: 0, name: $t('All Store Views')});
            }

            return stores;
        },

        /**
         * Return the store scope the server last reported for a theme.
         *
         * @param {number} themeId
         * @return {number}
         * @private
         */
        _storedScopeOf: function (themeId) {
            var theme = this.themes().find(function (t) {
                return t.theme_id === themeId;
            });

            return theme ? parseInt(theme.store_id, 10) || 0 : 0;
        },

        /**
         * Record a scope the server has confirmed against the theme in the loaded list.
         *
         * @param {number} themeId
         * @param {number} storeId
         * @private
         */
        _rememberScope: function (themeId, storeId) {
            var theme = this.themes().find(function (t) {
                return t.theme_id === themeId;
            });

            if (theme) {
                theme.store_id = parseInt(storeId, 10) || 0;
            }
        },

        /**
         * Show a store scope that came from the server, without sending it back.
         *
         * @param {number} storeId
         * @private
         */
        _applyStoredScope: function (storeId) {
            this._applyingStoredScope = true;

            try {
                this.currentThemeScope(storeId);
            } finally {
                this._applyingStoredScope = false;
            }
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
                theme_css: this.getThemeCss()
            }, 'POST').done(function (res) {
                if (res.success) {
                    $('body').trigger('themeChange');
                } else {
                    self._showNotification(res.message || $t('Failed to save theme.'), 'error');
                }
            }).fail(function (xhr, textStatus) {
                if (failureReporter.isAbort(textStatus)) {
                    return;
                }

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
         * Resolve the store scope the next outbound request must carry.
         *
         * The orchestrator owns the store view selection; this component only asks for
         * it, and asks again on every request. It must not be captured once: the toolbar
         * switcher changes it at any time, and a remembered store view may be applied
         * shortly after page load without any user action at all.
         *
         * Returns the store id baked into the page config while the orchestrator has not
         * registered yet. That window is harmless: of the endpoints this component calls,
         * only save and import read the scope, and both are user-triggered long after
         * registration.
         *
         * @return {number}
         * @private
         */
        _currentStoreId: function () {
            var parent = parentResolver.peek(this);

            if (parent && typeof parent.getEffectiveStoreId === 'function') {
                return parent.getEffectiveStoreId();
            }

            return this.storeId;
        },

        /**
         * Perform an AJAX request with form_key and the current store scope injected.
         *
         * Returns the jQuery promise of the request itself, synchronously, so callers can
         * chain .done/.fail on it.
         *
         * @param {string} url
         * @param {Object} [data]
         * @param {string} [method]
         * @return {Object}
         */
        _ajax: function (url, data, method) {
            data = data || {};
            data.form_key = this.formKey;
            data.store_id = this._currentStoreId();

            return this._track($.ajax({
                url: url,
                type: method || 'GET',
                data: data,
                dataType: 'json'
            }));
        },

        /**
         * Hand a request to the editor so it counts towards the busy state the whole
         * screen shares and can be reached by its cancellation sweep.
         *
         * The very same jqXHR comes back, so callers keep chaining .done/.fail on the
         * request itself. While the editor is not registered yet the request simply goes
         * out untracked: invisible is far better than not sent at all, and that window
         * closes during page load.
         *
         * @param {jQuery.jqXHR} xhr
         * @return {jQuery.jqXHR}
         * @private
         */
        _track: function (xhr) {
            var editor = parentResolver.peek(this);

            return editor && typeof editor.trackRequest === 'function'
                ? editor.trackRequest(xhr)
                : xhr;
        },

        /**
         * @inheritDoc
         */
        destroy: function () {
            if (this._autoSaveTimer) {
                clearTimeout(this._autoSaveTimer);
                this._autoSaveTimer = null;
            }

            this._super();
        }
    });
});
