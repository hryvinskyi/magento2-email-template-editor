/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define([
    'uiComponent',
    'ko'
], function (Component, ko) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Hryvinskyi_EmailTemplateEditor/email-editor/draft-manager',
            autoSaveDelay: 2000,
            savedTimeText: '',
            tracks: {
                savedTimeText: true
            }
        },

        /**
         * @inheritDoc
         */
        initialize: function () {
            this._super();

            this._isDirty = false;
            this._autoSaveTimer = null;
            this._lastSavedTime = null;
            this._statusUpdateInterval = null;
            this._dirtyGeneration = 0;

            return this;
        },

        /**
         * Mark the editor state as dirty and schedule an auto-save.
         *
         * Every edit advances the revision counter, so that a save which is already
         * on its way to the server can be recognised afterwards as belonging to an
         * older revision of the editor.
         */
        markDirty: function () {
            this._isDirty = true;
            this._dirtyGeneration++;
            this._armAutoSave();
        },

        /**
         * Return the revision the editor is currently at.
         *
         * A caller that persists the editor state reads this in the same synchronous
         * step as the payload it is about to send, and hands the value back to
         * {@link markClean} once that payload has been stored.
         *
         * @return {number}
         */
        getGeneration: function () {
            return this._dirtyGeneration;
        },

        /**
         * Mark the editor state as clean and cancel any pending auto-save.
         *
         * "Clean" is a statement about one specific revision of the editor, not about
         * the editor as such. A save that has been superseded by a newer edit must not
         * be allowed to claim the editor is clean: doing so cancels the auto-save armed
         * for that newer edit, and the edit is then never written anywhere. Passing the
         * revision that was captured together with the saved payload prevents it — when
         * the editor has moved on since, both the dirty flag and the pending timer are
         * left exactly as they are.
         *
         * Called with no argument at all it clears unconditionally, which is what a caller
         * that has just replaced the editor's content wants: there is no edit left to
         * protect. Passing a revision — including one that could not be determined — is a
         * claim about a revision and is checked, so an undeterminable revision errs
         * towards saving again rather than towards dropping an edit.
         *
         * @param {number} [generation] - Revision this claim of cleanliness refers to.
         */
        markClean: function (generation) {
            if (arguments.length > 0 && generation !== this._dirtyGeneration) {
                return;
            }

            this._isDirty = false;

            if (this._autoSaveTimer) {
                clearTimeout(this._autoSaveTimer);
                this._autoSaveTimer = null;
            }
        },

        /**
         * Re-arm the auto-save countdown without changing the revision or the dirty flag.
         *
         * For a caller that had to skip a save rather than perform it: the state waiting
         * to be saved has not changed, so marking dirty again would be wrong — it would
         * advance the revision and thereby invalidate the {@link markClean} of the save
         * that is still in flight, for no reason.
         */
        reschedule: function () {
            this._armAutoSave();
        },

        /**
         * Check whether there are unsaved changes.
         *
         * @return {boolean}
         */
        isDirty: function () {
            return this._isDirty;
        },

        /**
         * Start the auto-save countdown, replacing any countdown already running.
         */
        _armAutoSave: function () {
            var self = this;

            if (this._autoSaveTimer) {
                clearTimeout(this._autoSaveTimer);
            }

            this._autoSaveTimer = setTimeout(function () {
                var parent = self.source || self.parentComponent;

                // Forget this countdown before handing over: the save may decide it
                // cannot run now and arm a fresh one, and clearing the handle
                // afterwards would throw that new countdown away.
                self._autoSaveTimer = null;

                if (parent && typeof parent.saveDraft === 'function') {
                    parent.saveDraft();
                }
            }, this.autoSaveDelay);
        },

        /**
         * Record the current time as the last saved time and start
         * updating the relative time text every 30 seconds.
         */
        updateSavedTime: function () {
            var self = this;

            this._lastSavedTime = new Date();
            this._computeSavedTimeText();

            if (this._statusUpdateInterval) {
                clearInterval(this._statusUpdateInterval);
            }

            this._statusUpdateInterval = setInterval(function () {
                self._computeSavedTimeText();
            }, 30000);
        },

        /**
         * Compute the relative time text from the last saved time.
         */
        _computeSavedTimeText: function () {
            var now,
                diffMs,
                diffSec,
                diffMin,
                diffHour;

            if (!this._lastSavedTime) {
                this.savedTimeText = '';

                return;
            }

            now = new Date();
            diffMs = now.getTime() - this._lastSavedTime.getTime();
            diffSec = Math.floor(diffMs / 1000);
            diffMin = Math.floor(diffSec / 60);
            diffHour = Math.floor(diffMin / 60);

            var parent = this.source || this.parentComponent,
                prefix = parent && typeof parent.currentTemplateStatus === 'function'
                    && parent.currentTemplateStatus() === 'published'
                    ? 'Saved '
                    : 'Draft saved ';

            if (diffSec < 60) {
                this.savedTimeText = prefix + diffSec + 's ago';
            } else if (diffMin < 60) {
                this.savedTimeText = prefix + diffMin + 'm ago';
            } else {
                this.savedTimeText = prefix + diffHour + 'h ago';
            }
        },

        /**
         * Clear all timers and destroy the component.
         */
        destroy: function () {
            if (this._autoSaveTimer) {
                clearTimeout(this._autoSaveTimer);
                this._autoSaveTimer = null;
            }

            if (this._statusUpdateInterval) {
                clearInterval(this._statusUpdateInterval);
                this._statusUpdateInterval = null;
            }

            this._super();
        }
    });
});
