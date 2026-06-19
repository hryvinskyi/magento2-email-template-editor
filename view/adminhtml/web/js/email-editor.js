/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'underscore',
    'uiComponent',
    'ko',
    'jquery',
    'uiRegistry',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/tailwind-compiler',
    'Magento_Ui/js/modal/alert'
], function (_, Component, ko, $, registry, tailwindCompiler, uiAlert) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/email-editor',
            urls: {},
            formKey: '',
            storeId: 0,
            stores: [],
            selectedTemplate: '',
            isEnabled: true
        },

        /** @type {number|null} */
        _previewDebounceTimer: null,

        /** @type {number|null} */
        _entitySearchTimer: null,

        /** @type {string} */
        _lastTailwindCss: '',

        /** @type {Object} */
        _providerEntitySelectionMap: {},

        /** @type {string|null} */
        _currentEntityId: null,

        /** @type {number|null} */
        _workingDraftEntityId: null,

        /** @type {number|null} */
        _currentOverrideStoreId: null,

        /** @type {string|null} */
        _customDataIdentifier: null,

        /** @type {boolean} */
        _suppressChangeEvents: false,

        /** @type {Array<jQuery.jqXHR>} */
        _pendingRequests: [],

        /** @type {number} Monotonic generation guarding preview rendering against stale responses */
        _previewToken: 0,

        /** @type {string} */
        _storeStorageKey: 'hryvinskyi_email_editor_store_id',

        /**
         * Initialize the email editor orchestrator, create observables,
         * child components, subscriptions, and load initial data.
         *
         * @return {Object}
         */
        initialize: function () {
            this._super();

            this.observe([
                'isInitialLoading',
                'currentTemplateId',
                'currentTemplateStatus',
                'hasDraft',
                'hasPublished',
                'isOverrideActive',
                'subject',
                'statusText',
                'statusCssClass',
                'statusBarText',
                'showDraftBadge',
                'showScheduleBadge',
                'showExpiredBadge',
                'tailwindCssOutput',
                'showCustomData',
                'selectedProvider',
                'providers',
                'dataSourceLabel',
                'showEntitySearch',
                'entitySearchQuery',
                'entityResults',
                'selectedEntityId',
                'sidebarCollapsed',
                'templateCollapsed',
                'cssCollapsed',
                'themeCollapsed',
                'tailwindCollapsed',
                'scheduleCollapsed',
                'customDataCollapsed',
                'storeId',
                'showEditScheduleModal',
                'editScheduleFrom',
                'editScheduleTo',
                'editScheduleOverrideLabel',
                'viewingDefault'
            ]);

            this.isInitialLoading(true);
            this.currentTemplateId('');
            this.currentTemplateStatus('');
            this.hasDraft(false);
            this.hasPublished(false);
            this.isOverrideActive(true);
            this.subject('');
            this.statusText('READY');
            this.statusCssClass('ete-status ete-status-ready');
            this.statusBarText('Editor initialized');
            this.showDraftBadge(false);
            this.showScheduleBadge(false);
            this.showExpiredBadge(false);
            this.tailwindCssOutput('No Tailwind CSS generated yet.');
            this.showCustomData(false);
            this.selectedProvider('mock');
            this.providers([]);
            this.dataSourceLabel('Data Source');
            this.showEntitySearch(false);
            this.entitySearchQuery('');
            this.entityResults([]);
            this.selectedEntityId('');
            this.sidebarCollapsed(false);
            this.templateCollapsed(false);
            this.cssCollapsed(true);
            this.themeCollapsed(true);
            this.tailwindCollapsed(true);
            this.scheduleCollapsed(true);
            this.customDataCollapsed(false);
            this.showEditScheduleModal(false);
            this.editScheduleFrom('');
            this.editScheduleTo('');
            this.editScheduleOverrideLabel('');
            this.viewingDefault(false);

            // Editing is locked while the published (live) version is shown alongside an
            // existing working draft: edits here would otherwise fork into and overwrite
            // that draft, so the editor is switched to read-only until the user returns
            // to the draft via "Back to draft".
            var self = this;

            this.isReadOnly = ko.computed(function () {
                return self.currentTemplateStatus() === 'published' && self.hasDraft();
            });

            this.isReadOnly.subscribe(function () {
                self.applyReadOnlyState();
            });

            this._editScheduleEntityId = null;

            this.observe([
                'confirmModalVisible',
                'confirmModalTitle',
                'confirmModalMessage',
                'confirmModalDetail',
                'confirmModalAction',
                'confirmModalType',
                'sendTestEmailVisible',
                'sendTestEmailAddress',
                'sendTestEmailFeedback',
                'sendTestEmailHasError',
                'sendTestEmailSending'
            ]);

            this.sendTestEmailVisible(false);
            this.sendTestEmailAddress('');
            this.sendTestEmailFeedback('');
            this.sendTestEmailHasError(false);
            this.sendTestEmailSending(false);

            this.confirmModalVisible(false);
            this.confirmModalTitle('');
            this.confirmModalMessage('');
            this.confirmModalDetail('');
            this.confirmModalAction('');
            this.confirmModalType('danger');
            this._confirmCallback = null;

            this._restoreStoreId();
            this._initSubscriptions();
            this._initKeyboardShortcuts();
            this._loadInitialData();

            return this;
        },

        /**
         * Subscribe to child component events and observable changes
         * to wire up inter-component communication.
         */
        _initSubscriptions: function () {
            var self = this;

            registry.get(this.name + '.templateSidebar', function (sidebar) {
                var debouncedLoadTemplate = _.debounce(function (identifier, entityId) {
                    self.loadTemplate(identifier, entityId);
                }, 300);

                self.templateSidebar = sidebar;

                sidebar.on('templateSelect', function (identifier) {
                    self.viewingDefault(true);
                    debouncedLoadTemplate(identifier);
                });

                sidebar.on('overrideSelect', function (data) {
                    self.viewingDefault(false);
                    debouncedLoadTemplate(
                        data.template.id,
                        data.override.entity_id
                    );
                });

                sidebar.on('createDraft', function (identifier) {
                    self.createNewDraft(identifier);
                });

                sidebar.on('editSchedule', function (data) {
                    self.openEditScheduleModal(data.override, data.template);
                });

                sidebar.on('confirmAction', function (params) {
                    self.showConfirm(params);
                });
            });

            registry.get(this.name + '.templateEditor', function (editor) {
                self.templateEditor = editor;

                editor.on('contentChange', function () {
                    self.onContentChange();
                });

                // Sync the editor with the current read-only state in case it
                // finished wiring up after the state was already set.
                self.applyReadOnlyState();
            });

            registry.get(this.name + '.customCssEditor', function (cssEditor) {
                self.customCssEditor = cssEditor;
                self.applyReadOnlyState();

                self.cssCollapsed.subscribe(function (collapsed) {
                    if (!collapsed) {
                        setTimeout(function () {
                            cssEditor.refresh();
                        }, 50);
                    }
                });
            });

            registry.get(this.name + '.customDataEditor', function (dataEditor) {
                self.customDataEditor = dataEditor;

                dataEditor.on('customDataChange', function () {
                    self.onCustomDataChange();
                });

                self.showCustomData.subscribe(function (visible) {
                    if (visible) {
                        setTimeout(function () {
                            dataEditor.refresh();
                        }, 50);
                    }
                });

                self.customDataCollapsed.subscribe(function (collapsed) {
                    if (!collapsed) {
                        setTimeout(function () {
                            dataEditor.refresh();
                        }, 50);
                    }
                });
            });

            registry.get(this.name + '.themeEditor', function (themeEditor) {
                self.themeEditor = themeEditor;

                self.themeCollapsed.subscribe(function (collapsed) {
                    if (!collapsed) {
                        setTimeout(function () {
                            themeEditor.refresh();
                        }, 50);
                    }
                });

                $('body').on('themeChange', function () {
                    self.schedulePreview();
                });
            });

            registry.get(this.name + '.previewPanel', function (preview) {
                self.previewPanel = preview;
            });

            registry.get(this.name + '.schedulePanel', function (schedule) {
                self.schedulePanel = schedule;

                schedule.on('scheduleChange', function (data) {
                    self._editScheduleEntityId = self._currentEntityId || null;
                    self.editScheduleFrom(data.active_from || '');
                    self.editScheduleTo(data.active_to || '');
                    self.applyEditSchedule();
                });
            });

            registry.get(this.name + '.publishDialog', function (dialog) {
                self.publishDialog = dialog;
            });

            registry.get(this.name + '.versionHistory', function (history) {
                self.versionHistory = history;

                history.on('historyPreviewStart', function () {
                    if (self.previewPanel) {
                        self.previewPanel.showLoading();
                    }
                });

                history.on('historyPreview', function (res) {
                    if (self.previewPanel) {
                        self.previewPanel.hideLoading();

                        if (res.success) {
                            self.previewPanel.setContent(res.html);
                        }
                    }
                });

                history.on('historyRestore', function (res) {
                    if (res.success) {
                        if (res.content !== undefined && self.templateEditor) {
                            self.templateEditor.setValue(res.content);
                        }

                        if (res.subject !== undefined) {
                            self.subject(res.subject || '');
                        }

                        self.currentTemplateStatus('draft');
                        self.hasDraft(true);
                        self._currentEntityId = res.entity_id || self._currentEntityId;
                        self.updateBadges();
                        self.setStatus('modified', 'REVERTED');

                        if (self.templateSidebar) {
                            self.templateSidebar.refresh();
                        }

                        self.schedulePreview();

                        setTimeout(function () {
                            self.setStatus('ready');
                        }, 2000);
                    }
                });

                history.on('historyClose', function () {
                    self.renderPreview();
                });

                history.on('confirmAction', function (params) {
                    self.showConfirm(params);
                });
            });

            registry.get(this.name + '.variableChooser', function (chooser) {
                self.variableChooser = chooser;

                chooser.on('insertVariable', function (variableValue) {
                    if (self.templateEditor) {
                        self.templateEditor.insertAtCursor(variableValue);
                    }
                });
            });

            registry.get(this.name + '.draftManager', function (manager) {
                self.draftManager = manager;
                // The draft manager calls back into the editor to auto-save and to
                // resolve the saved-status label; without this reference both no-op.
                manager.parentComponent = self;
            });

            registry.get(this.name + '.draftListPanel', function (draftList) {
                self.draftListPanel = draftList;

                draftList.on('draftSelect', function (draftData) {
                    self.loadTemplate(
                        self.currentTemplateId(),
                        draftData.entity_id
                    );
                });

                draftList.on('draftCreate', function () {
                    self.createNewDraft(self.currentTemplateId());
                });

                draftList.on('confirmAction', function (params) {
                    self.showConfirm(params);
                });
            });

            registry.get(this.name + '.moreMenu', function (menu) {
                self.moreMenu = menu;

                menu.on('menuAction', function (action) {
                    switch (action) {
                        case 'previewInNewTab':
                            self.previewInNewTab();
                            break;
                        case 'openVersionHistory':
                            self.openVersionHistory();
                            break;
                        case 'deleteDraft':
                            self.discardDraft();
                            break;
                        case 'resetTemplate':
                            self.resetTemplate();
                            break;
                    }
                });
            });

            this.subject.subscribe(function () {
                if (!self._suppressChangeEvents) {
                    self.onContentChange();
                }
            });

            this.selectedProvider.subscribe(function () {
                self._updateProviderUI();

                if (!self._suppressChangeEvents) {
                    self.schedulePreview();
                }
            });

            this.entitySearchQuery.subscribe(function (query) {
                self.selectedEntityId('');

                if (self._entitySearchTimer) {
                    clearTimeout(self._entitySearchTimer);
                }

                self._entitySearchTimer = setTimeout(function () {
                    self.searchEntities(query);
                }, 400);
            });

            this.storeId.subscribe(function () {
                // Remember the chosen store view so it is preselected after a reload.
                self._persistStoreId();

                // Store-view scope decides which override (and which themed default) applies,
                // so rebuild the template tree for the new store and reload the open template.
                // Both fall back to the default scope server-side; loadTemplate re-previews.
                if (self.templateSidebar) {
                    self.templateSidebar.load(self.getEffectiveStoreId());
                }

                if (self.currentTemplateId()) {
                    if (!self.viewingDefault()) {
                        self._currentEntityId = null;
                    }
                    self.reloadTemplate();
                } else {
                    self.renderPreview();
                }
            });
        },

        /**
         * Bind global keyboard shortcuts for the editor.
         */
        _initKeyboardShortcuts: function () {
            var self = this;

            this._keyboardHandler = function (e) {
                var tag = (e.target.tagName || '').toLowerCase(),
                    isInput = tag === 'input' || tag === 'textarea' || tag === 'select',
                    isCtrl = e.ctrlKey || e.metaKey;

                // Escape — close any open modal/panel
                if (e.key === 'Escape') {
                    if (self.confirmModalVisible()) {
                        self.cancelConfirm();
                        e.preventDefault();

                        return;
                    }

                    if (self.sendTestEmailVisible()) {
                        self.closeSendTestEmailDialog();
                        e.preventDefault();

                        return;
                    }

                    if (self.publishDialog && self.publishDialog.isVisible()) {
                        self.publishDialog.close();
                        e.preventDefault();

                        return;
                    }

                    if (self.versionHistory && self.versionHistory.isVisible()) {
                        self.versionHistory.close();
                        e.preventDefault();

                        return;
                    }

                    if (self.showEditScheduleModal()) {
                        self.closeEditScheduleModal();
                        e.preventDefault();

                        return;
                    }

                    if (self.variableChooser && self.variableChooser.isOpen && self.variableChooser.isOpen()) {
                        self.variableChooser.close();
                        e.preventDefault();

                        return;
                    }

                    if (self.moreMenu && self.moreMenu.isOpen()) {
                        self.moreMenu.close();
                        e.preventDefault();

                        return;
                    }

                    return;
                }

                // Ctrl+S — Save Draft
                if (isCtrl && e.key === 's' && !e.shiftKey) {
                    e.preventDefault();

                    if (self.currentTemplateId()) {
                        self.saveDraft();
                    }

                    return;
                }

                // Ctrl+Shift+P — Publish
                if (isCtrl && e.shiftKey && (e.key === 'p' || e.key === 'P')) {
                    e.preventDefault();

                    if (self.currentTemplateId()) {
                        self.openPublishDialog();
                    }

                    return;
                }

                // Ctrl+Shift+E — Send Test Email
                if (isCtrl && e.shiftKey && (e.key === 'e' || e.key === 'E')) {
                    e.preventDefault();

                    if (self.currentTemplateId()) {
                        self.openSendTestEmailDialog();
                    }

                    return;
                }

                // Ctrl+Enter — Refresh Preview (works even in inputs)
                if (isCtrl && e.key === 'Enter') {
                    e.preventDefault();
                    self.renderPreview();

                    return;
                }

                // Ctrl+Shift+H — Version History
                if (isCtrl && e.shiftKey && (e.key === 'h' || e.key === 'H')) {
                    e.preventDefault();
                    self.openVersionHistory();

                    return;
                }
            };

            $(document).on('keydown.eteShortcuts', this._keyboardHandler);
        },

        /**
         * Load initial data: sidebar templates and sample data providers.
         */
        _loadInitialData: function () {
            var self = this;

            this.loadSampleDataProviders();
            tailwindCompiler.init();

            registry.get(this.name + '.templateSidebar', function (sidebar) {
                sidebar.load(self.getEffectiveStoreId()).done(function () {
                    self.isInitialLoading(false);

                    if (self.selectedTemplate) {
                        sidebar.select(self.selectedTemplate);
                    }
                }).fail(function () {
                    self.isInitialLoading(false);
                });
            });
        },

        /**
         * Set schedule dates, resolving the panel via registry if needed.
         *
         * @param {string} from
         * @param {string} to
         */
        _setScheduleDates: function (from, to) {
            var self = this;

            if (this.schedulePanel) {
                this.schedulePanel.setDates(from, to);

                return;
            }

            registry.get(this.name + '.schedulePanel', function (panel) {
                self.schedulePanel = panel;
                panel.setDates(from, to);
            });
        },

        /**
         * Set the status indicator text and CSS class.
         *
         * @param {string} status
         * @param {string} [text]
         */
        setStatus: function (status, text) {
            this.statusCssClass('ete-status ete-status-' + status);
            this.statusText(text || status.toUpperCase());
        },

        /**
         * Update the draft, schedule, and expired badges based on current state.
         */
        updateBadges: function () {
            var scheduleStatus = this.schedulePanel
                ? this.schedulePanel.getStatus()
                : 'none';

            this.showDraftBadge(
                this.currentTemplateStatus() === 'draft' || this.hasDraft()
            );
            this.showScheduleBadge(scheduleStatus === 'scheduled');
            this.showExpiredBadge(scheduleStatus === 'expired');
        },

        /**
         * Get the effective store ID from the store view observable.
         *
         * @return {number}
         */
        getEffectiveStoreId: function () {
            return parseInt(this.storeId(), 10) || 0;
        },

        /**
         * Reveal the editor-panel scrollbar briefly while scrolling.
         *
         * The bar is hidden by default and otherwise only appears on hover; the class is
         * dropped after a short idle window so it fades back out once scrolling stops.
         *
         * @param {Object} data
         * @param {Event} event
         * @return {boolean}
         */
        handlePanelLeftScroll: function (data, event) {
            var el = event.currentTarget;

            el.classList.add('ete-scrolling');

            if (this._panelLeftScrollTimer) {
                clearTimeout(this._panelLeftScrollTimer);
            }

            this._panelLeftScrollTimer = setTimeout(function () {
                el.classList.remove('ete-scrolling');
            }, 800);

            return true;
        },

        /**
         * Restore the last used store view from local storage.
         *
         * An explicit store passed in the URL (reflected as a non-zero initial
         * value) always wins; otherwise the remembered store is preselected,
         * provided it still exists in the current store list. The effective
         * store is then persisted so URL-driven selections are remembered too.
         */
        _restoreStoreId: function () {
            var saved = this._readStoredStoreId();

            if (this.getEffectiveStoreId() === 0 && saved !== null && this._storeExists(saved)) {
                this.storeId(saved);
            }

            this._persistStoreId();
        },

        /**
         * Read the persisted store ID from local storage.
         *
         * @return {number|null}
         */
        _readStoredStoreId: function () {
            try {
                var raw = window.localStorage.getItem(this._storeStorageKey),
                    value;

                if (raw === null || raw === '') {
                    return null;
                }

                value = parseInt(raw, 10);

                return isNaN(value) ? null : value;
            } catch (e) {
                return null;
            }
        },

        /**
         * Persist the current effective store ID to local storage.
         */
        _persistStoreId: function () {
            try {
                window.localStorage.setItem(this._storeStorageKey, String(this.getEffectiveStoreId()));
            } catch (e) {
                // Local storage unavailable (private mode / disabled) — ignore.
            }
        },

        /**
         * Check whether a store ID exists in the available store list.
         *
         * @param {number} storeId
         * @return {boolean}
         */
        _storeExists: function (storeId) {
            return (this.stores || []).some(function (store) {
                return parseInt(store.id, 10) === storeId;
            });
        },

        /**
         * Perform an AJAX request with form_key and store_id injected.
         *
         * @param {string} url
         * @param {Object} [data]
         * @param {string} [method]
         * @return {jQuery.Deferred}
         */
        _ajax: function (url, data, method) {
            var self = this,
                xhr;

            data = data || {};
            data.form_key = this.formKey;
            data.store_id = this.getEffectiveStoreId();

            xhr = $.ajax({
                url: url,
                type: method || 'GET',
                data: data,
                dataType: 'json',
                cache: false
            });

            this._pendingRequests.push(xhr);

            xhr.always(function () {
                var idx = self._pendingRequests.indexOf(xhr);

                if (idx !== -1) {
                    self._pendingRequests.splice(idx, 1);
                }
            });

            return xhr;
        },

        /**
         * Abort all in-flight AJAX requests.
         */
        _abortPendingRequests: function () {
            var pending = this._pendingRequests.slice();

            this._pendingRequests = [];

            pending.forEach(function (xhr) {
                if (xhr && xhr.readyState !== 4) {
                    xhr.abort();
                }
            });
        },

        /**
         * Load the list of available sample data providers from the server
         * and populate the providers observable, then resolve which provider is
         * selected for this template.
         *
         * @param {string} [templateIdentifier]
         * @param {string} [preferredProvider] - Provider to restore (the override's saved
         *        provider); falls back to the primary provider when absent or unavailable.
         * @return {jQuery.Deferred}
         */
        loadSampleDataProviders: function (templateIdentifier, preferredProvider) {
            var self = this,
                data = {};

            if (templateIdentifier) {
                data.template_identifier = templateIdentifier;
            }

            return this._ajax(this.urls.sampleDataLoadList, data).done(function (res) {
                var items = [];

                if (res.success && res.providers) {
                    $.each(res.providers, function (i, p) {
                        items.push({
                            code: p.code,
                            label: p.label
                        });
                        self._providerEntitySelectionMap[p.code] =
                            p.supports_entity_search || false;
                    });
                }

                if (res.data_source_label) {
                    self.dataSourceLabel(res.data_source_label);
                }

                items.push({code: 'custom', label: 'Custom Data'});
                self._providerEntitySelectionMap['custom'] = false;

                self.providers(items);
                self._applyProviderSelection(items, preferredProvider);
            });
        },

        /**
         * Pick the active sample-data provider for the freshly loaded provider list:
         * the override's saved provider when it is still offered, otherwise the primary
         * (first) provider. The change is made without triggering a preview render — the
         * caller renders once afterwards with the final provider — while keeping the
         * dependent provider UI (custom-data / entity-search visibility) in sync.
         *
         * @param {Array<{code: string, label: string}>} items
         * @param {string} [preferredProvider]
         */
        _applyProviderSelection: function (items, preferredProvider) {
            var codes = items.map(function (item) {
                    return item.code;
                }),
                target = '',
                prevSuppress;

            if (preferredProvider && codes.indexOf(preferredProvider) !== -1) {
                target = preferredProvider;
            } else if (items.length > 0) {
                target = items[0].code;
            }

            if (this.selectedProvider() === target) {
                // Value unchanged, so the subscribe will not fire — sync the UI directly.
                this._updateProviderUI();

                return;
            }

            prevSuppress = this._suppressChangeEvents;
            this._suppressChangeEvents = true;
            this.selectedProvider(target);
            this._suppressChangeEvents = prevSuppress;
        },

        /**
         * Update the entity search visibility and custom data visibility
         * based on the currently selected provider.
         */
        _updateProviderUI: function () {
            var providerCode = this.selectedProvider() || '',
                requiresEntity = this._providerEntitySelectionMap[providerCode] || false,
                isCustom = providerCode === 'custom';

            this.showEntitySearch(requiresEntity);
            this.showCustomData(isCustom);

            if (!requiresEntity) {
                this.selectedEntityId('');
                this.entitySearchQuery('');
                this.entityResults([]);
            }
        },

        /**
         * Search for entities matching the given query for the selected provider.
         *
         * @param {string} query
         */
        searchEntities: function (query) {
            var self = this,
                providerCode = this.selectedProvider() || '';

            if (!query || query.length < 2) {
                this.entityResults([]);

                return;
            }

            this._ajax(this.urls.sampleDataSearchEntities, {
                provider_code: providerCode,
                query: query
            }).done(function (res) {
                if (res.success && res.results && res.results.length > 0) {
                    self.entityResults(res.results);
                } else {
                    self.entityResults([{
                        id: '',
                        label: 'No results found'
                    }]);
                }
            });
        },

        /**
         * Select an entity from the search results and trigger a preview.
         *
         * @param {Object} entityData
         */
        selectEntity: function (entityData) {
            if (!entityData.id) {
                this.entityResults([]);

                return;
            }

            this.selectedEntityId(String(entityData.id));
            this.entitySearchQuery(entityData.label);
            this.entityResults([]);
            this.renderPreview();
        },

        /**
         * Load a template by its identifier, optionally loading a specific draft.
         *
         * @param {string} identifier
         * @param {string|number} [entityId]
         */
        loadTemplate: function (identifier, entityId) {
            var self = this,
                requestData;

            if (!identifier) {
                return $.Deferred().reject();
            }

            this._abortPendingRequests();
            this.setStatus('saving', 'LOADING');
            this.currentTemplateId(identifier);
            this._currentEntityId = entityId || null;

            this._setScheduleDates('', '');

            requestData = {
                template_identifier: identifier
            };

            if (entityId) {
                requestData.entity_id = entityId;
            }

            if (this.viewingDefault()) {
                requestData.default_only = 1;
            }

            this._loadRequestId = identifier + ':' + (entityId || '');

            return this._ajax(this.urls.load, requestData).done(function (res) {
                if (self._loadRequestId !== identifier + ':' + (entityId || '')) {
                    return;
                }

                if (res.success && res.template) {
                    var isDefault = self.viewingDefault();
                    // Restore the data source this override was saved with (empty in the
                    // read-only default view, where the primary provider always applies).
                    var savedProvider = isDefault ? '' : (res.template.sample_provider_code || '');
                    var savedCustomVars = isDefault ? '' : (res.template.custom_variables || '');

                    self._suppressChangeEvents = true;

                    var loadedContent = isDefault
                        ? (res.template.default_content || '')
                        : (res.template.content || '');

                    if (self.templateEditor) {
                        self.templateEditor.setValue(loadedContent);
                    }

                    self.subject(
                        isDefault
                            ? (res.template.default_subject || '')
                            : (res.template.subject || '')
                    );

                    if (self.customCssEditor) {
                        self.customCssEditor.setValue(isDefault ? '' : (res.template.custom_css || ''));
                    }

                    if (self.customDataEditor) {
                        if (savedProvider === 'custom' && savedCustomVars !== '') {
                            // This override was saved with the custom provider: restore the
                            // exact sample-data JSON it was saved/published with.
                            self.customDataEditor.setValue(savedCustomVars);
                        } else {
                            // Preserve entered sample values only when staying on the same
                            // template; a different template is rebuilt from its own variables.
                            var previousCustomData = self._customDataIdentifier === identifier
                                ? self.customDataEditor.getValue()
                                : '';

                            self.customDataEditor.setValue(
                                self._buildCustomDataJson(loadedContent, previousCustomData)
                            );
                        }

                        self._customDataIdentifier = identifier;
                    }

                    self.currentTemplateStatus(res.template.status || '');
                    self.hasDraft(res.template.has_draft || false);
                    self.hasPublished(res.template.has_published || false);
                    // Remember the working draft so "Back to draft" can return to it even
                    // while the published (live) version is being viewed.
                    self._workingDraftEntityId = self._pickWorkingDraftId(res.template.drafts);
                    self._publishedEntityId = res.template.published
                        ? res.template.published.entity_id
                        : null;
                    self.isOverrideActive(
                        res.template.published
                            ? res.template.published.is_active !== false
                            : true
                    );
                    self._currentEntityId = isDefault ? null : (res.template.entity_id || null);
                    // Store of the override actually shown (may be 0/default via fallback);
                    // version history is scoped to this, not the selected store view.
                    self._currentOverrideStoreId = res.template.store_id !== undefined
                        ? parseInt(res.template.store_id, 10)
                        : self.getEffectiveStoreId();

                    if (!isDefault && res.template.tailwind_css) {
                        self._lastTailwindCss = res.template.tailwind_css;
                        self.tailwindCssOutput(res.template.tailwind_css);
                    } else if (isDefault) {
                        self._lastTailwindCss = '';
                        self.tailwindCssOutput('');
                    }

                    self._suppressChangeEvents = false;

                    self._setScheduleDates(
                        isDefault ? '' : (res.template.active_from || ''),
                        isDefault ? '' : (res.template.active_to || '')
                    );

                    if (self.draftListPanel && res.template.drafts) {
                        self.draftListPanel.setDrafts(res.template.drafts);

                        if (self._currentEntityId) {
                            self.draftListPanel.activeDraftId(self._currentEntityId);
                        }
                    }

                    self.updateBadges();
                    self.setStatus('ready');

                    if (self.draftManager) {
                        self.draftManager.markClean();
                    }

                    self.statusBarText(
                        isDefault
                            ? 'Default template: ' + identifier
                            : 'Template loaded: ' + identifier
                    );

                    // Resolve this template/override's provider (saved or primary) before
                    // rendering, so the preview is produced once with the correct data
                    // source instead of briefly showing the previously selected one.
                    self.loadSampleDataProviders(identifier, savedProvider).always(function () {
                        self.renderPreview();
                    });
                } else {
                    self.setStatus('error', 'ERROR');
                    self.statusBarText('Failed to load template');
                }
            }).fail(function () {
                self.setStatus('error', 'ERROR');
                self.statusBarText('Failed to load template');
            });
        },

        /**
         * Render the preview. Sends preview request immediately using
         * cached Tailwind CSS, and recompiles Tailwind in the background.
         */
        renderPreview: function () {
            var self = this,
                content = this.templateEditor ? this.templateEditor.getValue() : '',
                token;

            if (!content) {
                return;
            }

            // Tag this render so the eventual preview response can be matched against the
            // latest selection. Fast-switching templates starts a new render each time;
            // only the newest one may touch the preview.
            token = ++this._previewToken;

            if (this.previewPanel) {
                this.previewPanel.showLoading();
            }

            if (this.themeEditor) {
                tailwindCompiler.setTheme(this.themeEditor.getThemeCss());
            }

            // Single-fire: wait for the Tailwind compile before sending the preview AJAX.
            // The old "render twice" path - immediate render with stale `_lastTailwindCss`
            // followed by a second render once the fresh CSS arrived - flickered the
            // preview iframe through an unstyled intermediate state whenever the user
            // typed a class the previous compile didn't know about (e.g. swapping
            // `bg-white` for `bg-black`). Waiting for the compiler removes that flicker;
            // the compile fast-paths when neither content nor theme changed, so the
            // common "edit text, classes unchanged" case still feels instant.
            tailwindCompiler.compile(content).done(function (twCss) {
                if (token !== self._previewToken) {
                    return;
                }

                var twString = twCss || '';
                if (twString !== self._lastTailwindCss) {
                    self._lastTailwindCss = twString;
                    self.tailwindCssOutput(twString);
                }

                self._sendPreviewRequest(content, token);
            });
        },

        /**
         * Send the preview AJAX request to the server.
         *
         * @param {string} content
         * @param {number} [token] - The renderPreview generation that issued this request;
         *                           responses from a superseded generation are discarded.
         * @private
         */
        _sendPreviewRequest: function (content, token) {
            var self = this,
                providerCode = this.selectedProvider() || 'mock',
                data;

            data = {
                template_content: content,
                theme_css: this.themeEditor ? this.themeEditor.getThemeCss() : '',
                custom_css: this.customCssEditor ? this.customCssEditor.getValue() : '',
                tailwind_css: this._lastTailwindCss || '',
                provider_code: providerCode,
                template_identifier: this.currentTemplateId(),
                entity_id: this.selectedEntityId()
            };

            if (providerCode === 'custom') {
                data.custom_variables = this.customDataEditor ? this.customDataEditor.getValue() : '';
            }

            this._ajax(this.urls.preview, data, 'POST').done(function (res) {
                // Drop a response the user has already moved past: a slower or
                // out-of-order preview must not replace the current selection's
                // preview (also silences the abort-triggered fail handler below).
                if (token !== undefined && token !== self._previewToken) {
                    return;
                }

                if (!self.previewPanel) {
                    return;
                }

                self.previewPanel.hideLoading();

                if (res.success) {
                    self.previewPanel.setContent(res.html);
                } else {
                    self.previewPanel.setContent(
                        '<div style="color:#eb5202;padding:20px;">Error: ' +
                        (res.message || 'Unknown error') + '</div>'
                    );
                }
            }).fail(function (xhr, textStatus) {
                // A request we aborted ourselves (switching template fires
                // _abortPendingRequests) is not a real failure — leave the loading
                // overlay up for the render that replaces it instead of flashing an
                // error. Also drop responses already superseded by a newer render.
                if (textStatus === 'abort') {
                    return;
                }

                if (token !== undefined && token !== self._previewToken) {
                    return;
                }

                if (self.previewPanel) {
                    self.previewPanel.hideLoading();
                    self.previewPanel.setContent(
                        '<div style="color:#eb5202;padding:20px;">Failed to render preview.</div>'
                    );
                }
            });
        },

        /**
         * Schedule a debounced preview render after 800ms.
         */
        schedulePreview: function () {
            var self = this;

            if (this._previewDebounceTimer) {
                clearTimeout(this._previewDebounceTimer);
            }

            this._previewDebounceTimer = setTimeout(function () {
                self.renderPreview();
            }, 800);
        },

        /**
         * Handle content changes from template editor, CSS editor, or subject field.
         */
        onContentChange: function () {
            // Ignore changes while loading content or while a read-only version
            // (e.g. the published view) is shown — nothing here may mark the draft
            // dirty or schedule an autosave that would overwrite it.
            if (this._suppressChangeEvents || this.isReadOnly()) {
                return;
            }

            this.setStatus('modified');

            if (this.draftManager) {
                this.draftManager.markDirty();
            }

            this.schedulePreview();
        },

        /**
         * Propagate the current read-only state to the editors so a read-only
         * version (e.g. the published view) cannot be edited.
         */
        applyReadOnlyState: function () {
            var readOnly = this.isReadOnly();

            if (this.templateEditor && typeof this.templateEditor.setReadOnly === 'function') {
                this.templateEditor.setReadOnly(readOnly);
            }

            if (this.customCssEditor && typeof this.customCssEditor.setReadOnly === 'function') {
                this.customCssEditor.setReadOnly(readOnly);
            }
        },

        /**
         * Handle changes to the custom data JSON editor.
         *
         * Custom data is preview-only sample data, so editing it re-renders the
         * preview but does not mark the template as modified.
         */
        onCustomDataChange: function () {
            if (this._suppressChangeEvents) {
                return;
            }

            this.schedulePreview();
        },

        /**
         * Parse the Magento directives in the given content, collect every
         * referenced variable path and build a pretty-printed JSON skeleton.
         *
         * Each "{{...}}" directive is inspected for both the leading variable of
         * "var"/"if"/"depend" directives and any "$object.property" references
         * (e.g. trans parameters such as {{trans "Hi %name" name=$customer.name}}).
         * Variables are resolved as nested objects (e.g. "order.increment_id"
         * becomes {"order": {"increment_id": ""}}). Sample values already entered
         * for variables that are still referenced are preserved.
         *
         * @param {string} content
         * @param {string} existingJson
         * @return {string}
         */
        _buildCustomDataJson: function (content, existingJson) {
            var self = this,
                data = {},
                existing = {},
                directiveRegex = /\{\{(.*?)\}\}/g,
                leadRegex = /^\s*(?:var|if|elseif|depend)\s+([a-zA-Z_][a-zA-Z0-9_.]*)/,
                directive,
                lead;

            try {
                existing = existingJson ? JSON.parse(existingJson) : {};
            } catch (e) {
                existing = {};
            }

            while ((directive = directiveRegex.exec(content || '')) !== null) {
                var body = directive[1],
                    dollarRegex = /\$([a-zA-Z_][a-zA-Z0-9_.]*)/g,
                    dollar;

                lead = body.match(leadRegex);

                if (lead && self._isPlainVariablePath(lead[1])) {
                    self._assignVariablePath(data, lead[1].split('.'));
                }

                while ((dollar = dollarRegex.exec(body)) !== null) {
                    if (body.charAt(dollar.index + dollar[0].length) === '(') {
                        continue;
                    }

                    if (self._isPlainVariablePath(dollar[1])) {
                        self._assignVariablePath(data, dollar[1].split('.'));
                    }
                }
            }

            if (Object.keys(data).length === 0) {
                return existingJson || '';
            }

            this._mergeExistingValues(data, existing);

            return JSON.stringify(data, null, 2);
        },

        /**
         * Determine whether a token is a plain data variable path that can be
         * represented in JSON, excluding method calls and helper objects.
         *
         * @param {string} path
         * @return {boolean}
         */
        _isPlainVariablePath: function (path) {
            if (!path || path.indexOf('(') !== -1 || path.indexOf(' ') !== -1) {
                return false;
            }

            if (path.split('.')[0] === 'this') {
                return false;
            }

            return /^[a-zA-Z_][a-zA-Z0-9_.]*$/.test(path);
        },

        /**
         * Assign an empty placeholder for a dotted variable path, creating nested
         * objects for intermediate segments without overwriting existing branches.
         *
         * @param {Object} target
         * @param {Array<string>} parts
         */
        _assignVariablePath: function (target, parts) {
            var current = target,
                i,
                key;

            for (i = 0; i < parts.length; i++) {
                key = parts[i];

                if (!key) {
                    return;
                }

                if (i === parts.length - 1) {
                    if (current[key] === undefined) {
                        current[key] = '';
                    }
                } else {
                    if (typeof current[key] !== 'object' || current[key] === null) {
                        current[key] = {};
                    }

                    current = current[key];
                }
            }
        },

        /**
         * Copy previously entered sample values onto the freshly extracted skeleton
         * for any variable path that is still referenced by the template.
         *
         * @param {Object} target
         * @param {Object} existing
         */
        _mergeExistingValues: function (target, existing) {
            var self = this;

            if (!target || typeof target !== 'object' || !existing || typeof existing !== 'object') {
                return;
            }

            Object.keys(target).forEach(function (key) {
                if (existing[key] === undefined) {
                    return;
                }

                if (target[key] && typeof target[key] === 'object') {
                    if (existing[key] && typeof existing[key] === 'object') {
                        self._mergeExistingValues(target[key], existing[key]);
                    }
                } else if (typeof existing[key] !== 'object') {
                    target[key] = existing[key];
                }
            });
        },

        /**
         * Collect the current state of all editors into a single data object for saving.
         *
         * @return {Object}
         */
        getSaveData: function () {
            var dates = this.schedulePanel
                    ? this.schedulePanel.getDates()
                    : {active_from: '', active_to: ''},
                providerCode = this.selectedProvider() || '',
                data = {
                    template_identifier: this.currentTemplateId(),
                    template_content: this.templateEditor
                        ? this.templateEditor.getValue()
                        : '',
                    template_subject: this.subject(),
                    custom_css: this.customCssEditor
                        ? this.customCssEditor.getValue()
                        : '',
                    tailwind_css: this._lastTailwindCss || '',
                    theme_id: this.themeEditor
                        ? this.themeEditor.getCurrentThemeId()
                        : '',
                    active_from: dates.active_from,
                    active_to: dates.active_to,
                    entity_id: this._currentEntityId || '',
                    provider_code: providerCode
                };

            // Persist the custom sample-data JSON only for the custom provider, mirroring
            // how the server stores it (cleared for any other provider).
            if (providerCode === 'custom') {
                data.custom_variables = this.customDataEditor ? this.customDataEditor.getValue() : '';
            }

            return data;
        },

        /**
         * Save the current editor state as a draft.
         *
         * Edits are always written to a draft, never to the live published override.
         * When a published template is being edited, the save forks the changes into a
         * working draft and leaves the published version untouched, so nothing reaches
         * customers until an explicit publish. The editor then switches to that draft.
         *
         * @param {boolean} [asNew] - When true, save as a new draft instead of updating the current one.
         */
        saveDraft: function (asNew) {
            var self = this,
                data,
                forkFromPublished;

            // Read-only means the published (live) version is being viewed while a
            // separate working draft exists. Saving here must not touch that draft, so
            // skip entirely — the user returns to the draft to make changes.
            if (!this.currentTemplateId() || this.viewingDefault() || this.isReadOnly()) {
                return;
            }

            // A "published" status means the loaded override is the live one. Saving must
            // not overwrite it in place, so fork the edits into a separate draft instead.
            forkFromPublished = this.currentTemplateStatus() === 'published';

            this.setStatus('saving', 'SAVING DRAFT');

            data = this.getSaveData();
            data.status = 'draft';

            // Drop the published override id (or force a brand-new draft) so the server
            // writes to the working draft rather than the live published row.
            if (asNew || forkFromPublished) {
                delete data.entity_id;
            }

            this._ajax(this.urls.saveDraft, data, 'POST').done(function (res) {
                if (res.success) {
                    self.currentTemplateStatus('draft');
                    self.hasDraft(true);
                    self._currentEntityId = res.entity_id || self._currentEntityId;
                    self.updateBadges();
                    self.setStatus('ready', 'DRAFT SAVED');

                    if (self.templateSidebar) {
                        // Point the tree at the freshly forked draft before refreshing so
                        // the sidebar selection stays in sync with what the editor shows.
                        if (forkFromPublished && res.entity_id) {
                            self.templateSidebar.activeOverrideId(res.entity_id);
                            self.templateSidebar.expandTemplate(self.currentTemplateId());
                        }

                        self.templateSidebar.markDraft(self.currentTemplateId(), true);
                    }

                    if (self.draftManager) {
                        self.draftManager.markClean();
                        self.draftManager.updateSavedTime();
                    }

                    if (self.draftListPanel && res.drafts) {
                        self.draftListPanel.setDrafts(res.drafts);
                    }

                    self.statusBarText('Draft saved');

                    setTimeout(function () {
                        self.setStatus('ready');
                    }, 2000);
                } else {
                    self.setStatus('error', 'ERROR');
                    self.statusBarText(res.message || 'Failed to save draft');
                    uiAlert({
                        title: $.mage.__('Save Failed'),
                        content: res.message || $.mage.__('An error occurred while saving the draft.')
                    });
                }
            }).fail(function () {
                self.setStatus('error', 'ERROR');
                self.statusBarText('Failed to save draft');
                uiAlert({
                    title: $.mage.__('Save Failed'),
                    content: $.mage.__('A network error occurred while saving the draft. Please try again.')
                });
            });
        },

        /**
         * Create a new draft for the given template identifier.
         * Loads the template first, then saves as a brand-new draft with force_new flag.
         *
         * @param {string} identifier
         */
        createNewDraft: function (identifier) {
            var self = this;

            this.loadTemplate(identifier).done(function (res) {
                if (!res || !res.success) {
                    return;
                }

                if (res.template.default_content !== undefined && self.templateEditor) {
                    self.templateEditor.setValue(res.template.default_content || '');
                }

                if (res.template.default_subject !== undefined) {
                    self.subject(res.template.default_subject || '');
                }

                self.setStatus('saving', 'CREATING DRAFT');

                var data = self.getSaveData();

                data.status = 'draft';
                data.force_new = 1;
                data.draft_name = res.template.label || identifier;
                delete data.entity_id;

                self._ajax(self.urls.saveDraft, data, 'POST').done(function (saveRes) {
                    if (saveRes.success) {
                        // The new draft is a real editable override, not the read-only
                        // default that was on screen, so leave default-view mode; otherwise
                        // the toolbar keeps hiding the draft actions (Publish/Discard) until
                        // the draft is manually re-selected in the sidebar.
                        self.viewingDefault(false);
                        self.currentTemplateStatus('draft');
                        self.hasDraft(true);
                        self._currentEntityId = saveRes.entity_id;
                        self.updateBadges();
                        self.setStatus('ready', 'DRAFT CREATED');

                        if (self.templateSidebar) {
                            self.templateSidebar.refresh().done(function () {
                                self.templateSidebar.activeOverrideId(saveRes.entity_id);
                                self.templateSidebar.expandTemplate(identifier);
                            });
                        }

                        if (self.draftManager) {
                            self.draftManager.markClean();
                            self.draftManager.updateSavedTime();
                        }

                        self.statusBarText('New draft created');

                        setTimeout(function () {
                            self.setStatus('ready');
                        }, 2000);
                    } else {
                        self.setStatus('error', 'ERROR');
                        self.statusBarText(saveRes.message || 'Failed to create draft');
                    }
                }).fail(function () {
                    self.setStatus('error', 'ERROR');
                    self.statusBarText('Failed to create draft');
                });
            });
        },

        /**
         * Publish the current template. Saves a draft first, then publishes it.
         *
         * @param {string} [comment]
         */
        publishTemplate: function (comment) {
            var self = this,
                data;

            if (!this.currentTemplateId()) {
                return;
            }

            this.setStatus('saving', 'PUBLISHING');

            data = this.getSaveData();
            data.status = 'draft';

            this._ajax(this.urls.saveDraft, data, 'POST').done(function (saveRes) {
                if (!saveRes.success || !saveRes.entity_id) {
                    self.setStatus('error', 'ERROR');
                    self.statusBarText('Failed to save before publishing');

                    return;
                }

                self._ajax(self.urls.publish, {
                    entity_id: saveRes.entity_id,
                    version_comment: comment || ''
                }, 'POST').done(function (res) {
                    if (res.success) {
                        self.currentTemplateStatus('published');
                        self.hasDraft(false);
                        self.hasPublished(true);
                        self._currentEntityId = res.entity_id || null;
                        self.updateBadges();
                        self.setStatus('ready', 'PUBLISHED');

                        if (self.templateSidebar) {
                            self.templateSidebar.refresh();
                        }

                        if (self.draftManager) {
                            self.draftManager.markClean();
                        }

                        self.statusBarText('Template published successfully');

                        setTimeout(function () {
                            self.setStatus('ready');
                        }, 2000);
                    } else {
                        self.setStatus('error', 'ERROR');
                        self.statusBarText(res.message || 'Publish failed');
                        uiAlert({
                            title: $.mage.__('Publish Failed'),
                            content: res.message || $.mage.__('An error occurred while publishing the template.')
                        });
                    }
                }).fail(function () {
                    self.setStatus('error', 'ERROR');
                    self.statusBarText('Failed to publish template');
                    uiAlert({
                        title: $.mage.__('Publish Failed'),
                        content: $.mage.__('A network error occurred while publishing the template. Please try again.')
                    });
                });
            }).fail(function () {
                self.setStatus('error', 'ERROR');
                self.statusBarText('Failed to save before publishing');
                uiAlert({
                    title: $.mage.__('Publish Failed'),
                    content: $.mage.__('Failed to save the draft before publishing. Please try again.')
                });
            });
        },

        /**
         * Discard the current draft after user confirmation.
         */
        discardDraft: function () {
            var self = this;

            if (!this.currentTemplateId()) {
                return;
            }

            this.showConfirm({
                title: $.mage.__('Discard Draft'),
                message: $.mage.__('Are you sure you want to discard this draft? The published version will remain active.'),
                detail: '<strong>' + self.currentTemplateId() + '</strong>',
                actionLabel: $.mage.__('Discard'),
                type: 'danger',
                onConfirm: function () {
                    self.setStatus('saving', 'DISCARDING');

                    self._ajax(self.urls.deleteDraft, {
                        template_identifier: self.currentTemplateId(),
                        entity_id: self._currentEntityId || ''
                    }, 'POST').done(function (res) {
                        if (res.success) {
                            self.hasDraft(false);
                            self._currentEntityId = null;

                            if (self.templateSidebar) {
                                self.templateSidebar.refresh();
                            }

                            self.statusBarText('Draft discarded');
                            self.loadTemplate(self.currentTemplateId());
                        } else {
                            self.setStatus('error', 'ERROR');
                            self.statusBarText(res.message || 'Failed to discard draft');
                        }
                    }).fail(function () {
                        self.setStatus('error', 'ERROR');
                        self.statusBarText('Failed to discard draft');
                    });
                }
            });
        },

        /**
         * Reset the template to the Magento default after user confirmation.
         */
        resetTemplate: function () {
            var self = this;

            if (!this.currentTemplateId()) {
                return;
            }

            this.showConfirm({
                title: $.mage.__('Reset to Default'),
                message: $.mage.__('This will permanently remove all customizations for this template and revert to the Magento default. This cannot be undone.'),
                detail: '<strong>' + self.currentTemplateId() + '</strong>',
                actionLabel: $.mage.__('Reset to Default'),
                type: 'danger',
                onConfirm: function () {
                    self.setStatus('saving', 'RESETTING');

                    self._ajax(self.urls.reset, {
                        template_identifier: self.currentTemplateId()
                    }, 'POST').done(function (res) {
                        if (res.success) {
                            self.currentTemplateStatus('');
                            self.hasDraft(false);
                            self.hasPublished(false);
                            self._currentEntityId = null;

                            if (self.schedulePanel) {
                                self.schedulePanel.clearDates();
                            }

                            self.updateBadges();

                            if (self.templateSidebar) {
                                self.templateSidebar.refresh();
                            }

                            self.statusBarText('Template reset to default');
                            self.loadTemplate(self.currentTemplateId());
                        } else {
                            self.setStatus('error', 'ERROR');
                            self.statusBarText(res.message || 'Failed to reset template');
                        }
                    }).fail(function () {
                        self.setStatus('error', 'ERROR');
                        self.statusBarText('Failed to reset template');
                    });
                }
            });
        },

        /**
         * Close the current draft and reload the template without draft context.
         */
        closeDraft: function () {
            this._currentEntityId = null;
            this.loadTemplate(this.currentTemplateId());
        },

        /**
         * Open the edit schedule modal for a published override from the sidebar.
         *
         * @param {Object} overrideData
         * @param {Object} templateData
         */
        openEditScheduleModal: function (overrideData, templateData) {
            this._editScheduleEntityId = overrideData.entity_id;
            this.editScheduleFrom(overrideData.active_from || '');
            this.editScheduleTo(overrideData.active_to || '');
            this.editScheduleOverrideLabel(overrideData.label || templateData.label || '');
            this.showEditScheduleModal(true);
            this._initEditScheduleCalendars();
        },

        /**
         * Close the edit schedule modal.
         */
        closeEditScheduleModal: function () {
            this.showEditScheduleModal(false);
            this._editScheduleEntityId = null;
        },

        /**
         * Apply the schedule from the edit schedule modal and save via AJAX.
         */
        applyEditSchedule: function () {
            var self = this,
                $modal = $('.ete-edit-schedule-modal:visible'),
                fromVal = $modal.find('.ete-edit-schedule-from').val(),
                toVal = $modal.find('.ete-edit-schedule-to').val(),
                fromTime, toTime;

            if (fromVal !== undefined) {
                this.editScheduleFrom(fromVal);
            }

            if (toVal !== undefined) {
                this.editScheduleTo(toVal);
            }

            if (this.editScheduleFrom() && this.editScheduleTo()) {
                fromTime = new Date(this.editScheduleFrom().replace(' ', 'T')).getTime();
                toTime = new Date(this.editScheduleTo().replace(' ', 'T')).getTime();

                if (fromTime >= toTime) {
                    uiAlert({
                        title: $.mage.__('Invalid Date Range'),
                        content: $.mage.__('Active From must be before Active To.')
                    });

                    return;
                }
            }

            this.showEditScheduleModal(false);
            this.setStatus('saving', 'UPDATING SCHEDULE');

            this._ajax(this.urls.updateSchedule, {
                entity_id: this._editScheduleEntityId,
                active_from: this.editScheduleFrom(),
                active_to: this.editScheduleTo()
            }, 'POST').done(function (res) {
                if (res.success) {
                    self.setStatus('ready', 'SCHEDULE UPDATED');
                    self.statusBarText('Schedule updated successfully');

                    if (self.templateSidebar) {
                        self.templateSidebar.refresh();
                    }

                    if (self._currentEntityId && String(self._currentEntityId) === String(self._editScheduleEntityId)) {
                        if (self.schedulePanel) {
                            self.schedulePanel.setDates(res.active_from || '', res.active_to || '');
                            self.updateBadges();
                        }
                    }

                    self._editScheduleEntityId = null;

                    setTimeout(function () {
                        self.setStatus('ready');
                    }, 2000);
                } else {
                    self.setStatus('error', 'ERROR');
                    self.statusBarText(res.message || 'Failed to update schedule');
                    uiAlert({
                        title: $.mage.__('Schedule Update Failed'),
                        content: res.message || $.mage.__('Failed to update schedule.')
                    });
                    self._editScheduleEntityId = null;
                }
            }).fail(function () {
                self.setStatus('error', 'ERROR');
                self.statusBarText('Failed to update schedule');
                uiAlert({
                    title: $.mage.__('Error'),
                    content: $.mage.__('Failed to update schedule. Please try again.')
                });
                self._editScheduleEntityId = null;
            });
        },

        /**
         * Remove the schedule from the edit schedule modal and save via AJAX.
         */
        removeEditSchedule: function () {
            this.editScheduleFrom('');
            this.editScheduleTo('');

            var $modal = $('.ete-edit-schedule-modal:visible');

            $modal.find('.ete-edit-schedule-from').val('');
            $modal.find('.ete-edit-schedule-to').val('');
            this.applyEditSchedule();
        },

        /**
         * Initialize calendar widgets on the edit schedule modal inputs.
         */
        _initEditScheduleCalendars: function () {
            var self = this;

            setTimeout(function () {
                var $fromInput = $('.ete-edit-schedule-from:visible'),
                    $toInput = $('.ete-edit-schedule-to:visible'),
                    calendarOpts = {
                        dateFormat: 'yyyy-MM-dd',
                        timeFormat: 'HH:mm:ss',
                        showsTime: true,
                        changeMonth: true,
                        changeYear: true
                    };

                if ($fromInput.length && !$fromInput.data('calendarInitialized')) {
                    $fromInput.calendar(calendarOpts);
                    $fromInput.data('calendarInitialized', true);

                    $fromInput.on('change', function () {
                        self.editScheduleFrom($(this).val());
                    });
                }

                if ($toInput.length && !$toInput.data('calendarInitialized')) {
                    $toInput.calendar(calendarOpts);
                    $toInput.data('calendarInitialized', true);

                    $toInput.on('change', function () {
                        self.editScheduleTo($(this).val());
                    });
                }
            }, 150);
        },

        /**
         * Open the publish dialog with the current template name and store view.
         */
        /**
         * Show a styled confirmation dialog.
         *
         * @param {Object} params
         * @param {string} params.title
         * @param {string} params.message
         * @param {string} [params.detail]
         * @param {string} [params.actionLabel]
         * @param {string} [params.type]
         * @param {Function} params.onConfirm
         */
        showConfirm: function (params) {
            this.confirmModalTitle(params.title || '');
            this.confirmModalMessage(params.message || '');
            this.confirmModalDetail(params.detail || '');
            this.confirmModalAction(params.actionLabel || $.mage.__('Confirm'));
            this.confirmModalType(params.type || 'danger');
            this._confirmCallback = params.onConfirm || null;
            this.confirmModalVisible(true);
        },

        /**
         * Accept the confirmation and execute the callback.
         */
        acceptConfirm: function () {
            var cb = this._confirmCallback;

            this.confirmModalVisible(false);
            this._confirmCallback = null;

            if (typeof cb === 'function') {
                cb();
            }
        },

        /**
         * Cancel the confirmation dialog.
         */
        cancelConfirm: function () {
            this.confirmModalVisible(false);
            this._confirmCallback = null;
        },

        /**
         * Open the send test email dialog.
         */
        openSendTestEmailDialog: function () {
            this.sendTestEmailFeedback('');
            this.sendTestEmailHasError(false);
            this.sendTestEmailSending(false);
            this.sendTestEmailVisible(true);
        },

        /**
         * Close the send test email dialog.
         */
        closeSendTestEmailDialog: function () {
            this.sendTestEmailVisible(false);
            this.sendTestEmailFeedback('');
            this.sendTestEmailHasError(false);
            this.sendTestEmailSending(false);
        },

        /**
         * Handle Enter key in the test email input.
         *
         * @param {Object} data
         * @param {Event} event
         * @return {boolean}
         */
        sendTestEmailKeydown: function (data, event) {
            if (event.key === 'Enter') {
                this.sendTestEmail();

                return false;
            }

            return true;
        },

        /**
         * Send the test email via AJAX.
         */
        sendTestEmail: function () {
            var self = this,
                email = (this.sendTestEmailAddress() || '').trim(),
                content,
                data;

            if (!email) {
                this.sendTestEmailFeedback($.mage.__('Please enter an email address.'));
                this.sendTestEmailHasError(true);

                return;
            }

            content = this.templateEditor ? this.templateEditor.getValue() : '';

            if (!content) {
                this.sendTestEmailFeedback($.mage.__('No template content to send.'));
                this.sendTestEmailHasError(true);

                return;
            }

            this.sendTestEmailSending(true);
            this.sendTestEmailFeedback('');
            this.sendTestEmailHasError(false);

            data = {
                recipient_email: email,
                template_content: content,
                template_subject: this.subject() || '',
                template_identifier: this.currentTemplateId(),
                custom_css: this.customCssEditor ? this.customCssEditor.getValue() : '',
                tailwind_css: this._lastTailwindCss || '',
                provider_code: this.selectedProvider() || 'mock',
                entity_id: this.selectedEntityId ? this.selectedEntityId() : ''
            };

            if ((this.selectedProvider() || 'mock') === 'custom') {
                data.custom_variables = this.customDataEditor ? this.customDataEditor.getValue() : '';
            }

            this._ajax(this.urls.sendTestEmail, data, 'POST').done(function (res) {
                self.sendTestEmailSending(false);

                if (res.success) {
                    self.sendTestEmailFeedback(res.message || $.mage.__('Test email sent successfully.'));
                    self.sendTestEmailHasError(false);
                    self.statusBarText(res.message || 'Test email sent');
                } else {
                    self.sendTestEmailFeedback(res.message || $.mage.__('Failed to send test email.'));
                    self.sendTestEmailHasError(true);
                }
            }).fail(function () {
                self.sendTestEmailSending(false);
                self.sendTestEmailFeedback($.mage.__('Network error. Please try again.'));
                self.sendTestEmailHasError(true);
            });
        },

        /**
         * Toggle the is_active flag on the current published override.
         */
        toggleActiveOverride: function () {
            var self = this,
                published = this._getPublishedEntityId();

            if (!published) {
                return;
            }

            this._ajax(this.urls.toggleActive, {entity_id: published}, 'POST').done(function (res) {
                if (res.success) {
                    self.isOverrideActive(res.is_active);
                    self.statusBarText(res.message || '');

                    if (self.templateSidebar) {
                        self.templateSidebar.refresh();
                    }
                }
            });
        },

        /**
         * Get the entity_id of the published override for the current template.
         *
         * @return {number|null}
         */
        _getPublishedEntityId: function () {
            return this._publishedEntityId || null;
        },

        /**
         * Load and display the published (live) version of the current template
         * so the user can compare it against the draft they are editing.
         */
        viewPublishedVersion: function () {
            var publishedEntityId = this._getPublishedEntityId();

            if (!this.currentTemplateId() || !publishedEntityId) {
                return;
            }

            this.viewingDefault(false);
            this.loadTemplate(this.currentTemplateId(), publishedEntityId);
        },

        /**
         * Switch from the published (live) version back to editing the working draft.
         */
        editWorkingDraft: function () {
            if (!this.currentTemplateId()) {
                return;
            }

            this.viewingDefault(false);
            // Use the known working-draft id when available; otherwise pass no entity id
            // so the server resolves the existing draft for this template/store. The
            // notice only shows while a draft exists, so a draft is always found.
            this.loadTemplate(this.currentTemplateId(), this._workingDraftEntityId || null);
        },

        /**
         * Pick the working draft entity id from the loaded draft override data — the most
         * recent one (highest entity id), matching how the sidebar pairs a draft with its
         * published version.
         *
         * The server serializes drafts as an entity-id-keyed object (collection items are
         * keyed by id), not a sequential array, so this iterates keys rather than relying
         * on Array length/indexing.
         *
         * @param {Object|Array<Object>} drafts
         * @return {number|null}
         */
        _pickWorkingDraftId: function (drafts) {
            var id = null;

            if (!drafts || typeof drafts !== 'object') {
                return null;
            }

            Object.keys(drafts).forEach(function (key) {
                var candidate = parseInt(drafts[key] && drafts[key].entity_id, 10);

                if (!isNaN(candidate) && (id === null || candidate > id)) {
                    id = candidate;
                }
            });

            return id;
        },

        openPublishDialog: function () {
            var self = this,
                dates = this.schedulePanel
                    ? this.schedulePanel.getDates()
                    : {active_from: '', active_to: ''},
                summary = this._computeChangesSummary(),
                storeName = this._getCurrentStoreName();

            if (this.publishDialog) {
                this.publishDialog.open({
                    activeFrom: dates.active_from,
                    activeTo: dates.active_to,
                    changesSummary: summary,
                    targetStore: storeName,
                    templateName: this.currentTemplateId(),
                    onPublish: function (comment, scheduleFrom, scheduleTo) {
                        if (self.schedulePanel && (scheduleFrom || scheduleTo)) {
                            self.schedulePanel.setDates(scheduleFrom, scheduleTo);
                        } else if (self.schedulePanel && !scheduleFrom && !scheduleTo) {
                            self.schedulePanel.clearDates();
                        }

                        self.updateBadges();
                        self.publishTemplate(comment);
                    }
                });
            }
        },

        /**
         * Compute a summary of changes for the publish dialog.
         *
         * @return {Array}
         */
        _computeChangesSummary: function () {
            var changes = [],
                content = this.templateEditor ? this.templateEditor.getValue() : '',
                subject = this.subject() || '',
                css = this.customCssEditor ? this.customCssEditor.getValue() : '',
                themeId = this.themeEditor ? this.themeEditor.getCurrentThemeId() : '';

            if (content) {
                changes.push({
                    type: 'template',
                    icon: '&#9998;',
                    label: $.mage.__('Template HTML'),
                    detail: content.length + ' ' + $.mage.__('characters')
                });
            }

            if (subject) {
                changes.push({
                    type: 'subject',
                    icon: '&#9993;',
                    label: $.mage.__('Subject Line'),
                    detail: '"' + (subject.length > 50 ? subject.substring(0, 50) + '...' : subject) + '"'
                });
            }

            if (css && css.trim()) {
                changes.push({
                    type: 'css',
                    icon: '&#127912;',
                    label: $.mage.__('Custom CSS'),
                    detail: css.trim().split('\n').length + ' ' + $.mage.__('lines')
                });
            }

            if (themeId) {
                changes.push({
                    type: 'theme',
                    icon: '&#9726;',
                    label: $.mage.__('Theme Applied'),
                    detail: ''
                });
            }

            if (this._lastTailwindCss) {
                changes.push({
                    type: 'tailwind',
                    icon: '&#9729;',
                    label: $.mage.__('Tailwind CSS'),
                    detail: $.mage.__('auto-generated')
                });
            }

            return changes;
        },

        /**
         * Get the name of the currently selected store view.
         *
         * @return {string}
         */
        _getCurrentStoreName: function () {
            var storeId = this.getEffectiveStoreId(),
                stores = this.stores || [],
                i;

            if (typeof stores === 'function') {
                stores = stores();
            }

            for (i = 0; i < stores.length; i++) {
                if (parseInt(stores[i].id, 10) === storeId) {
                    return stores[i].name;
                }
            }

            return '';
        },

        /**
         * Toggle the more actions dropdown menu.
         */
        toggleMoreMenu: function () {
            if (this.moreMenu) {
                this.moreMenu.toggle();
            }
        },

        /**
         * Close the more actions dropdown menu.
         */
        _closeMoreMenu: function () {
            if (this.moreMenu) {
                this.moreMenu.close();
            }
        },

        /**
         * Open the variable chooser panel.
         */
        openVariableChooser: function () {
            if (this.variableChooser) {
                if (this.variableChooser.isOpen()) {
                    this.variableChooser.close();
                } else {
                    this.variableChooser.open(
                        this.currentTemplateId(),
                        this.getEffectiveStoreId()
                    );
                }
            }
        },

        /**
         * Open the version history panel for the current template.
         */
        openVersionHistory: function () {
            this._closeMoreMenu();

            if (this.currentTemplateId() && this.versionHistory) {
                this.versionHistory.show(
                    this.currentTemplateId(),
                    this._currentOverrideStoreId !== null
                        ? this._currentOverrideStoreId
                        : this.getEffectiveStoreId()
                );
            }
        },

        /**
         * Reload the current template from the server.
         */
        reloadTemplate: function () {
            if (this.currentTemplateId()) {
                this.loadTemplate(
                    this.currentTemplateId(),
                    this._currentEntityId
                );
            }
        },

        /**
         * Reload drafts for the current template via the draft list panel.
         */
        loadDrafts: function () {
            if (this.draftListPanel && this.currentTemplateId()) {
                this.draftListPanel.loadDrafts(
                    this.currentTemplateId(),
                    this.getEffectiveStoreId()
                );
            }
        },

        /**
         * Open a preview of the current template in a new browser tab.
         */
        previewInNewTab: function () {
            var content, form, fields;

            this._closeMoreMenu();

            content = this.templateEditor ? this.templateEditor.getValue() : '';

            if (!content) {
                return;
            }

            form = document.createElement('form');
            form.method = 'POST';
            form.action = this.urls.preview;
            form.target = '_blank';

            fields = {
                template_content: content,
                theme_css: this.themeEditor ? this.themeEditor.getThemeCss() : '',
                custom_css: this.customCssEditor ? this.customCssEditor.getValue() : '',
                template_identifier: this.currentTemplateId(),
                form_key: this.formKey,
                store_id: this.getEffectiveStoreId(),
                raw: '1'
            };

            Object.keys(fields).forEach(function (key) {
                var input = document.createElement('input');

                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        },

        /**
         * Handle mousedown on the split-pane resizer to start drag resizing.
         *
         * @param {Object} data
         * @param {Event} event
         */
        startResize: function (data, event) {
            var self = this,
                leftPanel = $('#ete-panel-left'),
                startX = event.clientX,
                startWidth = leftPanel.width(),
                onMouseMove,
                onMouseUp;

            event.preventDefault();
            $('body').css('cursor', 'col-resize');

            onMouseMove = function (e) {
                var container = leftPanel.closest('.ete-panels-container'),
                    newWidth = startWidth + (e.clientX - startX),
                    minWidth = 300,
                    maxWidth = container.width() - 350;

                newWidth = Math.max(minWidth, Math.min(maxWidth, newWidth));
                leftPanel.css('width', newWidth + 'px');
            };

            onMouseUp = function () {
                $(document).off('mousemove.eteResize');
                $(document).off('mouseup.eteResize');
                $('body').css('cursor', '');

                if (self.templateEditor) {
                    self.templateEditor.refresh();
                }

                if (self.themeEditor) {
                    self.themeEditor.refresh();
                }

                if (self.customCssEditor) {
                    self.customCssEditor.refresh();
                }
            };

            $(document).on('mousemove.eteResize', onMouseMove);
            $(document).on('mouseup.eteResize', onMouseUp);
        },

        /**
         * Handle clicks on the document to close menus when clicking outside.
         *
         * @param {Object} data
         * @param {Event} event
         * @return {boolean}
         */
        handleDocumentClick: function (data, event) {
            var target = $(event.target);

            if (!target.closest('.ete-more-menu, .ete-toolbar-action-more').length) {
                this._closeMoreMenu();
            }

            if (!target.closest('.ete-entity-search').length) {
                this.entityResults([]);
            }

            return true;
        },

        /**
         * Clean up timers and subscriptions when the component is destroyed.
         */
        destroy: function () {
            if (this._previewDebounceTimer) {
                clearTimeout(this._previewDebounceTimer);
                this._previewDebounceTimer = null;
            }

            if (this._entitySearchTimer) {
                clearTimeout(this._entitySearchTimer);
                this._entitySearchTimer = null;
            }

            $(document).off('keydown.eteShortcuts');

            tailwindCompiler.destroy();

            this._super();
        }
    });
});
