/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

define(['jquery'], function ($) {
    'use strict';

    /**
     * TailwindCSS CDN Compiler
     *
     * Creates a hidden iframe that loads TailwindCSS CDN script,
     * injects template HTML, and extracts the generated CSS.
     */
    return {
        /** @type {HTMLIFrameElement|null} */
        _iframe: null,

        /** @type {boolean} */
        _ready: false,

        /** @type {jQuery.Deferred|null} */
        _readyDeferred: null,

        /** @type {string} snapshot of the @theme block last initialised with */
        _appliedThemeKey: '',

        /** @type {string} @theme CSS block awaiting (or driving) the current iframe */
        _themeCss: '',

        /** @type {boolean} true once the current iframe is known to have produced no usable output */
        _compileFailed: false,

        /** @type {number|null} handle of init()'s ready poll */
        _readyInterval: null,

        /** @type {number|null} handle of init()'s ready fallback timeout */
        _readyTimeout: null,

        /**
         * Extract runs currently polling the iframe.
         *
         * Two compiles for the same content share one iframe and therefore poll it side by
         * side, so runs are tracked individually rather than as a single handle.
         *
         * @type {Array<{deferred: jQuery.Deferred, interval: number|null}>}
         */
        _extractRuns: [],

        /** @type {string} the Tailwind v4 browser bundle the iframe loads */
        _CDN_SCRIPT_URL: 'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4',

        /** @type {string} id of the <style> block this compiler writes the theme into */
        _THEME_STYLE_ID: 'ete-tw-theme',

        /** @type {string} id of the wrapper the scanned template markup is baked into */
        _CONTENT_WRAPPER_ID: 'tw-content',

        /** @type {number} cadence of init()'s ready poll, ms */
        _READY_POLL_MS: 50,

        /** @type {number} cadence of the output poll, ms */
        _EXTRACT_POLL_MS: 80,

        /** @type {number} output polls before giving up (~1s at the poll cadence) */
        _EXTRACT_MAX_ATTEMPTS: 12,

        /** @type {number} output poll at which the structural failure check is evaluated */
        _EXTRACT_GRACE_TICK: 3,

        /**
         * Map of legacy JSON token sections to Tailwind v4 @theme namespace prefixes.
         * Used by setTheme() to auto-convert unmigrated stored themes on the fly.
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
         * Convert a legacy JSON theme payload into a v4 @theme CSS block.
         *
         * @param {string} input
         * @returns {string} v4 @theme CSS, or the input unchanged when not legacy JSON
         * @private
         */
        _normalizeTheme: function (input) {
            var trimmed = (input || '').replace(/^\s+/, ''),
                data,
                tokens,
                section,
                prefix,
                bucket,
                name,
                lines = [];

            if (trimmed.charAt(0) !== '{') {
                return input;
            }

            try {
                data = JSON.parse(trimmed);
            } catch (e) {
                return input;
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

            return lines.length ? '@theme {\n' + lines.join('\n') + '\n}' : '';
        },

        /**
         * Push the editor's theme into the compiler.
         *
         * The input is a Tailwind v4 CSS-first theme string (a `@theme { … }` block plus any
         * surrounding CSS). Legacy JSON themes from the pre-v4 storage shape are auto-
         * normalized to v4 CSS, so the editor renders correctly even before the storage
         * migration runs. The iframe is rebuilt on next compile() whenever the content
         * changes so Tailwind regenerates utility rules.
         *
         * @param {string} themeCss
         */
        setTheme: function (themeCss) {
            this._themeCss = this._normalizeTheme(themeCss == null ? '' : String(themeCss));
        },

        /**
         * Initialize the hidden iframe with TailwindCSS CDN
         *
         * @returns {jQuery.Deferred}
         */
        init: function () {
            if (this._readyDeferred) {
                return this._readyDeferred;
            }

            var self = this,
                deferred = $.Deferred(),
                themeCss = this._themeCss || '',
                bakedHtml = this._bakedHtml || '',
                classShadow = this._collectClassNames(bakedHtml),
                iframe;

            this._readyDeferred = deferred;
            this._appliedThemeKey = themeCss + '\0' + bakedHtml;
            // A fresh iframe is a fresh verdict, and this is the only place the verdict is
            // cleared. The ordering is load-bearing: compile() tears the old iframe down and
            // builds a new one, and the teardown settles whatever it abandons *before* this
            // runs - so an abandoned caller still sees that its empty string was an
            // abandonment rather than a template without utility classes, while the compile
            // that replaces it starts from a clean slate.
            this._compileFailed = false;

            this._iframe = document.createElement('iframe');
            // Tiny on-screen footprint with opacity:0 (instead of visibility:hidden) so the
            // browser doesn't throttle the v4 bundle's idle scheduler - hidden iframes can
            // skip requestIdleCallback ticks for many seconds, which is what was making
            // first-time compiles feel slow.
            this._iframe.style.cssText =
                'position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;border:none;opacity:0;pointer-events:none;';
            this._iframe.sandbox = 'allow-scripts allow-same-origin';
            document.body.appendChild(this._iframe);

            var iframeDoc = this._iframe.contentDocument || this._iframe.contentWindow.document;

            iframeDoc.open();
            iframeDoc.write([
                '<!DOCTYPE html>',
                '<html><head>',
                // This iframe is created via document.write (no src), so it
                // inherits the parent admin document's base URL. Without an
                // explicit base, relative or unresolved URLs in the scanned
                // template (e.g. "{{var logo_url}}") would be fetched against
                // "emaileditor/editor/index/...", crashing the admin controller.
                // Anchoring the base at the site origin keeps any stray request
                // harmless (a frontend 404 instead of an admin 500).
                '<base href="' + window.location.origin + '/">',
                // The id is how the failure check tells the block we wrote from the stylesheet
                // the bundle appends. The bundle selects its inputs by `type`, so carrying an
                // id in addition is inert.
                '<style id="' + this._THEME_STYLE_ID + '" type="text/tailwindcss">',
                themeCss,
                '</style>',
                '<script>',
                'window._twReady = false;',
                'window._twLoadFailed = false;',
                'window._twCompileMarker = "__TW_DONE__";',
                '<\/script>',
                // Tailwind v4 browser build. The script does its initial class-name scan during
                // page load - so the template HTML must already be in the body when the script
                // executes. That's why we bake the content into the initial document write
                // rather than injecting it into an empty body afterwards via innerHTML, which
                // proved unreliable (MutationObserver-driven re-scans in a hidden iframe were
                // missing late-arriving classes such as `invert` and even custom token utilities
                // like `!bg-primary`).
                // The onerror attribute is the primary "the bundle never ran" signal. A script
                // that is blocked by the page's content security policy and a script whose
                // fetch fails both surface as a network error on the element, so both raise it.
                // Inline handlers execute here for the same reason the ready bootstrap below
                // does: the admin policy permits inline script.
                '<script src="' + this._CDN_SCRIPT_URL + '" onerror="window._twLoadFailed = true;"><\/script>',
                '<script>',
                // Mark ready quickly; the output poll then watches for actual utility output,
                // so we never depend on a single fixed sleep being "enough". Readiness alone is
                // not evidence that anything compiled - this handler runs whether or not the
                // bundle above executed, which is why failure is detected separately.
                'document.addEventListener("DOMContentLoaded", function() {',
                '  setTimeout(function() { window._twReady = true; }, 100);',
                '});',
                '<\/script>',
                '</head><body>',
                // Belt-and-braces class-name surface for Tailwind v4's scanner. The original
                // template HTML often interleaves Magento `{{if ...}}` / `{{var ...}}` directives
                // *inside* tag attribute lists (e.g. <img {{if logo_width}} width=... class="invert"/>);
                // v4's text-based scanner can lose track of the class attribute on such elements
                // and silently skip its utilities. Surfacing every class name found anywhere in
                // the source via this clean wrapper guarantees the scanner sees them all.
                '<div id="tw-class-shadow" hidden aria-hidden="true" class="' + classShadow + '"></div>',
                '<div id="' + this._CONTENT_WRAPPER_ID + '">',
                bakedHtml,
                '</div>',
                '</body></html>'
            ].join(''));
            iframeDoc.close();

            iframe = this._iframe;

            iframe.onload = function () {
                if (self._iframe !== iframe) {
                    // Superseded before it finished loading. The teardown that replaced it has
                    // already settled everything waiting on this document, and arming timers
                    // now would point them at the iframe that took its place.
                    return;
                }

                if (self._pollReady(iframe, deferred)) {
                    return;
                }

                self._readyInterval = setInterval(function () {
                    self._pollReady(iframe, deferred);
                }, self._READY_POLL_MS);

                self._readyTimeout = setTimeout(function () {
                    self._clearReadyTimers();

                    if (!self._ready) {
                        self._ready = true;
                        deferred.resolve();
                    }
                }, 5000);
            };

            return deferred.promise();
        },

        /**
         * Read the in-iframe ready flag once and settle init()'s deferred when it has flipped.
         *
         * Called immediately when the document loads and then on every poll. The immediate call
         * is what makes the poll cadence nearly free: the iframe raises the flag 100 ms after
         * its own DOMContentLoaded, which has usually already passed by the time the load event
         * fires, so waiting for a first tick before looking would cost a full interval on every
         * compile - and the iframe is torn down and rebuilt on every content change.
         *
         * @param {HTMLIFrameElement} iframe The document this poll belongs to
         * @param {jQuery.Deferred} deferred init()'s deferred
         * @returns {boolean} True once the deferred has been settled and polling can stop
         * @private
         */
        _pollReady: function (iframe, deferred) {
            try {
                if (!iframe.contentWindow._twReady) {
                    return false;
                }
            } catch (e) {
                this._clearReadyTimers();
                deferred.reject(e);

                return true;
            }

            this._clearReadyTimers();
            this._ready = true;
            deferred.resolve();

            return true;
        },

        /**
         * Stop init()'s ready poll and its fallback timeout.
         *
         * Both close over the compiler rather than over one document, so a timer that outlives
         * its iframe would go on to observe whichever iframe replaced it and settle the wrong
         * document's deferred.
         *
         * @returns {void}
         * @private
         */
        _clearReadyTimers: function () {
            if (this._readyInterval !== null) {
                clearInterval(this._readyInterval);
                this._readyInterval = null;
            }

            if (this._readyTimeout !== null) {
                clearTimeout(this._readyTimeout);
                this._readyTimeout = null;
            }
        },

        /**
         * Compile HTML content and return the extracted Tailwind CSS.
         *
         * Each compile bakes the template HTML into a fresh iframe at init time so the
         * Tailwind v4 browser bundle sees every class name during its initial scan. The
         * iframe is reused (no rebuild + no CDN re-fetch) when neither the template HTML
         * nor the theme CSS has changed.
         *
         * Always resolves, and always with a string - '' when nothing could be generated. Use
         * hasCompileFailed() to tell an empty result that means "this template has no utility
         * classes" from one that means "nothing compiled it".
         *
         * @param {string} htmlContent
         * @returns {jQuery.Deferred} Resolves with the generated CSS string
         */
        compile: function (htmlContent) {
            var self = this,
                deferred = $.Deferred(),
                stripped = this._stripResourceUrls(htmlContent || ''),
                desiredKey = (this._themeCss || '') + '\0' + stripped;

            // Fast path: nothing changed since the last successful compile.
            if (this._ready && desiredKey === this._appliedThemeKey && typeof this._lastCss === 'string') {
                deferred.resolve(this._lastCss);

                return deferred.promise();
            }

            // Tear down and rebuild whenever either the theme or template content changed,
            // so v4 picks up new classes (`invert`, `!bg-primary`, ...) at script-load time.
            if (this._iframe && desiredKey !== this._appliedThemeKey) {
                this.destroy();
            }

            this._bakedHtml = stripped;

            this.init().done(function () {
                self._extractWithRetry(deferred);
            }).fail(function () {
                self._reportCompileFailure('the compiler iframe never became reachable');
                deferred.resolve('');
            });

            return deferred.promise();
        },

        /**
         * Whether the most recent compile produced no usable output.
         *
         * True when the browser bundle never ran - blocked, offline, or an unreachable host -
         * and true when a compile still in flight was abandoned because the iframe was rebuilt
         * underneath it. In both cases compile() still resolves with an empty string, so this
         * is the only way to tell "there was nothing to compile" from "nothing compiled it",
         * and a caller that persists the result should refuse to overwrite a good stored value
         * with an empty one while this is true.
         *
         * Failure is a property of the iframe and its script, not of an individual compile
         * call, which is why it is asked of the compiler rather than carried by the promise:
         * the resolved value stays a plain CSS string, so no existing caller has to change.
         *
         * @returns {boolean}
         */
        hasCompileFailed: function () {
            return this._compileFailed;
        },

        /**
         * Whether the iframe reported that the Tailwind bundle failed to load.
         *
         * This is the trustworthy signal. The ready flag is raised from a DOMContentLoaded
         * handler inside the iframe that runs whether or not the bundle executed, so readiness
         * says only that the document parsed - never that there is a compiler in it.
         *
         * @returns {boolean}
         * @private
         */
        _bundleLoadErrored: function () {
            try {
                return !!(this._iframe &&
                    this._iframe.contentWindow &&
                    this._iframe.contentWindow._twLoadFailed);
            } catch (e) {
                return false;
            }
        },

        /**
         * Whether the bundle has appended no stylesheet of its own.
         *
         * The bundle's one observable side effect is that it creates a <style> element of its
         * own and appends it; it only ever reads the block we hand it. So the block we wrote
         * carries an id and is excluded here, and <style> blocks that arrived as part of the
         * template are baked into the content wrapper and are excluded too - neither is
         * evidence that anything ran.
         *
         * The CSS text is deliberately not consulted. A theme may itself contain a nested
         * `@layer`, which reads exactly like compiled output, so a content-based test would
         * report success on precisely the themes most likely to be in use.
         *
         * @param {Document} iframeDoc
         * @returns {boolean}
         * @private
         */
        _bundleStyleMissing: function (iframeDoc) {
            var styles = iframeDoc.querySelectorAll('style:not(#' + this._THEME_STYLE_ID + ')'),
                i;

            for (i = 0; i < styles.length; i++) {
                if (!this._isInsideContentWrapper(styles[i])) {
                    return false;
                }
            }

            return true;
        },

        /**
         * Whether a node sits inside the wrapper the scanned template markup is baked into.
         *
         * @param {Node} node
         * @returns {boolean}
         * @private
         */
        _isInsideContentWrapper: function (node) {
            var parent = node.parentNode;

            while (parent) {
                if (parent.id === this._CONTENT_WRAPPER_ID) {
                    return true;
                }

                parent = parent.parentNode;
            }

            return false;
        },

        /**
         * Record that the current iframe produced no usable output, and say so once.
         *
         * Only the first report against a given iframe is logged, and the flag is cleared when
         * a new iframe is built, so a lasting outage warns once per compile instead of once per
         * poll. No user-facing message is raised from here: a preview that renders unstyled is
         * already visible, and messaging belongs to the component that owns the screen.
         *
         * @param {string} reason What was observed
         * @returns {void}
         * @private
         */
        _reportCompileFailure: function (reason) {
            var alreadyReported = this._compileFailed;

            this._compileFailed = true;

            if (!alreadyReported && window.console && typeof window.console.warn === 'function') {
                window.console.warn(
                    'TailwindCSS compile produced no output: ' + reason + '. The browser bundle at ' +
                    this._CDN_SCRIPT_URL + ' did not run - check that the host is reachable and ' +
                    'permitted by the admin content security policy. Utility classes will be missing ' +
                    'from the preview and from any CSS stored for this template.'
                );
            }
        },

        /**
         * Poll the iframe for Tailwind output and resolve once utility rules appear.
         *
         * v4's first output frame can race the ready signal: the bundle marks itself
         * ready immediately but may take another tick to write compiled CSS into the
         * document. Polling avoids both the false-empty extract and a worst-case fixed
         * sleep that's longer than necessary.
         *
         * Polling is also how a bundle that never ran is told apart from one that is merely
         * slow. The load-error flag is read before the first tick is paid for - an error raised
         * while the document was parsing has long since fired by then - and again on every
         * tick; a structural check for the stylesheet the bundle appends runs once at a fixed
         * grace tick as a backstop for a script that was fetched but did not execute. Either
         * verdict resolves straight away, so a broken bundle costs a fraction of the exhaustion
         * budget rather than all of it.
         *
         * Exhaustion itself is deliberately *not* treated as failure: after a full budget with
         * the bundle present, an empty extract is genuinely ambiguous, and claiming failure
         * there would freeze the stored CSS of a template that really has no utility classes.
         *
         * @param {jQuery.Deferred} deferred Resolved with the extracted CSS, or '' if there is none
         * @returns {void}
         * @private
         */
        _extractWithRetry: function (deferred) {
            var self = this,
                started = 0,
                run = {deferred: deferred, interval: null};

            this._extractRuns.push(run);

            if (this._bundleLoadErrored()) {
                this._reportCompileFailure('the bundle script reported a load error');
                this._settleExtractRun(run, '');

                return;
            }

            run.interval = setInterval(function () {
                started++;

                try {
                    var iframeDoc = self._iframe.contentDocument || self._iframe.contentWindow.document,
                        css,
                        hasUtilities;

                    if (self._bundleLoadErrored()) {
                        self._reportCompileFailure('the bundle script reported a load error');
                        self._settleExtractRun(run, '');

                        return;
                    }

                    if (started === self._EXTRACT_GRACE_TICK && self._bundleStyleMissing(iframeDoc)) {
                        self._reportCompileFailure('the bundle appended no stylesheet of its own');
                        self._settleExtractRun(run, '');

                        return;
                    }

                    css = self._extractCss(iframeDoc);
                    hasUtilities = css && (
                        css.indexOf('@layer utilities') !== -1 ||
                        css.indexOf('@layer') !== -1 ||
                        /\.[\w\\!-]+\s*\{/.test(css)
                    );

                    if (hasUtilities || started >= self._EXTRACT_MAX_ATTEMPTS) {
                        self._lastCss = css || '';
                        self._settleExtractRun(run, self._lastCss);
                    }
                } catch (e) {
                    self._reportCompileFailure('the compiler iframe became unreadable');
                    self._settleExtractRun(run, '');
                }
            }, this._EXTRACT_POLL_MS);
        },

        /**
         * Stop one extract run's polling, forget it, and hand its caller the CSS it produced.
         *
         * Runs are settled by identity because several can poll the same iframe at once -
         * otherwise one run would clear another's timer and resolve another caller's promise
         * while its own was left pending forever.
         *
         * The last known-good CSS is not touched here on the failure paths: leaving it unset
         * keeps compile()'s fast path from handing out an empty result as though it were a
         * successful compile of the same content.
         *
         * @param {{deferred: jQuery.Deferred, interval: number|null}} run
         * @param {string} css CSS to resolve with, '' when the compile produced none
         * @returns {void}
         * @private
         */
        _settleExtractRun: function (run, css) {
            var index = this._extractRuns.indexOf(run);

            if (run.interval !== null) {
                clearInterval(run.interval);
                run.interval = null;
            }

            if (index !== -1) {
                this._extractRuns.splice(index, 1);
            }

            run.deferred.resolve(css);
        },

        /**
         * Remove everything that triggers a network fetch from the scanned HTML.
         *
         * The compiler only needs the elements' class names to let TailwindCSS
         * generate its utility CSS; it never needs images, scripts, stylesheets
         * or media to load. Injecting raw template markup would make the browser
         * fetch unresolved directives such as "{{var logo_url}}" as URLs. This
         * strips resource-bearing elements and attributes (keeping classes and
         * structure intact) so no request is ever issued.
         *
         * @param {string} html
         * @returns {string}
         * @private
         */
        _stripResourceUrls: function (html) {
            var doc,
                removable,
                urlAttrs = ['src', 'srcset', 'poster', 'background', 'data-src', 'data-srcset', 'xlink:href'],
                all,
                el,
                style,
                i,
                j;

            try {
                doc = new DOMParser().parseFromString(html, 'text/html');
            } catch (e) {
                return html;
            }

            if (!doc || !doc.body) {
                return html;
            }

            removable = doc.querySelectorAll('script, link, iframe, object, embed, source, track');
            for (i = 0; i < removable.length; i++) {
                if (removable[i].parentNode) {
                    removable[i].parentNode.removeChild(removable[i]);
                }
            }

            all = doc.body.querySelectorAll('*');
            for (i = 0; i < all.length; i++) {
                el = all[i];

                for (j = 0; j < urlAttrs.length; j++) {
                    el.removeAttribute(urlAttrs[j]);
                }

                style = el.getAttribute('style');
                if (style && /url\s*\(/i.test(style)) {
                    el.setAttribute('style', style.replace(/url\s*\([^)]*\)/gi, 'none'));
                }
            }

            return doc.body.innerHTML;
        },

        /**
         * Collect every class name that appears anywhere in the source HTML.
         *
         * Tailwind v4's scanner is text-based and can drop class attributes that sit
         * next to Magento `{{if ...}}` / `{{var ...}}` directives inside the same tag
         * (the directives confuse its attribute tokenizer). Surfacing every class
         * name in a separate clean wrapper element guarantees the scanner sees them
         * all regardless of how messy the original markup is.
         *
         * @param {string} html
         * @returns {string} space-separated, HTML-escaped class-name list
         * @private
         */
        _collectClassNames: function (html) {
            if (!html) {
                return '';
            }

            var classRegex = /class\s*=\s*(?:"([^"]*)"|'([^']*)')/gi,
                seen = Object.create(null),
                ordered = [],
                match,
                token,
                names,
                i;

            while ((match = classRegex.exec(html)) !== null) {
                names = (match[1] || match[2] || '').split(/\s+/);
                for (i = 0; i < names.length; i++) {
                    token = names[i];
                    if (!token || seen[token]) {
                        continue;
                    }
                    seen[token] = true;
                    ordered.push(token);
                }
            }

            return ordered.join(' ')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        },

        /**
         * Extract the generated Tailwind CSS from the iframe document
         *
         * @param {Document} iframeDoc
         * @returns {string}
         * @private
         */
        _extractCss: function (iframeDoc) {
            var styles = iframeDoc.querySelectorAll('style'),
                css = '',
                styleText,
                i;

            for (i = 0; i < styles.length; i++) {
                styleText = styles[i].textContent || '';

                if (styleText.indexOf('tailwind') !== -1 ||
                    styleText.indexOf('--tw-') !== -1 ||
                    styleText.indexOf('@layer') !== -1 ||
                    styleText.indexOf('@property') !== -1 ||
                    styleText.indexOf('.bg-') !== -1 ||
                    styleText.indexOf('.text-') !== -1 ||
                    styleText.indexOf('.p-') !== -1 ||
                    styleText.indexOf('.m-') !== -1 ||
                    styleText.indexOf('.flex') !== -1 ||
                    styleText.indexOf('.grid') !== -1) {
                    css += styleText + '\n';
                }
            }

            return css.trim();
        },

        /**
         * Destroy the iframe, stop its timers and settle everything still waiting on it.
         *
         * This runs from compile() on every content change, not only when the editor closes,
         * so abandoning a caller here is routine. Two rules follow from that.
         *
         * Every timer must be cleared: they close over the compiler, not over one document, so
         * a survivor would go on to poll the iframe that replaced this one and write its
         * partially generated stylesheet into the shared cache, which the fast path would then
         * hand out indefinitely.
         *
         * And every pending promise must be settled, because the poll that was cleared is the
         * only thing that would ever have resolved it - a caller left waiting waits forever,
         * and a preview keeps its loading overlay up until its own request completes. The
         * failure flag is raised before anything is settled: those handlers run synchronously
         * from here, and the empty string an abandoned caller receives is not a template
         * without utility classes.
         */
        destroy: function () {
            var pendingReady = this._readyDeferred,
                abandoned = this._extractRuns,
                i;

            this._clearReadyTimers();

            if (this._iframe && this._iframe.parentNode) {
                this._iframe.parentNode.removeChild(this._iframe);
            }

            this._iframe = null;
            this._ready = false;
            this._readyDeferred = null;
            this._appliedThemeKey = '';
            this._lastCss = null;
            this._extractRuns = [];

            if (abandoned.length || (pendingReady && pendingReady.state() === 'pending')) {
                this._compileFailed = true;
            }

            for (i = 0; i < abandoned.length; i++) {
                this._settleExtractRun(abandoned[i], '');
            }

            if (pendingReady && pendingReady.state() === 'pending') {
                pendingReady.reject();
            }
        }
    };
});
