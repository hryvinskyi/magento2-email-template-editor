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
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/custom-data-editor',
            editor: null
        },

        /** @type {HTMLElement|null} */
        _editorElement: null,

        /** @type {string|null} */
        _pendingValue: null,

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
         * Create the JSON CodeMirror editor if the container is visible and not yet initialized.
         */
        _tryCreateEditor: function () {
            var self = this,
                el = this._editorElement;

            if (this.editor || !el || !el.offsetParent) {
                return;
            }

            require([
                'Hryvinskyi_ConfigurationFields/js/codemirror/lib/codemirror',
                'Hryvinskyi_ConfigurationFields/js/codemirror/mode/javascript/javascript',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/foldcode',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/foldgutter',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/fold/brace-fold',
                'Hryvinskyi_ConfigurationFields/js/codemirror/addon/edit/matchbrackets'
            ], function (CodeMirror) {
                if (self.editor) {
                    return;
                }

                self.editor = CodeMirror(el, {
                    mode: {name: 'javascript', json: true},
                    theme: 'default',
                    lineNumbers: true,
                    foldGutter: true,
                    gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
                    matchBrackets: true
                });

                self.editor.on('change', function () {
                    self.onContentChange();
                });

                if (self._pendingValue !== null) {
                    self.editor.setValue(self._pendingValue);
                    self._pendingValue = null;
                }
            });
        },

        /**
         * Return the current editor content (the custom data JSON).
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
         * Notify the parent component that the custom (preview-only) data changed.
         */
        onContentChange: function () {
            this.trigger('customDataChange');
        }
    });
});
