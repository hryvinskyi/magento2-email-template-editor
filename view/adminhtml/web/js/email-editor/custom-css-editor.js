/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'uiComponent',
    'ko',
    'jquery',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver'
], function (Component, ko, $, parentResolver) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/custom-css-editor',
            editor: null
        },

        /** @type {HTMLElement|null} */
        _editorElement: null,

        /** @type {string|null} */
        _pendingValue: null,

        /** @type {boolean} */
        _readOnly: false,

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
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/foldcode',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/foldgutter',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/brace-fold',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/edit/matchbrackets'
            ], function (CodeMirror) {
                if (self.editor) {
                    return;
                }

                self.editor = CodeMirror(el, {
                    mode: 'css',
                    theme: 'default',
                    lineNumbers: true,
                    foldGutter: true,
                    gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
                    matchBrackets: true,
                    readOnly: self._readOnly
                });

                self.editor.on('change', function () {
                    self.onContentChange();
                });

                if (self._pendingValue !== null) {
                    self.editor.setValue(self._pendingValue);
                    self._pendingValue = null;
                }

                // Re-apply any read-only state requested before CodeMirror finished loading.
                self.setReadOnly(self._readOnly);
            });
        },

        /**
         * Toggle read-only mode so the CSS of a read-only version (e.g. the
         * published view) cannot be edited.
         *
         * @param {boolean} flag
         */
        setReadOnly: function (flag) {
            this._readOnly = !!flag;

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
            return this.editor ? this.editor.getValue() : (this._pendingValue || '');
        },

        /**
         * Set the editor content.
         *
         * @param {string} value
         */
        setValue: function (value) {
            if (this.editor) {
                this.editor.setValue(value || '');
            } else {
                this._pendingValue = value || '';
            }
        },

        /**
         * Refresh the CodeMirror layout.
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
         * Notify the editor that the CSS content has changed.
         *
         * This is the only signal the editor gets that the custom CSS was edited: it is
         * what marks the draft dirty, arms the autosave and re-renders the preview.
         *
         * @return {void}
         */
        onContentChange: function () {
            var editor = parentResolver.peek(this);

            if (editor && typeof editor.onContentChange === 'function') {
                editor.onContentChange();
            }
        }
    });
});
