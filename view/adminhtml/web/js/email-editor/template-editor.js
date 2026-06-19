/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'uiComponent',
    'ko',
    'jquery',
    'uiRegistry'
], function (Component, ko, $, registry) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/template-editor',
            editor: null,
            _lineWrapping: false,
            _readOnly: false
        },

        /**
         * Initialize the component and create its observables.
         *
         * @return {Object}
         */
        initialize: function () {
            this._super();

            // Reactive read-only flag so the toolbar can disable the
            // content-mutating actions (Format, Insert Variable) when a
            // read-only version is being viewed.
            this.isReadOnly = ko.observable(false);

            return this;
        },

        /**
         * Load CodeMirror asynchronously and initialize it on the given container element.
         *
         * @param {HTMLElement} element
         */
        initCodeMirror: function (element) {
            var self = this;

            require([
                'Hryvinskyi_ConfigurationFields/js/codemirror/lib/codemirror',
                'Hryvinskyi_ConfigurationFields/js/codemirror/mode/htmlmixed/htmlmixed',
                'Hryvinskyi_ConfigurationFields/js/codemirror/mode/xml/xml',
                'Hryvinskyi_ConfigurationFields/js/codemirror/mode/javascript/javascript',
                'Hryvinskyi_ConfigurationFields/js/codemirror/mode/css/css',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/foldcode',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/foldgutter',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/xml-fold',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/brace-fold',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/edit/matchbrackets'
            ], function (CodeMirror) {
                self.editor = CodeMirror(element, {
                    mode: 'htmlmixed',
                    theme: 'default',
                    lineNumbers: true,
                    foldGutter: true,
                    matchBrackets: true,
                    lineWrapping: self._lineWrapping,
                    readOnly: self._readOnly,
                    gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter']
                });

                self.editor.on('change', function () {
                    self.onContentChange();
                });

                // Re-apply any read-only state requested before CodeMirror finished loading.
                self.setReadOnly(self._readOnly);
            });
        },

        /**
         * Toggle read-only mode. Blocks user edits in CodeMirror and the
         * content-mutating toolbar actions so a read-only version (e.g. the
         * published view) cannot be changed.
         *
         * @param {boolean} flag
         */
        setReadOnly: function (flag) {
            this._readOnly = !!flag;
            this.isReadOnly(this._readOnly);

            if (this.editor) {
                this.editor.setOption('readOnly', this._readOnly);

                var wrapper = this.editor.getWrapperElement();

                if (wrapper) {
                    wrapper.classList.toggle('ete-codemirror-readonly', this._readOnly);
                }
            }
        },

        /**
         * Return the current editor content.
         *
         * @return {string}
         */
        getValue: function () {
            return this.editor ? this.editor.getValue() : '';
        },

        /**
         * Set the editor content.
         *
         * @param {string} value
         */
        setValue: function (value) {
            if (this.editor) {
                this.editor.setValue(value || '');
            }
        },

        /**
         * Refresh the CodeMirror layout.
         */
        refresh: function () {
            if (this.editor) {
                this.editor.refresh();
            }
        },

        /**
         * Format the current HTML content with basic line-break formatting.
         */
        formatCode: function () {
            if (!this.editor || this._readOnly) {
                return;
            }

            var content = this.editor.getValue();
            var formatted = content.replace(/></g, '>\n<');

            this.editor.setValue(formatted);
        },

        /**
         * Toggle line wrapping in the editor.
         */
        toggleWrap: function () {
            this._lineWrapping = !this._lineWrapping;

            if (this.editor) {
                this.editor.setOption('lineWrapping', this._lineWrapping);
            }
        },

        /**
         * Reload the template via the parent orchestrator.
         */
        reloadTemplate: function () {
            this._getParent(function (parent) {
                if (typeof parent.reloadTemplate === 'function') {
                    parent.reloadTemplate();
                }
            });
        },

        /**
         * Open the variable chooser via the parent orchestrator.
         */
        insertVariable: function () {
            this._getParent(function (parent) {
                if (typeof parent.openVariableChooser === 'function') {
                    parent.openVariableChooser();
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
        },

        /**
         * Notify listeners that the editor content has changed.
         */
        onContentChange: function () {
            this.trigger('contentChange');
        },

        /**
         * Insert text at the current cursor position in the editor.
         *
         * @param {string} text
         */
        insertAtCursor: function (text) {
            if (!this.editor || this._readOnly) {
                return;
            }

            var cursor = this.editor.getCursor();

            this.editor.replaceRange(text, cursor);
        }
    });
});
