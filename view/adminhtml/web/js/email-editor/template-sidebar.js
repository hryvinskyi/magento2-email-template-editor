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
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/template-sidebar',
            urls: window.emailEditorConfig && window.emailEditorConfig.urls || {},
            formKey: window.emailEditorConfig && window.emailEditorConfig.formKey || ''
        },

        /**
         * Monotonic generation of template-list requests.
         *
         * A response may only be applied if it is still the newest request of its kind:
         * the tree belongs to the store view that is selected now, not to whichever
         * response happens to come back last.
         *
         * @type {number}
         */
        _listToken: 0,

        /** @type {jQuery.jqXHR|null} The template-list request currently on the wire. */
        _listXhr: null,

        /**
         * Initialize component, set up observables and computed properties.
         *
         * @return {Object}
         */
        initialize: function () {
            this._super();

            this.observe(['searchQuery', 'groups', 'activeId', 'activeOverrideId', 'activeLegacyId', 'filterMode']);

            this.searchQuery('');
            this.groups([]);
            this.activeId('');
            this.activeOverrideId(null);
            this.activeLegacyId(null);
            this.filterMode('all');

            this.expandedGroups = ko.observable({});
            this.expandedTemplates = ko.observable({});
            this.currentStoreId = ko.observable(0);

            this.filteredGroups = ko.computed(function () {
                var query = (this.searchQuery() || '').toLowerCase(),
                    allGroups = this.groups(),
                    filter = this.filterMode(),
                    storeId = this.currentStoreId(),
                    self = this;

                return allGroups.reduce(function (result, group) {
                    var matchingTemplates = group.templates.filter(function (tpl) {
                        var matchesSearch = true,
                            matchesFilter = true,
                            hasOverrides = self._hasOverrideForStore(tpl, storeId);

                        if (query) {
                            matchesSearch = (tpl.label && tpl.label.toLowerCase().indexOf(query) !== -1) ||
                                (tpl.id && tpl.id.toLowerCase().indexOf(query) !== -1);
                        }

                        // The tree always lists every template so any of them can be
                        // selected to create a new override for the chosen store. The
                        // "Customized"/"Default" chips narrow this to templates that are
                        // (or are not) overridden for the selected store specifically.
                        if (filter === 'customized') {
                            matchesFilter = hasOverrides;
                        } else if (filter === 'defaults') {
                            matchesFilter = !hasOverrides;
                        }

                        return matchesSearch && matchesFilter;
                    }).map(function (tpl) {
                        // Show only the selected store's own overrides; the store-0
                        // ("All Store Views") fallback is hidden under a specific store.
                        // Then collapse a published override and its working draft into a
                        // single row carrying a "draft pending" badge.
                        return self._mergeOverridesForDisplay(self._scopeTemplateOverrides(tpl, storeId));
                    });

                    if (matchingTemplates.length > 0) {
                        result.push({
                            key: group.key,
                            label: group.label,
                            templates: matchingTemplates
                        });
                    }

                    return result;
                }, []);
            }, this);

            this.templateCount = ko.computed(function () {
                var groups = this.groups(),
                    storeId = this.currentStoreId(),
                    self = this,
                    total = 0,
                    customized = 0;

                groups.forEach(function (group) {
                    group.templates.forEach(function (tpl) {
                        total++;

                        // "Customized" counts templates overridden for the selected
                        // store specifically; "All" stays the full template count.
                        if (self._hasOverrideForStore(tpl, storeId)) {
                            customized++;
                        }
                    });
                });

                return {total: total, customized: customized, defaults: total - customized};
            }, this);

            return this;
        },

        /**
         * Whether a template has an override applicable to the given store scope.
         *
         * For a specific store view only its own overrides count ("overridden for
         * this store"); the store-0 ("All Store Views") fallback that the server
         * also returns is intentionally excluded. For the "All Store Views" scope
         * (0) any override counts.
         *
         * @param {Object} tpl
         * @param {number} storeId
         * @return {boolean}
         */
        _hasOverrideForStore: function (tpl, storeId) {
            var overrides = tpl.overrides || [];

            if (storeId === 0) {
                return overrides.length > 0;
            }

            return overrides.some(function (override) {
                if (override.source === 'legacy') {
                    var ids = override.store_ids || [];

                    // store 0 in the bindings means "applies to all stores".
                    return ids.indexOf(0) !== -1 || ids.indexOf(storeId) !== -1;
                }

                return parseInt(override.store_id, 10) === storeId;
            });
        },

        /**
         * Return a copy of the template whose overrides are limited to the selected
         * store. Under a specific store view the store-0 ("All Store Views") fallback
         * the server also returns is removed, so the tree (expander, status dot and
         * the override rows) reflects only overrides that belong to that store.
         *
         * @param {Object} tpl
         * @param {number} storeId
         * @return {Object}
         */
        _scopeTemplateOverrides: function (tpl, storeId) {
            if (storeId === 0) {
                return tpl;
            }

            var scoped = $.extend({}, tpl);

            scoped.overrides = (tpl.overrides || []).filter(function (override) {
                if (override.source === 'legacy') {
                    var ids = override.store_ids || [];

                    return ids.indexOf(0) !== -1 || ids.indexOf(storeId) !== -1;
                }

                return parseInt(override.store_id, 10) === storeId;
            });

            return scoped;
        },

        /**
         * Collapse a published override and its working draft into a single tree row.
         *
         * A live published override and an unpublished draft of the same customization
         * are one thing in two states. This attaches the draft to the published entry as
         * `pending_draft` and drops the standalone draft row, so the tree shows one row
         * (with a "draft pending" badge) instead of two siblings. Templates with only a
         * draft, scheduled entries, and any additional drafts are left untouched.
         *
         * @param {Object} tpl
         * @return {Object}
         */
        _mergeOverridesForDisplay: function (tpl) {
            var overrides = tpl.overrides || [],
                published = null,
                draft = null,
                merged,
                i;

            for (i = 0; i < overrides.length; i++) {
                if (overrides[i].source === 'legacy') {
                    continue;
                }

                if (published === null
                    && overrides[i].status === 'published'
                    && !overrides[i].active_from
                    && !overrides[i].active_to
                ) {
                    published = overrides[i];
                }
            }

            if (published === null) {
                return tpl;
            }

            // Pair with the most recent draft (highest entity_id), matching the server's
            // notion of the working draft; any older drafts stay as their own rows.
            for (i = 0; i < overrides.length; i++) {
                if (overrides[i].source === 'legacy') {
                    continue;
                }

                if (overrides[i].status === 'draft'
                    && parseInt(overrides[i].store_id, 10) === parseInt(published.store_id, 10)
                    && (draft === null
                        || parseInt(overrides[i].entity_id, 10) > parseInt(draft.entity_id, 10))
                ) {
                    draft = overrides[i];
                }
            }

            if (draft === null) {
                return tpl;
            }

            merged = $.extend({}, tpl);
            merged.overrides = overrides
                .filter(function (override) {
                    return override !== draft;
                })
                .map(function (override) {
                    return override === published
                        ? $.extend({}, override, {pending_draft: draft})
                        : override;
                });

            return merged;
        },

        /**
         * Set the sidebar filter mode.
         *
         * @param {string} mode
         */
        setFilter: function (mode) {
            this.filterMode(mode);
        },

        /**
         * Clear the search query.
         */
        clearSearch: function () {
            this.searchQuery('');
        },

        /**
         * Load template list from the server and populate groups.
         *
         * The returned promise settles for the request that actually owns the tree, so
         * a caller may restore its own state in `.done()` without checking whether its
         * load was overtaken; a load that loses the race simply never resolves it.
         *
         * @param {number} [storeId] Store view scope; remembered for subsequent refreshes.
         * @return {jQuery.Promise} Resolved with the response that populated the tree.
         */
        load: function (storeId) {
            var deferred = $.Deferred();

            if (storeId !== undefined) {
                this.currentStoreId(parseInt(storeId, 10) || 0);
            }

            // Claim the newest generation before cancelling, so the request being
            // dropped here can recognise that a newer tree took its place - and not
            // mistake itself for a tree that was cancelled for some unrelated reason.
            this._listToken++;

            if (this._listXhr && this._listXhr.readyState !== 4) {
                this._listXhr.abort();
            }

            this._requestList(this._listToken, deferred, true);

            return deferred.promise();
        },

        /**
         * Fire one template-list request for the store view currently on screen.
         *
         * @param {number} token - Generation this request belongs to.
         * @param {jQuery.Deferred} deferred - Settled once this generation is decided.
         * @param {boolean} mayReissue - Whether a cancellation may be answered by asking again.
         * @return {void}
         * @private
         */
        _requestList: function (token, deferred, mayReissue) {
            var self = this;

            this._listXhr = this._ajax(this.urls.loadList, 'GET', {store_id: this.currentStoreId()})
                .done(function (res) {
                    if (token !== self._listToken) {
                        deferred.reject();

                        return;
                    }

                    if (!res.success || !res.templates) {
                        failureReporter.report(
                            self,
                            res.message || $t('The template list could not be loaded.')
                        );
                        deferred.reject(res);

                        return;
                    }

                    var grouped = [];

                    $.each(res.templates, function (groupKey, templates) {
                        grouped.push({
                            key: groupKey,
                            label: groupKey,
                            templates: templates
                        });
                    });

                    self.groups(grouped);
                    deferred.resolve(res);
                })
                .fail(function (xhr, textStatus) {
                    if (token !== self._listToken) {
                        deferred.reject();

                        return;
                    }

                    if (failureReporter.isAbort(textStatus)) {
                        // Cancelled while still the newest request, so it was not
                        // superseded by another tree - opening a template cancels
                        // everything outstanding, and the store view on screen would be
                        // left with no tree at all. Ask once more; a second cancellation
                        // is accepted so this can never chase itself.
                        if (mayReissue) {
                            self._requestList(token, deferred, false);
                        } else {
                            deferred.reject();
                        }

                        return;
                    }

                    failureReporter.report(
                        self,
                        $t('The template list could not be loaded. Please try again.'),
                        textStatus
                    );
                    deferred.reject();
                });
        },

        /**
         * Select a template by identifier, expand its parent group, and fire event.
         *
         * @param {string} identifier
         */
        select: function (identifier) {
            var groups = this.groups(),
                i, j;

            this.activeId(identifier);

            for (i = 0; i < groups.length; i++) {
                for (j = 0; j < groups[i].templates.length; j++) {
                    if (groups[i].templates[j].id === identifier) {
                        this._expandGroup(groups[i].key);
                        this.trigger('templateSelect', identifier);

                        return;
                    }
                }
            }

            this.trigger('templateSelect', identifier);
        },

        /**
         * Select a template from its data object.
         *
         * @param {Object} templateData
         */
        selectTemplate: function (templateData) {
            this.activeOverrideId(null);
            this.activeLegacyId(null);
            this.select(templateData.id);
        },

        /**
         * Select an override entry and fire overrideSelect event.
         *
         * @param {Object} overrideData
         * @param {Object} templateData
         */
        selectOverride: function (overrideData, templateData) {
            this.activeId(templateData.id);

            if (overrideData.source === 'legacy') {
                this.activeOverrideId(null);
                this.activeLegacyId(parseInt(overrideData.legacy_id, 10));
            } else {
                this.activeLegacyId(null);
                this.activeOverrideId(overrideData.entity_id);
            }

            this.trigger('overrideSelect', {
                override: overrideData,
                template: templateData
            });
        },

        /**
         * Select the editable face of a tree override row: the attached pending draft
         * when present (so editing continues on the draft), otherwise the override itself.
         *
         * @param {Object} overrideData
         * @param {Object} templateData
         */
        selectOverrideEntry: function (overrideData, templateData) {
            this.selectOverride(overrideData.pending_draft || overrideData, templateData);
        },

        /**
         * Whether a tree override row is the active selection. A merged row is active
         * when either its published entry or its attached pending draft is selected.
         *
         * @param {Object} overrideData
         * @return {boolean}
         */
        isOverrideRowActive: function (overrideData) {
            if (overrideData.source === 'legacy') {
                var activeLegacy = this.activeLegacyId();

                return activeLegacy !== null
                    && activeLegacy !== undefined
                    && parseInt(overrideData.legacy_id, 10) === activeLegacy;
            }

            var active = this.activeOverrideId();

            if (active === null || active === undefined) {
                return false;
            }

            if (overrideData.entity_id === active) {
                return true;
            }

            return !!overrideData.pending_draft && overrideData.pending_draft.entity_id === active;
        },

        /**
         * Toggle the expanded state of a group.
         *
         * @param {Object} groupData
         */
        toggleGroup: function (groupData) {
            var map = this.expandedGroups(),
                key = groupData.key;

            map[key] = !map[key];
            this.expandedGroups(Object.assign({}, map));
        },

        /**
         * Check whether a group is currently expanded.
         *
         * @param {string} key
         * @return {boolean}
         */
        isGroupExpanded: function (key) {
            return ko.unwrap(this.expandedGroups())[key] === true;
        },

        /**
         * Check whether a group should be rendered open.
         *
         * While a search query is active every matching group auto-expands so the
         * results are visible without manual clicks; otherwise the manual
         * expand/collapse state is used.
         *
         * @param {string} key
         * @return {boolean}
         */
        isGroupOpen: function (key) {
            if (this.searchQuery()) {
                return true;
            }

            return this.isGroupExpanded(key);
        },

        /**
         * Toggle the expanded state of a template.
         *
         * @param {Object} templateData
         */
        toggleTemplate: function (templateData) {
            var map = this.expandedTemplates(),
                id = templateData.id;

            map[id] = !map[id];
            this.expandedTemplates(Object.assign({}, map));
        },

        /**
         * Check whether a template is currently expanded.
         *
         * @param {string} id
         * @return {boolean}
         */
        isTemplateExpanded: function (id) {
            return ko.unwrap(this.expandedTemplates())[id] === true;
        },

        /**
         * Reload the sidebar data from the server while preserving
         * the currently expanded groups, templates, and active selection.
         *
         * @return {jQuery.Promise}
         */
        refresh: function () {
            var self = this,
                currentActiveId = this.activeId(),
                currentActiveOverrideId = this.activeOverrideId(),
                currentActiveLegacyId = this.activeLegacyId(),
                currentExpandedGroups = Object.assign({}, this.expandedGroups()),
                currentExpandedTemplates = Object.assign({}, this.expandedTemplates());

            return this.load().done(function () {
                self.expandedGroups(currentExpandedGroups);
                self.expandedTemplates(currentExpandedTemplates);

                if (currentActiveId) {
                    self.activeId(currentActiveId);
                }

                if (currentActiveOverrideId) {
                    self.activeOverrideId(currentActiveOverrideId);
                }

                if (currentActiveLegacyId) {
                    self.activeLegacyId(currentActiveLegacyId);
                }
            });
        },

        /**
         * Reflect that a template does or does not have a working draft.
         *
         * This runs after every draft save, the two-second autosave included, so it must
         * not go to the server for something that is already on screen. Saving an
         * existing draft over and over changes no row in the tree; only gaining a first
         * draft or losing the last one adds or removes one, and only that is worth a
         * reload. A template the tree has never heard of is reloaded too, because there
         * is nothing to compare against.
         *
         * @param {string} identifier
         * @param {boolean} hasDraft
         * @return {void}
         */
        markDraft: function (identifier, hasDraft) {
            var template = this._findTemplate(identifier);

            if (template && this._hasDraft(template) === !!hasDraft) {
                return;
            }

            this.refresh();
        },

        /**
         * Find a template node in the loaded tree by its identifier.
         *
         * @param {string} identifier
         * @return {Object|null}
         * @private
         */
        _findTemplate: function (identifier) {
            var groups = this.groups(),
                i, j;

            for (i = 0; i < groups.length; i++) {
                for (j = 0; j < groups[i].templates.length; j++) {
                    if (groups[i].templates[j].id === identifier) {
                        return groups[i].templates[j];
                    }
                }
            }

            return null;
        },

        /**
         * Whether the loaded tree already carries a draft for a template.
         *
         * The server scopes the list it returns to the selected store view and its
         * fallback, so any draft present here is one the tree already knows about for
         * the scope on screen. Legacy entries are not drafts and never count.
         *
         * @param {Object} tpl
         * @return {boolean}
         * @private
         */
        _hasDraft: function (tpl) {
            return (tpl.overrides || []).some(function (override) {
                return override.source !== 'legacy' && override.status === 'draft';
            });
        },

        /**
         * Rename an override entry by prompting the user for a new name.
         *
         * @param {Object} overrideData
         */
        renameOverride: function (overrideData) {
            var newName = prompt('Enter new name:', overrideData.label || ''),
                self = this;

            if (newName === null || newName === '') {
                return;
            }

            this._ajax(this.urls.renameDraft, 'POST', {
                entity_id: overrideData.entity_id,
                draft_name: newName
            }).done(function (res) {
                if (res && res.success === false) {
                    failureReporter.report(self, res.message || $t('The override could not be renamed.'));

                    return;
                }

                self.refresh();
            }).fail(function (xhr, textStatus) {
                failureReporter.report(
                    self,
                    $t('The override could not be renamed. Please try again.'),
                    textStatus
                );
            });
        },

        /**
         * Delete an override entry after user confirmation.
         *
         * @param {Object} overrideData
         */
        deleteOverride: function (overrideData) {
            var self = this;

            this.trigger('confirmAction', {
                title: $.mage.__('Delete Override'),
                message: $.mage.__('Are you sure you want to delete this override? This action cannot be undone.'),
                detail: (overrideData.label || '') + ' (' + overrideData.status + ')',
                actionLabel: $.mage.__('Delete'),
                type: 'danger',
                onConfirm: function () {
                    self._ajax(self.urls.deleteDraft, 'POST', {
                        entity_id: overrideData.entity_id
                    }).done(function (res) {
                        if (res && res.success === false) {
                            failureReporter.report(
                                self,
                                res.message || $t('The override could not be deleted.')
                            );

                            return;
                        }

                        if (self.activeOverrideId() === overrideData.entity_id) {
                            self.activeOverrideId(null);
                        }

                        self.refresh();
                    }).fail(function (xhr, textStatus) {
                        failureReporter.report(
                            self,
                            $t('The override could not be deleted. Please try again.'),
                            textStatus
                        );
                    });
                }
            });
        },

        /**
         * Toggle the active state of a published override.
         *
         * @param {Object} overrideData
         */
        toggleActive: function (overrideData) {
            var self = this;

            this._ajax(this.urls.toggleActive, 'POST', {
                entity_id: overrideData.entity_id
            }).done(function (res) {
                if (!res.success) {
                    failureReporter.report(
                        self,
                        res.message || $t('The override could not be switched on or off.')
                    );

                    return;
                }

                self.refresh();
            }).fail(function (xhr, textStatus) {
                failureReporter.report(
                    self,
                    $t('The override could not be switched on or off. Please try again.'),
                    textStatus
                );
            });
        },

        /**
         * Fire event to edit the schedule of a published override.
         *
         * @param {Object} overrideData
         * @param {Object} templateData
         */
        editSchedule: function (overrideData, templateData) {
            this.trigger('editSchedule', {
                override: overrideData,
                template: templateData
            });
        },

        /**
         * Fire event to create a new draft for a template without changing active selection.
         *
         * @param {Object} templateData
         */
        createDraftFor: function (templateData) {
            this.activeId(templateData.id);
            this.trigger('createDraft', templateData.id);
        },

        /**
         * Format a date string into a short readable format (e.g. "Jan 15, 2026").
         *
         * @param {string} dateStr
         * @return {string}
         */
        formatDate: function (dateStr) {
            if (!dateStr) {
                return '';
            }

            try {
                var d = new Date(dateStr.replace(/-/g, '/')),
                    months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                              'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

                if (isNaN(d.getTime())) {
                    return dateStr;
                }

                return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
            } catch (e) {
                return dateStr;
            }
        },

        /**
         * Expand a template's children without toggling.
         *
         * @param {string} id
         */
        expandTemplate: function (id) {
            var map = this.expandedTemplates();

            if (!map[id]) {
                map[id] = true;
                this.expandedTemplates(Object.assign({}, map));
            }
        },

        /**
         * Expand a group by key without toggling.
         *
         * @param {string} key
         */
        _expandGroup: function (key) {
            var map = this.expandedGroups();

            if (!map[key]) {
                map[key] = true;
                this.expandedGroups(Object.assign({}, map));
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
