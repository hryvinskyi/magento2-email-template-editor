/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The panel that offers the variables an author can insert.
 *
 * An adapter: it opens, it asks, it binds, and it says which row was clicked. What a group is, what
 * a search matches and how many rows there are is decided in the groups module, where it can be
 * proved without a browser.
 *
 * Three things about the request are load-bearing rather than incidental. It is handed to the editor
 * so that it counts towards the one busy state the screen shares and can be reached by the
 * cancellation sweep. It carries a generation, claimed before anything is cancelled, so that the
 * answer for the store view the author has already switched away from cannot overwrite the newer
 * list - the store switcher is the reason this panel reloads at all, so a superseded answer is the
 * normal case rather than an exotic one. And a failure is said out loud, because a panel left
 * spinning tells an author nothing about what to do next.
 *
 * A group is remembered as collapsed by its code, never by its label. The label is translated, and a
 * map keyed on it would lose every collapsed group the day the admin locale changed.
 */
define([
    'uiComponent',
    'ko',
    'jquery',
    'mage/translate',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/failure-reporter',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/variable-groups'
], function (Component, ko, $, $t, parentResolver, failureReporter, variableGroups) {
    'use strict';

    /** @type {number} */
    var MAX_RECENT = 5,

        /** @type {string} */
        STORAGE_KEY = 'ete_recent_variables';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/variable-chooser',
            urls: window.emailEditorConfig && window.emailEditorConfig.urls || {},
            formKey: window.emailEditorConfig && window.emailEditorConfig.formKey || '',
            isOpen: false,
            searchQuery: '',
            groups: [],
            isLoading: false,
            recentCollapsed: false,

            /** @type {number} Generation guarding the group list against a superseded answer */
            _groupsToken: 0
        },

        /**
         * @inheritDoc
         */
        initialize: function () {
            var self = this;

            this._super();

            this.observe([
                'isOpen',
                'searchQuery',
                'groups',
                'isLoading',
                'recentVariables',
                'recentCollapsed'
            ]);

            this._loaded = false;
            this._templateId = '';
            this._storeId = 0;
            this._groupsXhr = null;
            this._expandedGroupsMap = ko.observable({});

            this.recentVariables(this._loadRecent());

            this.filteredGroups = ko.computed(function () {
                return variableGroups.filter(self.groups(), self.searchQuery());
            });

            this.totalCount = ko.computed(function () {
                return variableGroups.countVariables(self.groups());
            });

            return this;
        },

        /**
         * Toggle the variable chooser panel open or closed.
         */
        toggle: function () {
            if (this.isOpen()) {
                this.close();
            } else {
                this.open();
            }
        },

        /**
         * Open the variable chooser panel. Loads groups on first open or when template changes.
         *
         * @param {string} [templateId]
         * @param {number} [storeId]
         */
        open: function (templateId, storeId) {
            var needsReload = !this._loaded;

            if (templateId !== undefined && templateId !== this._templateId) {
                this._templateId = templateId;
                needsReload = true;
            }

            if (storeId !== undefined && storeId !== this._storeId) {
                this._storeId = storeId;
                needsReload = true;
            }

            this.isOpen(true);
            this.searchQuery('');

            if (needsReload) {
                this.loadGroups(this._templateId, this._storeId);
            }
        },

        /**
         * Close the variable chooser panel.
         */
        close: function () {
            this.isOpen(false);
        },

        /**
         * Clear the search query.
         */
        clearSearch: function () {
            this.searchQuery('');
        },

        /**
         * Toggle group collapsed state.
         *
         * @param {Object} group
         */
        toggleGroup: function (group) {
            var key = group.code,
                map = this._expandedGroupsMap(),
                updated = {};

            Object.keys(map).forEach(function (k) {
                updated[k] = map[k];
            });

            updated[key] = !this.isGroupExpanded(group);
            this._expandedGroupsMap(updated);
        },

        /**
         * Check whether a group is expanded.
         *
         * @param {Object} group
         * @return {boolean}
         */
        isGroupExpanded: function (group) {
            return this._expandedGroupsMap()[group.code] !== false;
        },

        /**
         * Load variable groups from the server via AJAX.
         *
         * @param {string} [templateId]
         * @param {number} [storeId]
         * @return {void}
         */
        loadGroups: function (templateId, storeId) {
            var self = this,
                editor = parentResolver.peek(this),
                url = this.urls.variableLoadGroups,
                data = {form_key: this.formKey},
                token,
                xhr;

            // Claimed before anything is cancelled. Cancelling is best effort - an answer already on
            // the wire still arrives - so it is this generation, not the abort, that decides which
            // answer is allowed to become the list.
            token = ++this._groupsToken;

            if (this._groupsXhr && this._groupsXhr.readyState !== 4) {
                this._groupsXhr.abort();
            }

            this._groupsXhr = null;

            if (!url) {
                this.isLoading(false);
                failureReporter.report(this, $t('This editor has no address for loading variables.'));

                return;
            }

            if (templateId) {
                data.template_id = templateId;
            }

            if (storeId !== undefined) {
                data.store_id = storeId;
            }

            this.isLoading(true);

            xhr = $.ajax({
                url: url,
                type: 'GET',
                data: data,
                dataType: 'json'
            });

            this._groupsXhr = editor && typeof editor.trackRequest === 'function'
                ? editor.trackRequest(xhr)
                : xhr;

            this._groupsXhr.done(function (res) {
                if (token !== self._groupsToken) {
                    return;
                }

                if (res && res.success === false) {
                    failureReporter.report(self, res.message || $t('The variables could not be loaded.'));

                    return;
                }

                self.groups(variableGroups.normalise(res && res.groups));
                self._loaded = true;
            }).fail(function (jqXhr, textStatus) {
                // A cancelled load is not a fault: it was superseded by the store view the author
                // switched to, and the answer for that one is already on its way.
                if (textStatus === 'abort' || token !== self._groupsToken) {
                    return;
                }

                failureReporter.report(
                    self,
                    $t('The variables could not be loaded. Please try again.'),
                    textStatus
                );
            }).always(function () {
                if (token === self._groupsToken) {
                    self.isLoading(false);
                }
            });
        },

        /**
         * Handle a variable click: insert it and track in recent list.
         *
         * @param {Object} variable
         */
        onVariableClick: function (variable) {
            this._addRecent(variable);
            this.trigger('insertVariable', variable.value);
        },

        /**
         * Ask for a row to be explained, without inserting it.
         *
         * Explaining and inserting are two different intentions and the row offers both: an author
         * who is not sure a variable is the right one has no way to find out if reading about it
         * costs them an edit to undo. Nothing goes into the recently used list either, because the
         * variable has not been used.
         *
         * @param {Object} variable
         * @return {void}
         */
        onVariableInfoClick: function (variable) {
            if (!variable.reference) {
                return;
            }

            this.trigger('describeVariable', variable.reference);
        },

        /**
         * Load recently used variables from localStorage.
         *
         * @return {Array}
         */
        _loadRecent: function () {
            var stored;

            try {
                stored = localStorage.getItem(STORAGE_KEY);

                return stored ? JSON.parse(stored) : [];
            } catch (e) {
                return [];
            }
        },

        /**
         * Add a variable to the recently used list and persist.
         *
         * The reference is kept with the row so that a recently used variable can be explained as
         * readily as one picked out of its group. A row remembered before rows carried references
         * simply carries none, and offers no explanation rather than a broken one.
         *
         * @param {Object} variable
         */
        _addRecent: function (variable) {
            var recent = this.recentVariables().slice(),
                existing = -1,
                i;

            for (i = 0; i < recent.length; i++) {
                if (recent[i].value === variable.value) {
                    existing = i;
                    break;
                }
            }

            if (existing !== -1) {
                recent.splice(existing, 1);
            }

            recent.unshift({
                value: variable.value,
                label: variable.label || '',
                reference: variable.reference || ''
            });

            if (recent.length > MAX_RECENT) {
                recent = recent.slice(0, MAX_RECENT);
            }

            this.recentVariables(recent);

            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(recent));
            } catch (e) {
                // storage full or unavailable
            }
        }
    });
});
