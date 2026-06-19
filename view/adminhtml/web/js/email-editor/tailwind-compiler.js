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
                bakedHtml = this._bakedHtml || '';

            this._readyDeferred = deferred;
            this._appliedThemeKey = themeCss + '\0' + bakedHtml;

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
                '<style type="text/tailwindcss">',
                themeCss,
                '</style>',
                '<script>',
                'window._twReady = false;',
                'window._twCompileMarker = "__TW_DONE__";',
                '<\/script>',
                // Tailwind v4 browser build. The script does its initial class-name scan during
                // page load - so the template HTML must already be in the body when the script
                // executes. That's why we bake the content into the initial document write
                // rather than injecting it into an empty body afterwards via innerHTML, which
                // proved unreliable (MutationObserver-driven re-scans in a hidden iframe were
                // missing late-arriving classes such as `invert` and even custom token utilities
                // like `!bg-primary`).
                '<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"><\/script>',
                '<script>',
                // Mark ready quickly; _extractWithRetry then polls every 125ms for actual
                // utility output, so we never depend on a single fixed sleep being "enough".
                'document.addEventListener("DOMContentLoaded", function() {',
                '  setTimeout(function() { window._twReady = true; }, 100);',
                '});',
                '<\/script>',
                '</head><body>',
                '<div id="tw-content">',
                bakedHtml,
                '</div>',
                '</body></html>'
            ].join(''));
            iframeDoc.close();

            this._iframe.onload = function () {
                var checkReady = setInterval(function () {
                    try {
                        if (self._iframe.contentWindow._twReady) {
                            clearInterval(checkReady);
                            self._ready = true;
                            deferred.resolve();
                        }
                    } catch (e) {
                        clearInterval(checkReady);
                        deferred.reject(e);
                    }
                }, 200);

                setTimeout(function () {
                    clearInterval(checkReady);

                    if (!self._ready) {
                        self._ready = true;
                        deferred.resolve();
                    }
                }, 5000);
            };

            return deferred.promise();
        },

        /**
         * Compile HTML content and return the extracted Tailwind CSS.
         *
         * Each compile bakes the template HTML into a fresh iframe at init time so the
         * Tailwind v4 browser bundle sees every class name during its initial scan. The
         * iframe is reused (no rebuild + no CDN re-fetch) when neither the template HTML
         * nor the theme CSS has changed.
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
                deferred.resolve('');
            });

            return deferred.promise();
        },

        /**
         * Poll the iframe for Tailwind output and resolve once utility rules appear.
         *
         * v4's first output frame can race the ready signal: the bundle marks itself
         * ready immediately but may take another tick to write compiled CSS into the
         * document. Polling avoids both the false-empty extract and a worst-case fixed
         * sleep that's longer than necessary.
         *
         * @param {jQuery.Deferred} deferred
         * @private
         */
        _extractWithRetry: function (deferred) {
            var self = this,
                started = 0,
                maxAttempts = 40, // ~3.2s at 80ms cadence
                interval;

            interval = setInterval(function () {
                started++;

                try {
                    var iframeDoc = self._iframe.contentDocument || self._iframe.contentWindow.document,
                        css = self._extractCss(iframeDoc),
                        hasUtilities = css && (
                            css.indexOf('@layer utilities') !== -1 ||
                            css.indexOf('@layer') !== -1 ||
                            /\.[\w\\!-]+\s*\{/.test(css)
                        );

                    if (hasUtilities || started >= maxAttempts) {
                        clearInterval(interval);
                        self._lastCss = css || '';
                        deferred.resolve(self._lastCss);
                    }
                } catch (e) {
                    clearInterval(interval);
                    deferred.resolve('');
                }
            }, 80);
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
         * Destroy the iframe and clean up resources
         */
        destroy: function () {
            if (this._iframe && this._iframe.parentNode) {
                this._iframe.parentNode.removeChild(this._iframe);
            }

            this._iframe = null;
            this._ready = false;
            this._readyDeferred = null;
            this._appliedThemeKey = '';
            this._lastCss = null;
        }
    };
});
