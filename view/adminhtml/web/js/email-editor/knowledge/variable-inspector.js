/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The popover that answers "what is this {{...}}, and where do I change it".
 *
 * An adapter, not a rulebook: it binds, it asks, it words. Where a directive is and what its
 * formatting says is the scanner's answer, what a chain becomes when a modifier is added is the
 * chain module's, what a reference means is the server's, and where the box goes is the placement
 * module's. What is left here - and all that is meant to be here - is turning those answers into
 * something on screen and turning a click back into a request.
 *
 * Four things about it are load-bearing rather than incidental.
 *
 * It never touches the code editor. A formatting change goes through the template editor's own
 * bounded rewrite, which re-reads the directive from its live position and refuses when what it
 * finds is not what this popover last showed. That is what makes "changing the formatting cannot
 * change what the directive points at" a property of the code rather than a promise: the bytes
 * naming the variable are not inside the range being written, and no route from here can widen
 * that range.
 *
 * It holds the mark it was handed, not the offsets it was handed. Offsets are absolute and stop
 * describing the document as soon as anything is typed - including this popover's own previous
 * write, since the marks are redrawn only once the document settles. The mark is moved by the
 * editor along with the text it brackets, so it is what gets asked before every write.
 *
 * Every request is handed to the editor. A request the editor cannot see counts towards neither
 * the busy indicator nor the cancellation sweep, and the module has a documented history of that
 * exact omission. Each concern also carries its own generation, claimed before anything is
 * cancelled, so an answer for a directive the admin has already navigated away from cannot land
 * in the popover describing a different one; a cancelled request is normal and is never reported.
 *
 * Nothing an admin or a merchant wrote is ever rendered as markup. Custom variable names, config
 * values, resolved previews and template variable labels all reach this popover as text and are
 * bound as text, in the template as well as here.
 */
define([
    'uiComponent',
    'ko',
    'jquery',
    'uiRegistry',
    'mage/translate',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/parent-resolver',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/failure-reporter',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/modifier-chain',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/entry-cache',
    'Hryvinskyi_EmailTemplateEditor/js/email-editor/knowledge/inspector-placement'
], function (
    Component,
    ko,
    $,
    registry,
    $t,
    parentResolver,
    failureReporter,
    modifierChain,
    entryCache,
    placement
) {
    'use strict';

    var /**
         * How wide the popover is, in pixels
         *
         * The same number as the width in the stylesheet, and it has to stay the same number: the
         * box is placed before it exists, so the width used to keep it inside the window is this
         * one rather than a measured one.
         *
         * @type {number}
         */
        PANEL_WIDTH = 380,

        /**
         * How tall the popover would like to be, in pixels
         *
         * Only used to choose which side of the click to open on. What actually bounds the box is
         * the height limit that comes back with the position, so a taller answer scrolls inside the
         * box instead of growing past the window.
         *
         * @type {number}
         */
        PANEL_PREFERRED_HEIGHT = 440;

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/knowledge/variable-inspector',
            urls: window.emailEditorConfig && window.emailEditorConfig.urls || {},
            formKey: window.emailEditorConfig && window.emailEditorConfig.formKey || '',

            /** @type {number} Generation guarding the lookup against a superseded answer */
            _describeToken: 0,

            /** @type {number} Generation guarding the value write against a superseded answer */
            _saveToken: 0
        },

        /**
         * @inheritDoc
         */
        initialize: function () {
            this._super();

            this.observe([
                'isOpen',
                'isLoading',
                'loadError',
                'noticeText',
                'panelLeft',
                'panelTop',
                'panelBottom',
                'panelMaxHeight',
                'panelPlacement',
                'hasEntry',
                'known',
                'title',
                'summary',
                'outputKindLabel',
                'reference',
                'overlong',
                'originLabel',
                'originLocator',
                'originExplanation',
                'caveats',
                'valueAvailable',
                'valueExact',
                'valuePreview',
                'valueTruncated',
                'valueScopeLabel',
                'affordanceKind',
                'affordanceLabel',
                'affordanceUrl',
                'affordanceSteps',
                'editorType',
                'editorOptions',
                'readOnly',
                'hasOccurrence',
                'modifierText',
                'modifierDescriptors',
                'chainCalls',
                'chainParsed',
                'defaultModifier',
                'inlineValue',
                'inlineSaving',
                'inlineMessage',
                'inlineMessageIsError',
                'savedScopeLabel',
                'scopeIsDefault',
                'scopeStoreName',
                'confirmVisible'
            ]);

            this._cache = entryCache.create();
            this._anchor = null;
            this._templateEditor = null;
            this._rootElement = null;
            this._pointer = null;
            this._describeXhr = null;
            this._settling = false;
            this._settleTimer = null;

            this.isOpen(false);
            this.modifierDescriptors([]);
            this.chainCalls([]);
            this.chainParsed(true);
            this.readOnly(false);
            this.hasOccurrence(false);
            this._resetEntryState();
            this._resetPosition();

            this._initComputed();
            this._initDocumentListeners();
            this._listenToTemplateEditor();
            this._listenToVariableChooser();
            this._listenToStoreView();

            return this;
        },

        /**
         * Build the views derived from what is currently on screen.
         *
         * @return {void}
         * @private
         */
        _initComputed: function () {
            var self = this;

            /**
             * The published modifiers, each carrying whether this directive is using it.
             *
             * Exactly what the server published, in the order it published: the popover never
             * invents a modifier, never hides one, and never reorders them. A modifier the renderer
             * does not implement is shown with the rest rather than left out, because the whole
             * point of listing it is to explain why writing it works at all.
             */
            /*
             * The rows depend only on what the server published, so the list is rebuilt only when
             * that changes - which is once per page. Whether a modifier is in use is an observable
             * on the row rather than part of its identity, because a row list rebuilt on every
             * chain change makes the foreach binding tear down and recreate every control under it:
             * the panel loses its scroll position and the checkbox loses focus each time one is
             * ticked, which reads as the panel jumping away from what was just pressed.
             */
            this.modifierRows = ko.computed(function () {
                return (self.modifierDescriptors() || []).map(function (descriptor) {
                    var spec = (descriptor.arguments || [])[0] || null,
                        fallback = spec && typeof spec['default'] === 'string' ? spec['default'] : '';

                    return {
                        name: descriptor.name,
                        label: descriptor.label || descriptor.name,
                        description: descriptor.description || '',
                        implemented: descriptor.implemented !== false,
                        options: spec && spec.options ? spec.options : [],
                        defaultArgument: fallback,
                        applied: ko.observable(false),
                        argument: ko.observable(fallback)
                    };
                });
            });

            /*
             * Bring every row back in line with the chain the directive actually carries. This runs
             * after a write lands and after a different directive is opened, and it is also what
             * corrects the checkbox when a write was refused: the checkbox binds two ways, so the
             * press flips it optimistically, and only the document can say whether that stands.
             */
            ko.computed(function () {
                var calls = self.chainCalls() || [];

                self.modifierRows().forEach(function (row) {
                    var call = self._callNamed(calls, row.name);

                    row.applied(call !== null);
                    row.argument(
                        call && call.args.length > 0 ? call.args[0] : row.defaultArgument
                    );
                });
            });

            /**
             * What to say about a chain that carries nothing, or one that could not be read.
             *
             * An empty chain is never described as "no formatting". In an email template a
             * variable written with nothing after it is escaped as HTML, so the empty chain is a
             * choice with an effect and saying otherwise would teach the opposite of what happens.
             */
            this.chainSummary = ko.computed(function () {
                if (!self.chainParsed()) {
                    return $t(
                        'This formatting could not be read as a list of modifiers, so it is shown ' +
                        'as it is written and left alone. Edit it in the template itself.'
                    );
                }

                if ((self.chainCalls() || []).length > 0) {
                    return '';
                }

                return self.defaultModifier() === 'escape'
                    ? $t(
                        'No modifiers. In an email template that means the value is escaped as ' +
                        'HTML, which is what a variable written with nothing after it does.'
                    )
                    : $t('No modifiers are applied to this directive.');
            });

            /**
             * Whether the formatting may be changed from here at all.
             */
            this.chainLocked = ko.computed(function () {
                return self.readOnly() || !self.chainParsed();
            });

            /**
             * Whether there is any formatting to talk about.
             *
             * A directive picked out of the chooser is not in the template yet: there is no chain to
             * show, nothing to rewrite, and no mark to rewrite it against. The whole section goes
             * rather than appearing empty, because a formatting control that could only refuse reads
             * as the popover being broken. A read-only version is the other case, and it is the same
             * answer for a different reason - nothing there may be written at all.
             */
            this.showFormatting = ko.computed(function () {
                return self.hasOccurrence() && !self.readOnly();
            });

            /**
             * Whether a value editor should be offered for what is on screen.
             */
            this.showValueEditor = ko.computed(function () {
                return self.hasEntry() && self.affordanceKind() === 'inline';
            });
        },

        /**
         * Watch the gestures that place, dismiss and invalidate the popover.
         *
         * @return {void}
         * @private
         */
        _initDocumentListeners: function () {
            var self = this;

            /**
             * Remember where a gesture ended, because that is where the popover opens.
             *
             * Read while the release is still travelling down to its target: the code editor
             * announces the directive from its own handler on that same release, so by the time
             * this component hears about the activation the gesture is over and its position is
             * only knowable from what was recorded on the way in.
             *
             * @param {MouseEvent} event
             * @return {void}
             */
            this._onPointerUp = function (event) {
                self._pointer = {x: event.clientX, y: event.clientY};
            };

            /**
             * Close when something outside the popover is pressed.
             *
             * @param {MouseEvent} event
             * @return {void}
             */
            this._onDocumentDown = function (event) {
                if (self.isOpen() && !self._isInsidePanel(event.target)) {
                    self.close();
                }
            };

            /**
             * Close on Escape.
             *
             * @param {KeyboardEvent} event
             * @return {void}
             */
            this._onKeyDown = function (event) {
                if (event.key === 'Escape' && self.isOpen()) {
                    self.close();
                }
            };

            /**
             * Close when the window changes shape.
             *
             * @return {void}
             */
            this._onResize = function () {
                if (self.isOpen()) {
                    self.close();
                }
            };

            /**
             * Close when anything outside the popover scrolls.
             *
             * The box is placed against a point in the window, and scrolling the editor pane moves
             * the directive away from that point without moving the box. Nothing is left to
             * re-measure against once that has happened, so it goes rather than sitting over a
             * directive it is not describing. Scrolling the popover's own content is not that.
             *
             * A scroll belonging to the gesture that opened the popover is not that either: the
             * code editor brings the line that was clicked into view, and that scroll is reported
             * after this component has already been told about the click. Anything arriving before
             * the browser has drawn the popover once is part of opening it rather than a reason to
             * take it away again.
             *
             * @param {Event} event
             * @return {void}
             */
            this._onScroll = function (event) {
                if (self.isOpen() && !self._settling && !self._isInsidePanel(event.target)) {
                    self.close();
                }
            };

            document.addEventListener('mouseup', this._onPointerUp, true);
            document.addEventListener('mousedown', this._onDocumentDown, true);
            document.addEventListener('keydown', this._onKeyDown);
            document.addEventListener('scroll', this._onScroll, true);
            window.addEventListener('resize', this._onResize);
        },

        /**
         * Open whenever the code editor says a directive was activated.
         *
         * The template editor is also the only route to a formatting change, so the same reference
         * is what the chain editor writes through later.
         *
         * @return {void}
         * @private
         */
        _listenToTemplateEditor: function () {
            var self = this;

            registry.get(this.parentName + '.templateEditor', function (editor) {
                self._templateEditor = editor;

                editor.on('directiveActivate', function (payload) {
                    self.open(payload);
                });
            });
        },

        /**
         * Open whenever the variable chooser says a row was asked about.
         *
         * The chooser sends a reference and nothing else, which is all it has: the variable it named
         * is not in the template yet. That is why it arrives here rather than being dressed up as a
         * directive with no position - the write path would then have to carry a case for a mark
         * that was never there, and every write would be one test away from being aimed at nothing.
         *
         * @return {void}
         * @private
         */
        _listenToVariableChooser: function () {
            var self = this;

            registry.get(this.parentName + '.variableChooser', function (chooser) {
                chooser.on('describeVariable', function (reference) {
                    self.openReference(reference);
                });
            });
        },

        /**
         * Throw away what was learned at the old store view when the admin picks a new one.
         *
         * Every value in an entry was read at a scope and several of the affordances differ by
         * scope, so an entry kept across the switch is a confident wrong answer rather than a stale
         * one. The open popover goes with them for the same reason.
         *
         * @return {void}
         * @private
         */
        _listenToStoreView: function () {
            var self = this;

            parentResolver.resolve(this, function (editor) {
                if (!editor || typeof editor.storeId !== 'function' ||
                    typeof editor.storeId.subscribe !== 'function'
                ) {
                    return;
                }

                editor.storeId.subscribe(function () {
                    self._cache.forget();
                    self.close();
                });
            });
        },

        /**
         * Keep the popover's own element, so a press can be told from a press outside it.
         *
         * @param {HTMLElement} element
         * @return {void}
         */
        onRootRendered: function (element) {
            this._rootElement = element;
        },

        /**
         * Show what is known about an activated directive.
         *
         * @param {{occurrence: Object, anchor: Object, readOnly: boolean}} payload
         * @return {void}
         */
        open: function (payload) {
            var occurrence = payload && payload.occurrence;

            if (!occurrence || !occurrence.reference) {
                return;
            }

            // The mark, not the offsets. It is what the template editor is handed before every
            // write, and it is the only thing here that still describes the document after the
            // admin has typed a character anywhere above the directive.
            this._anchor = payload.anchor || null;

            this._resetEntryState();
            this.readOnly(payload.readOnly === true);
            this.overlong(occurrence.overlong === true);
            this.hasOccurrence(true);
            this._adoptChain(occurrence);
            this._present(occurrence.reference);
        },

        /**
         * Show what is known about a reference that is not in the template.
         *
         * The other way in. Everything an entry says about where a value comes from, what it is
         * right now and where to change it holds whether or not the directive has been written down
         * yet, so all of that is shown exactly as it always is. What is missing is the directive
         * itself, and with it the formatting: there is no chain in any document to read, and nothing
         * for a formatting change to be applied to.
         *
         * @param {string} reference a canonical reference
         * @return {void}
         */
        openReference: function (reference) {
            if (typeof reference !== 'string' || reference === '') {
                return;
            }

            // Nothing to hold on to and nothing that could go stale: there is no directive in the
            // document to move underneath this popover.
            this._anchor = null;

            this._resetEntryState();
            this.readOnly(false);
            this.overlong(false);
            this.hasOccurrence(false);
            this.reference(reference);
            this.modifierText('');
            this.chainParsed(true);
            this.chainCalls([]);
            this._present(reference);
        },

        /**
         * Put the popover on screen and ask what the reference means.
         *
         * @param {string} reference a canonical reference
         * @return {void}
         * @private
         */
        _present: function (reference) {
            var self = this;

            this._applyScope();
            this.isOpen(true);
            this.reposition();

            this._settling = true;
            clearTimeout(this._settleTimer);
            this._settleTimer = setTimeout(function () {
                self._settling = false;
            }, 0);

            this._describe(reference);
        },

        /**
         * Close the popover and forget the directive it was describing.
         *
         * A value write already on the wire is deliberately left to finish: it has reached the
         * server either way, and cancelling it would only throw away the answer saying whether it
         * landed.
         *
         * @return {void}
         */
        close: function () {
            this.isOpen(false);
            this.confirmVisible(false);
            this.noticeText('');
            this._anchor = null;

            // The generation moves on with the popover, so a lookup that was already on the wire
            // when it closed cannot fill a popover that has been reopened on something else.
            this._describeToken++;

            if (this._describeXhr && this._describeXhr.readyState !== 4) {
                this._describeXhr.abort();
            }

            this._describeXhr = null;
        },

        /**
         * Put the popover next to the click that opened it, inside the window.
         *
         * @return {void}
         */
        reposition: function () {
            var point = this._pointer || {
                    x: Math.round(window.innerWidth / 2),
                    y: Math.round(window.innerHeight / 3)
                },
                placed = placement.place(
                    point,
                    {width: PANEL_WIDTH, height: PANEL_PREFERRED_HEIGHT},
                    {width: window.innerWidth, height: window.innerHeight}
                );

            this.panelLeft(placed.left + 'px');
            this.panelTop(placed.top === null ? '' : placed.top + 'px');
            this.panelBottom(placed.bottom === null ? '' : placed.bottom + 'px');
            this.panelMaxHeight(placed.maxHeight + 'px');
            this.panelPlacement(placed.placement);
        },

        /**
         * Toggle one modifier on the directive being shown.
         *
         * Which way to toggle is decided from the chain the directive currently carries, never from
         * the row's own applied flag. The checkbox binds two ways, and its write-back runs before
         * this handler does, so by the time this is called the flag has already been flipped to the
         * state the click is asking for — reading it here would invert every toggle: adding a
         * modifier would try to remove one that is not there, and removing one would add it twice.
         * Either way the chain came out unchanged and the checkbox snapped straight back, which
         * reads as a control that does not respond at all. The document is the one source of truth.
         *
         * @param {Object} row a row of the modifier list
         * @return {boolean} true, so the browser still paints the control that was clicked
         */
        toggleModifier: function (row) {
            var calls = this.chainCalls() || [],
                isApplied = this._callNamed(calls, row.name) !== null;

            this._writeChain(modifierChain.serialise(
                isApplied
                    ? modifierChain.withoutModifier(calls, row.name)
                    : modifierChain.withModifier(calls, row.name, this._argumentsFor(row, row.argument()))
            ));

            return true;
        },

        /**
         * Choose the argument a modifier is called with, applying it if it was not applied yet.
         *
         * @param {Object} row a row of the modifier list
         * @param {Event} event the change event of the argument control
         * @return {boolean} true, so the browser still paints the control that was changed
         */
        setModifierArgument: function (row, event) {
            var chosen = event && event.target ? event.target.value : '';

            this._writeChain(modifierChain.serialise(
                modifierChain.withModifier(this.chainCalls() || [], row.name, this._argumentsFor(row, chosen))
            ));

            return true;
        },

        /**
         * Take every modifier off the directive being shown.
         *
         * @return {void}
         */
        clearModifiers: function () {
            this._writeChain('');
        },

        /**
         * Begin an inline write, asking first when it would land in the default scope.
         *
         * The default scope is the widest blast radius there is and the one an admin is least
         * likely to have chosen on purpose, because the editor opens on whatever store view it
         * remembered from last time. A store view write states its scope and needs nothing more.
         *
         * @return {void}
         */
        startSave: function () {
            if (this.inlineSaving()) {
                return;
            }

            this.inlineMessage('');
            this.inlineMessageIsError(false);
            this.savedScopeLabel('');

            if (this.scopeIsDefault()) {
                this.confirmVisible(true);

                return;
            }

            this._postValue();
        },

        /**
         * Go ahead with a write into the default scope.
         *
         * @return {void}
         */
        confirmSave: function () {
            this.confirmVisible(false);
            this._postValue();
        },

        /**
         * Abandon a write into the default scope.
         *
         * @return {void}
         */
        cancelSave: function () {
            this.confirmVisible(false);
        },

        /**
         * Ask the server what a reference means at the current store view.
         *
         * @param {string} reference a canonical reference
         * @return {void}
         * @private
         */
        _describe: function (reference) {
            var self = this,
                editor = parentResolver.peek(this),
                storeId = this._storeId(),
                remembered = this._cache.modifiers(),
                cached = this._cache.entry(storeId, reference),
                url = this.urls.knowledgeDescribe,
                token,
                xhr;

            // Claimed before anything is cancelled, and before the cache is even consulted.
            // Cancelling is best effort - an answer already on the wire still arrives - so it is
            // this generation that decides which answer is allowed to write the popover, and an
            // answer read straight out of the cache has to supersede a request in flight for the
            // directive the admin has just moved on from exactly as a new request would.
            token = ++this._describeToken;

            if (this._describeXhr && this._describeXhr.readyState !== 4) {
                this._describeXhr.abort();
            }

            this._describeXhr = null;

            if (remembered) {
                this.modifierDescriptors(remembered);
            }

            if (cached) {
                this._applyEntry(cached);

                return;
            }

            if (!url) {
                this.isLoading(false);
                this.loadError($t(
                    'This editor has no address for the variable knowledge base, so nothing can ' +
                    'be looked up.'
                ));

                return;
            }

            this.isLoading(true);
            this.loadError('');

            xhr = $.ajax({
                url: url,
                type: 'POST',
                data: {
                    form_key: this.formKey,
                    store_id: storeId,
                    template_id: this._templateId(),
                    references: [reference]
                },
                dataType: 'json'
            });

            this._describeXhr = editor && typeof editor.trackRequest === 'function'
                ? editor.trackRequest(xhr)
                : xhr;

            this._describeXhr.done(function (res) {
                if (token !== self._describeToken) {
                    return;
                }

                self.isLoading(false);
                self._receiveDescription(res, storeId, reference);
            }).fail(function (jqXhr, textStatus) {
                // A lookup cancelled here is not a fault: it was superseded by the directive the
                // admin clicked next, or dropped along with the popover.
                if (textStatus === 'abort' || token !== self._describeToken) {
                    return;
                }

                self.isLoading(false);
                self.loadError($t('This directive could not be looked up. Please try again.'));
                failureReporter.report(
                    self,
                    $t('This directive could not be looked up. Please try again.'),
                    textStatus
                );
            });
        },

        /**
         * Take what came back from a lookup, or say why nothing did.
         *
         * @param {Object} res
         * @param {number} storeId
         * @param {string} reference
         * @return {void}
         * @private
         */
        _receiveDescription: function (res, storeId, reference) {
            var entry;

            if (!res || res.success !== true) {
                this.loadError(res && res.message ? res.message : $t('This directive could not be looked up.'));
                failureReporter.report(this, this.loadError());

                return;
            }

            if (res.modifiers) {
                this._cache.rememberModifiers(res.modifiers);
                this.modifierDescriptors(this._cache.modifiers() || []);
            }

            entry = res.entries && Object.prototype.hasOwnProperty.call(res.entries, reference)
                ? res.entries[reference]
                : null;

            if (!entry) {
                this.loadError($t('The knowledge base answered without an entry for this directive.'));

                return;
            }

            this._cache.remember(storeId, reference, entry);
            this._applyEntry(entry);
        },

        /**
         * Put an entry on screen.
         *
         * @param {Object} entry
         * @return {void}
         * @private
         */
        _applyEntry: function (entry) {
            var origin = entry.origin || {},
                affordance = entry.affordance || {},
                value = entry.value || {};

            this.known(entry.known === true);
            this.title(entry.title || $t('Not documented'));
            this.summary(entry.summary || '');
            this.outputKindLabel(this._outputKindLabel(entry.outputKind));
            this.defaultModifier(entry.defaultModifier || '');
            this.originLabel(this._originKindLabel(origin.kind));
            this.originLocator(origin.locator || '');
            this.originExplanation(origin.explanation || '');
            this.caveats(entry.caveats || []);
            this.affordanceKind(affordance.kind || 'none');
            this.affordanceLabel(affordance.label || '');
            this.affordanceUrl(affordance.url || '');
            this.affordanceSteps(affordance.steps || []);
            this.editorType(affordance.editorType || 'text');
            this.editorOptions(affordance.editorOptions || []);
            this._applyValueBlock(value);

            // The editor is filled in from the stored value only when what is on screen is the
            // whole of it. A preview that was shortened for display would otherwise be saved back
            // as the value, and the admin would have truncated the setting by opening a popover.
            this.inlineValue(
                value.available === true && value.exact === true && value.truncated !== true
                    ? value.preview || ''
                    : ''
            );

            this.hasEntry(true);
            this.isLoading(false);
        },

        /**
         * Show a value block: what it is, whether it is real, and the scope it was read at.
         *
         * @param {Object} value
         * @return {void}
         * @private
         */
        _applyValueBlock: function (value) {
            var block = value || {};

            this.valueAvailable(block.available === true);
            this.valueExact(block.exact === true);
            this.valuePreview(block.preview || '');
            this.valueTruncated(block.truncated === true);
            this.valueScopeLabel(block.scopeLabel || '');
        },

        /**
         * Send an inline value to the server and show what it says came back.
         *
         * @return {void}
         * @private
         */
        _postValue: function () {
            var self = this,
                editor = parentResolver.peek(this),
                url = this.urls.knowledgeSaveValue,
                token,
                xhr;

            if (this.inlineSaving()) {
                return;
            }

            if (!url) {
                this.inlineMessage($t('This editor has no address for saving a value, so nothing was sent.'));
                this.inlineMessageIsError(true);

                return;
            }

            token = ++this._saveToken;
            this.inlineSaving(true);

            xhr = $.ajax({
                url: url,
                type: 'POST',
                data: {
                    form_key: this.formKey,
                    store_id: this._storeId(),
                    reference: this.reference(),
                    value: this.inlineValue()
                },
                dataType: 'json'
            });

            xhr = editor && typeof editor.trackRequest === 'function' ? editor.trackRequest(xhr) : xhr;

            xhr.done(function (res) {
                if (token !== self._saveToken) {
                    return;
                }

                self._receiveSave(res);
            }).fail(function (jqXhr, textStatus) {
                if (textStatus === 'abort' || token !== self._saveToken) {
                    return;
                }

                self.inlineMessage($t('The value could not be saved. Please try again.'));
                self.inlineMessageIsError(true);
                failureReporter.report(
                    self,
                    $t('The value could not be saved. Please try again.'),
                    textStatus
                );
            }).always(function () {
                if (token === self._saveToken) {
                    self.inlineSaving(false);
                }
            });
        },

        /**
         * Take the answer to a write.
         *
         * The refusals the server distinguishes are several, and each one names something
         * different that is missing, so whichever it sent is shown as it was sent rather than
         * flattened into one sentence that would tell the admin nothing to act on.
         *
         * What replaces the value on screen is the block that came back, never what was typed:
         * stating the scope beforehand is a promise, and the scope reported by the refreshed value
         * is the fact the admin can check it against.
         *
         * @param {Object} res
         * @return {void}
         * @private
         */
        _receiveSave: function (res) {
            if (!res || res.success !== true) {
                this.inlineMessage(res && res.message ? res.message : $t('The value could not be saved.'));
                this.inlineMessageIsError(true);

                return;
            }

            // A written value can change what other references resolve to as well, and every entry
            // held here carries a value read before this write, so all of them go rather than one
            // being patched. What is on screen now came from the answer.
            this._cache.forget();
            this._applyValueBlock(res.value || {});
            this.savedScopeLabel(res.value && res.value.scopeLabel ? res.value.scopeLabel : '');
            this.inlineMessage($t('Saved.'));
            this.inlineMessageIsError(false);
        },

        /**
         * Rewrite the formatting of the directive being shown, through the code editor's own
         * bounded rewrite and never any other way.
         *
         * The pair handed over is the reference and the chain bytes as this popover has them on
         * screen. The editor re-reads the directive where the mark says it is now and refuses
         * unless both still match, so a document edited underneath the popover leaves the
         * directive alone instead of being written at an offset that has moved.
         *
         * @param {string} chainText the chain to write, '' to take all formatting off
         * @return {void}
         * @private
         */
        _writeChain: function (chainText) {
            var result;

            if (this.readOnly()) {
                this.showNotice(this._refusalMessage('readOnly'));

                return;
            }

            if (!this._templateEditor || typeof this._templateEditor.replaceModifierSpan !== 'function') {
                this.showNotice(this._refusalMessage('noEditor'));

                return;
            }

            result = this._templateEditor.replaceModifierSpan(this._anchor, chainText, {
                reference: this.reference(),
                modifierText: this.modifierText()
            });

            if (!result || result.written !== true) {
                this.showNotice(this._refusalMessage(result ? result.reason : ''));

                return;
            }

            // The directive as it stands after the write, not as it stood before it. Keeping the
            // old one would have the next change refused for having moved on, which reads as the
            // popover being broken rather than as the document having changed.
            if (!result.occurrence) {
                this.showNotice(this._refusalMessage('changed'));

                return;
            }

            this._adoptChain(result.occurrence);
        },

        /**
         * Take the formatting of a directive as this popover's own.
         *
         * @param {Object} occurrence
         * @return {void}
         * @private
         */
        _adoptChain: function (occurrence) {
            this.reference(occurrence.reference);
            this.modifierText(occurrence.modifierText || '');
            this.chainParsed(occurrence.chainParsed !== false);
            this.chainCalls(occurrence.modifiers || []);
        },

        /**
         * Replace the popover's body with something that has to be read before it goes.
         *
         * Closing outright would take the sentence away with the box, and a formatting change that
         * quietly did not happen is worse than one that says why. What is left is the sentence and
         * a way to dismiss it; the popover has stopped describing the directive either way.
         *
         * @param {string} message already translated
         * @return {void}
         */
        showNotice: function (message) {
            this.confirmVisible(false);
            this.noticeText(message);
        },

        /**
         * What to tell the admin about a formatting change that did not happen.
         *
         * The codes the rewrite answers with are codes and carry no wording of their own; this is
         * where they become something to read. `gone` and `changed` are kept apart on purpose -
         * one means the directive is not there any more and the other that it is there and
         * different, and only the second is worth clicking again for.
         *
         * @param {string} reason
         * @return {string}
         * @private
         */
        _refusalMessage: function (reason) {
            var messages = {
                gone: $t('That directive is no longer in the template, so its formatting was left alone.'),
                changed: $t(
                    'The directive changed while this was open, so its formatting was left alone. ' +
                    'Click it again to start from what is there now.'
                ),
                readOnly: $t('This version is read-only, so its formatting cannot be changed here.'),
                noEditor: $t('The code editor is not ready yet, so its formatting was left alone.'),
                invalidChain: $t(
                    'That combination of modifiers cannot be written into the template safely, so ' +
                    'nothing was changed.'
                )
            };

            return Object.prototype.hasOwnProperty.call(messages, reason)
                ? messages[reason]
                : $t('The formatting could not be changed, so it was left alone.');
        },

        /**
         * What kind of output a directive produces, in words.
         *
         * @param {string} kind
         * @return {string}
         * @private
         */
        _outputKindLabel: function (kind) {
            var labels = {
                text: $t('Plain text'),
                html: $t('HTML'),
                url: $t('Web address'),
                currency: $t('Money amount'),
                date: $t('Date'),
                number: $t('Number')
            };

            return Object.prototype.hasOwnProperty.call(labels, kind) ? labels[kind] : '';
        },

        /**
         * Where a value comes from, in words.
         *
         * @param {string} kind
         * @return {string}
         * @private
         */
        _originKindLabel: function (kind) {
            var labels = {
                config: $t('Configuration value'),
                custom_variable: $t('Custom variable'),
                template_var: $t('Template variable'),
                design_config: $t('Design configuration'),
                computed: $t('Worked out in code'),
                directive: $t('Template directive')
            };

            return Object.prototype.hasOwnProperty.call(labels, kind) ? labels[kind] : $t('Unknown origin');
        },

        /**
         * The arguments a modifier should be written with.
         *
         * A chosen argument that is the one the server published as the default is written as no
         * argument at all, so a directive keeps the shorter spelling an admin would have typed.
         *
         * @param {Object} row a row of the modifier list
         * @param {string} chosen
         * @return {string[]}
         * @private
         */
        _argumentsFor: function (row, chosen) {
            if (!chosen || chosen === row.defaultArgument) {
                return [];
            }

            return [chosen];
        },

        /**
         * The call carrying this name in a chain, or nothing.
         *
         * @param {Array.<{name: string, args: string[]}>} calls
         * @param {string} name
         * @return {{name: string, args: string[]}|null}
         * @private
         */
        _callNamed: function (calls, name) {
            var index;

            for (index = 0; index < calls.length; index++) {
                if (calls[index].name === name) {
                    return calls[index];
                }
            }

            return null;
        },

        /**
         * Say which scope an inline write would land in, before it is offered.
         *
         * @return {void}
         * @private
         */
        _applyScope: function () {
            var storeId = this._storeId();

            this.scopeIsDefault(storeId === 0);
            this.scopeStoreName(this._storeName());
        },

        /**
         * The store view the editor is currently working in.
         *
         * @return {number}
         * @private
         */
        _storeId: function () {
            var editor = parentResolver.peek(this);

            return editor && typeof editor.getEffectiveStoreId === 'function'
                ? editor.getEffectiveStoreId()
                : 0;
        },

        /**
         * The template the editor currently has open.
         *
         * @return {string}
         * @private
         */
        _templateId: function () {
            var editor = parentResolver.peek(this);

            return editor && typeof editor.currentTemplateId === 'function'
                ? String(editor.currentTemplateId() || '')
                : '';
        },

        /**
         * The name of the store view being edited, as the store switcher spells it.
         *
         * Asked of the orchestrator rather than worked out here. The store list is part of that
         * component's configuration and is not published into the global configuration object,
         * which carries only the addresses, the form key and the store id - so a copy kept here
         * would be an empty list, and the scope this panel promises to name would silently have no
         * name. Naming the scope before a value is written is the whole of what makes such a write
         * safe to offer, so it must not be able to come out blank.
         *
         * @return {string}
         * @private
         */
        _storeName: function () {
            var editor = parentResolver.peek(this);

            return editor && typeof editor.getCurrentStoreName === 'function'
                ? String(editor.getCurrentStoreName() || '')
                : '';
        },

        /**
         * Is this node the popover, or inside it?
         *
         * Compared against the popover's own element rather than against a class name, so nothing
         * here has to agree with the stylesheet about what the popover is called.
         *
         * @param {Node} node
         * @return {boolean}
         * @private
         */
        _isInsidePanel: function (node) {
            var element = node;

            if (!this._rootElement) {
                return false;
            }

            while (element) {
                if (element === this._rootElement) {
                    return true;
                }

                element = element.parentNode;
            }

            return false;
        },

        /**
         * Forget the directive that was on screen.
         *
         * @return {void}
         * @private
         */
        _resetEntryState: function () {
            this.isLoading(false);
            this.loadError('');
            this.noticeText('');
            this.hasEntry(false);
            this.known(false);
            this.title('');
            this.summary('');
            this.outputKindLabel('');
            this.originLabel('');
            this.originLocator('');
            this.originExplanation('');
            this.caveats([]);
            this.valueAvailable(false);
            this.valueExact(false);
            this.valuePreview('');
            this.valueTruncated(false);
            this.valueScopeLabel('');
            this.affordanceKind('none');
            this.affordanceLabel('');
            this.affordanceUrl('');
            this.affordanceSteps([]);
            this.editorType('text');
            this.editorOptions([]);
            this.defaultModifier('');
            this.inlineValue('');
            this.inlineSaving(false);
            this.inlineMessage('');
            this.inlineMessageIsError(false);
            this.savedScopeLabel('');
            this.confirmVisible(false);
        },

        /**
         * Put the popover somewhere harmless until it has been placed for real.
         *
         * @return {void}
         * @private
         */
        _resetPosition: function () {
            this.panelLeft('0px');
            this.panelTop('0px');
            this.panelBottom('');
            this.panelMaxHeight(placement.MIN_HEIGHT + 'px');
            this.panelPlacement('below');
        },

        /**
         * @inheritDoc
         */
        destroy: function () {
            clearTimeout(this._settleTimer);
            this._settleTimer = null;

            document.removeEventListener('mouseup', this._onPointerUp, true);
            document.removeEventListener('mousedown', this._onDocumentDown, true);
            document.removeEventListener('keydown', this._onKeyDown);
            document.removeEventListener('scroll', this._onScroll, true);
            window.removeEventListener('resize', this._onResize);

            this._anchor = null;
            this._rootElement = null;
            this._templateEditor = null;

            this._super();
        }
    });
});
