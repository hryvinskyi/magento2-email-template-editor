/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'uiComponent',
    'ko',
    'jquery',
    'uiRegistry',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/directive-decorator',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/directive-scanner'
], function (Component, ko, $, registry, directiveDecorator, directiveScanner) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/template-editor',
            editor: null,
            _decorator: null,
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

                // Directives are marked in a read-only document as well: explaining what a
                // variable is and where it comes from is worth as much while reading a published
                // version as while writing a draft. What changes is only what may be done about
                // it, which is why the read-only state rides along with every activation instead
                // of deciding whether one happens.
                self._decorator = directiveDecorator.create(self.editor, {
                    /**
                     * Pass a clicked directive on, and open nothing.
                     *
                     * @param {Object} occurrence
                     * @param {Object} anchor tracks the directive as the document is edited
                     */
                    onActivate: function (occurrence, anchor) {
                        self.trigger('directiveActivate', {
                            occurrence: occurrence,
                            anchor: anchor,
                            readOnly: self._readOnly
                        });
                    }
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

            // The document was replaced rather than typed into, so there is no pause in the typing
            // to wait for and waiting for one only shows the new content unmarked in the meantime.
            if (this._decorator) {
                this._decorator.refresh();
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
        },

        /**
         * Rewrite the `|...` formatting of one directive, and nothing else.
         *
         * The only route this module has for changing a directive's formatting, and deliberately
         * the only one: the write is bounded to the directive's own chain, so what a directive
         * points at is not among the bytes being replaced and a formatting change cannot turn into
         * a different variable. It lives here because this component owns the editor and owns the
         * read-only state - a caller reaching past it would have neither, and would end up
         * rebuilding the whole directive text, which is the one thing the bounded span exists to
         * prevent.
         *
         * The anchor rather than the caller's offsets decides where to write. Offsets are absolute
         * and go stale as soon as anything is typed anywhere earlier in the document; the caller's
         * own previous call through here counts, because the marks are redrawn only after the
         * document settles and a second change inside that window would be measured against the
         * document as it was before the first. The anchor is moved by the editor along with the
         * text it brackets, so it is asked instead, and what it points at is read again and matched
         * against what the caller last saw before a single byte is replaced.
         *
         * Nothing is thrown and nothing quietly does nothing: a refusal comes back as a reason, so
         * that a formatting change which did not happen can be said out loud rather than read by
         * the admin as one that did not stick.
         *
         * @param {Object} anchor the mark handed out when the directive was activated
         * @param {string} chainText the chain to write, `''` to remove the formatting entirely
         * @param {{reference: string, modifierText: string}} expected the directive the caller
         *        believes it is editing, and the chain it last displayed
         * @return {{written: boolean, reason: string, occurrence: Object|null}} `reason` is one of
         *         `written`, `readOnly`, `noEditor`, `invalidChain`, `gone` or `changed`;
         *         `occurrence` is the directive as it stands after a write and null otherwise
         */
        replaceModifierSpan: function (anchor, chainText, expected) {
            var range,
                rederived,
                current;

            if (!this.editor) {
                return {written: false, reason: 'noEditor', occurrence: null};
            }

            if (this._readOnly) {
                return {written: false, reason: 'readOnly', occurrence: null};
            }

            if (!directiveScanner.isWritableChain(chainText)) {
                return {written: false, reason: 'invalidChain', occurrence: null};
            }

            if (!anchor || typeof anchor.find !== 'function') {
                return {written: false, reason: 'gone', occurrence: null};
            }

            range = anchor.find();

            if (!range) {
                return {written: false, reason: 'gone', occurrence: null};
            }

            rederived = directiveScanner.rederive(
                this.editor.getValue(),
                this.editor.indexFromPos(range.from),
                this.editor.indexFromPos(range.to),
                expected
            );

            if (rederived.reason !== 'ok') {
                return {written: false, reason: rederived.reason, occurrence: null};
            }

            this.editor.replaceRange(
                chainText,
                this.editor.posFromIndex(rederived.occurrence.modifierStart),
                this.editor.posFromIndex(rederived.occurrence.modifierEnd)
            );

            // The chain that was just written is strictly inside the anchor, so the anchor still
            // brackets the whole directive and reading it again is how the caller learns what it
            // now holds. Without that, the caller's next call would be matched against the chain
            // it displayed before this one and refused.
            current = this._occurrenceAt(anchor);

            return {written: true, reason: 'written', occurrence: current};
        },

        /**
         * Read the directive an anchor currently brackets.
         *
         * @param {Object} anchor
         * @return {Object|null}
         * @private
         */
        _occurrenceAt: function (anchor) {
            var range = anchor.find(),
                found;

            if (!range) {
                return null;
            }

            found = directiveScanner.scanRange(
                this.editor.getValue(),
                this.editor.indexFromPos(range.from),
                this.editor.indexFromPos(range.to)
            );

            return found.length === 1 ? found[0] : null;
        },

        /**
         * @inheritDoc
         */
        destroy: function () {
            if (this._decorator) {
                this._decorator.destroy();
                this._decorator = null;
            }

            this._super();
        }
    });
});
