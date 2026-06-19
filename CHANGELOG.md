# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-06-19

### Added
- **Tailwind v4.3 migration.** The editor now uses Tailwind v4 end-to-end. The client-side
  compiler iframe loads `@tailwindcss/browser@4` from jsDelivr and consumes a CSS-first
  `<style type="text/tailwindcss">` block with `@theme {…}` variables instead of v3's JS
  config object. The compiler iframe rebuilds when either the template content or theme
  changes and reuses cached output otherwise; the template HTML is baked into the iframe's
  initial markup so v4's first-pass scan sees every class (including static utilities such
  as `invert` that the previous MutationObserver-based injection silently missed).
- **Comprehensive token → utility derivation in `UtilityCssGenerator`.** Server-side
  derivation now covers every Tailwind v4 token bucket: colors (`.bg-`, `.text-`,
  `.border-`, `.outline-`), spacing (`.m`/`.mx`/`.my`/`.mt`/`.mr`/`.mb`/`.ml`/`.p`/`.px`/
  `.py`/`.pt`/`.pr`/`.pb`/`.pl`/`.w-`/`.h-`), font-size (`.text-`), font-family (`.font-`),
  font-weight (`.font-`, with extraction that excludes `--font-weight-*` from the family
  bucket to avoid namespace collisions), line-height (`.leading-`), letter-spacing
  (`.tracking-`), border-radius (`.rounded-`), box-shadow (`.shadow-`), opacity
  (`.opacity-`), z-index (`.z-`), and max-width (`.max-w-`). Every utility also emits its
  `.\!`-prefixed important variant so overrides like `!bg-primary` can beat baseline rules
  such as `.header { background-color: … }`.
- **Per-store theme awareness.** The editor sends its currently-loaded theme CSS as
  `theme_css` on preview, and `TemplateRenderer::render()` now accepts a `$themeCssOverride`
  parameter. When supplied it takes precedence over the store's default theme, so the
  theme shown in the editor is the theme used to compile the preview.
- **Custom CSS variable merger** (`Api/CustomVariableMergerInterface`,
  `Model/CustomVariableMerger`) for merging user-supplied variables into provider
  variables at preview and test-send time. The `SaveDraft` controller persists
  `sample_provider_code` and `custom_variables` on the draft so reopening a template
  restores the data-source selection.
- **Send Test Email** path rewritten around `TransportBuilder` plus a packaged wrapper
  template (`etc/email_templates.xml`, `view/frontend/email/test_email.html`) - replaces
  the hand-rolled `EmailMessage`/`MimeMessage`/`AddressConverter` plumbing with the
  framework primitive.
- **Custom Data editor panel** (`view/adminhtml/web/template/email-editor/custom-data-editor.html`,
  `view/adminhtml/web/js/email-editor/custom-data-editor.js`) - structured editor for
  template variables exposed as JSON, replacing free-form textarea editing.
- **Schema:** `hryvinskyi_email_template_override` gains `sample_provider_code` and
  `custom_variables` columns for per-override data-source persistence.
- **Editor-context flag** (`Api/EditorContextFlagInterface`, `Model/EditorContextFlag`)
  signals to the runtime plugin that a render is happening inside the admin editor
  preview - so the plugin applies overrides on included templates (header/footer) even
  when the store toggle is off. The toggle now genuinely gates only live transactional
  emails; admin previews always reflect overrides.
- **CSS cascade-layer flattener** (`Api/CssLayerFlattenerInterface`,
  `Model/CssLayerFlattener`) unwraps Tailwind v4's `@layer utilities` (and other
  preserved layers), drops `@layer base`/`@layer properties`/`@property` rules, and is
  applied to both external CSS parameters and `<style>` blocks embedded in the HTML.
  Without this Pelago Emogrifier silently drops every layered rule, leaving Tailwind
  classes uninlined.
- **Sidebar collapse toggle** in the editor toolbar using Lucide
  `panel-left-close`/`panel-left-open` icons; the sidebar slides out with a small
  transition.
- **Auto-expand groups on search.** Typing in the sidebar search input now expands every
  matching group so results are immediately visible; clearing the search restores the
  manual expand/collapse state.
- **Theme-aware override matching** in `EmailTemplatePlugin::loadPublishedOverride()`.
  When a header is included by its base id under a themed store, the plugin tries
  `<templateId>/<themeCode>` first (then the bare id), then the specific store, then
  store 0. An override created against `…/Ikonic/theme` now applies even when the
  template is pulled in by `{{template config_path="design/email/header_template"}}`.
- **Store-scope override fallback** in `TemplateLoader` - the editor's load path now
  mirrors the runtime plugin: tries the selected store first, falls back to store 0.
  Sidebar override badges and the inline editor both surface inherited "All Store Views"
  overrides when a specific store view is selected.
- **Per-store themed default template loading.** `TemplateLoader::loadDefaultTemplate()`
  now wraps Magento's `loadDefault()` in environment emulation when no theme is encoded
  in the identifier and a store is selected. The editor shows the same template file
  that store actually uses (e.g. the Ikonic theme's header) instead of always the base
  module default.
- **Unit test suite** under `Test/Unit/Model/` with a module-local `phpunit.xml.dist`
  bootstrap that needs no Magento boot. Coverage includes the utility-CSS generator
  across every token bucket and the `!` variants, the variable resolver (including the
  `!important`-strip regression and v4's empty-fallback `var(--x,)` form), the lenient
  theme validator (CSS + legacy-JSON acceptance), the cascade-layer flattener, and an
  end-to-end integration test that drives the real Pelago Emogrifier with the full v4
  output shape. 72 tests, 209 assertions.

### Changed
- **Theme storage migrated to Tailwind v4 CSS-first.** The `hryvinskyi_email_theme.theme_json`
  column was renamed to `theme_css` (declarative-schema rename with `migrateDataFrom`).
  Stored JSON payloads are converted in place to a `@theme { … }` block via a new
  `Setup\Patch\Data\MigrateThemeJsonToCss` data patch that preserves every token
  (including custom user overrides). The `ThemeInterface` exposes `getThemeCss()` /
  `setThemeCss()` and `THEME_CSS`; the model keeps reading and writing both columns
  during the transition so the editor works whether or not `setup:upgrade` has run.
  Controllers, JS, AJAX payloads, and the theme-editor CodeMirror mode all switched
  from JSON to CSS, with auto-conversion on load for any row that still carries the
  legacy JSON shape.
- **Theme-editor JS** swaps the CodeMirror mode from JSON to `text/css` and seeds new
  themes with a `@theme { … }` starter template.
- **`UtilityCssGenerator::generate()`** input contract changed from JSON to CSS. The
  legacy JSON shape is auto-detected and routed through a kept-around legacy renderer
  so unmigrated themes still render. The v4 namespace map (`fontSize → text`,
  `fontFamily → font`, `maxWidth → container`, `zIndex → z`, etc.) matches Tailwind
  v4's `@theme` variable naming.
- **`CssInliner::inline()`** runs cascade-layer flattening on both external CSS
  parameters and `<style>` blocks embedded in the HTML, applies the variable resolver
  to embedded blocks as well, and no longer early-returns when CSS parameters are
  empty but embedded styles are present.
- **Iframe rebuild ergonomics.** The compiler iframe uses a 1px offscreen
  `opacity:0`/`pointer-events:none` footprint instead of `visibility:hidden` so the
  browser's idle scheduler isn't throttled, the ready signal fires after 100ms instead
  of 600ms, and `_extractWithRetry()` polls every 80ms for compiled utilities and
  bails as soon as they appear (up to ~3s).
- **`renderPreview()` single-fire.** The editor now waits for the Tailwind compile to
  finish before sending the preview AJAX. The previous double-fire (immediate preview
  with stale CSS, then a second preview with the fresh CSS) flickered the preview
  iframe through an unstyled intermediate state whenever a class changed; the new
  flow shows a single clean transition with the loading spinner bridging the gap.
- **Sidebar tree is now store-aware.** The sidebar's own `_ajax` sends `store_id`, the
  template-tree refreshes when the store view changes, and overrides from store 0
  appear as inherited entries on store-specific views.
- **Module enabled by default in admin previews.** The `hryvinskyi_email_editor/general/enabled`
  config still gates real transactional sends, but the editor preview applies overrides
  unconditionally via the new editor-context flag.

### Fixed
- **`!`-modified Tailwind classes did not win over baseline element rules.** The
  generator never emitted `.\!`-prefixed variants, so `!bg-primary` on the header
  inlined as nothing while `.header { background-color: … }` won. Every utility family
  now emits its important variant.
- **CSS variable resolver carried `!important` into the substituted value.** Tailwind v3's
  `.\!bg-white` compiles `--tw-bg-opacity: 1 !important;` plus
  `background-color: rgb(255 255 255 / var(--tw-bg-opacity, 1))`. The resolver was
  treating the entire declaration value as the variable, producing
  `rgb(255 255 255 / 1 !important)` - invalid CSS that Emogrifier dropped. The
  `!important` flag is now stripped from custom-property values (it belongs to the
  declaration, not the substituted value).
- **CSS variable resolver missed Tailwind v4's empty-fallback form.** `var(--tw-blur,)`
  (no fallback after the comma) is what v4 emits for compositional `filter`/`transform`
  slots; the resolver's regex required ≥1 character after the comma and was leaving
  these refs unresolved. Empty fallback now resolves to empty string.
- **Theme overrides on included templates were rendered as the base default.** The
  runtime plugin's `afterGetProcessedTemplate` ran a separate Emogrifier inlining pass
  on the included header fragment - the header opens an unclosed document for the
  footer to close, and Emogrifier "completed" it (slamming `</body></html>` shut right
  after the header), orphaning the body and footer outside the document and stripping
  their styles. The plugin now embeds the override's CSS as a `<style>` block instead;
  the single top-level inliner applies it to the fully assembled document.
- **Header override CSS embedded by the plugin never reached Emogrifier.** Tailwind v4
  output wraps every utility in `@layer utilities { … }`, and Pelago Emogrifier 7.3
  silently drops every rule inside `@layer`. The plugin now flattens and resolves the
  override CSS before embedding it; the inliner also flattens and resolves any
  `<style>` blocks present in the HTML as defense in depth.
- **`TemplateRenderer::render()` always loaded the DB-default theme.** The editor sends
  its currently-edited theme CSS as `theme_css`, but the renderer ignored it and called
  `getDefaultTheme($storeId)` - so editing the Ikonic theme but previewing showed the
  Default theme's primary color (`#1a1a2e`) instead of Ikonic's (`#131CCF`).
- **Sidebar override badges and the loaded override missed inherited rows.** Every
  override lookup in `TemplateOverrideRepository` filtered by exact `store_id`. A
  store-0 ("All Store Views") published override no longer appeared in the editor when
  a specific store view was selected. The editor's loader now mirrors the send-time
  plugin's `[storeId, 0]` fallback.
- **Saving an autosave wiped a draft's name.** `SaveDraft` treated an absent `draft_name`
  field in the autosave/publish payload as "clear the name", flipping named drafts to
  "Untitled" on the next save. The controller now only overwrites the name when the
  client actually provides it.
- **Body styling stripped when an overridden header was included.** Same root cause as
  the per-fragment Emogrifier pass above - the body's `<p>` lost its email-CSS styles.
  Fixed by the same `<style>` embedding approach.

### Removed
- Stray artifacts from local development (`testdisk.log`,
  `view/adminhtml/web/js/email-editor/tailwind-compiler.js.TRUNCATED`,
  `.phpunit.result.cache`) and added a `.gitignore` to keep them out.

## [1.0.5] - 2026-06-18

### Fixed
- Store switcher in the editor toolbar only listed "All Store Views". `Editor::getStoreList()`
  read `SystemStore::getStoreValuesForForm()`, whose return value is a nested tree
  (website → group → store views) where the actual store views live inside the parent
  entries' `value` arrays. The old loop kept only items with a numeric top-level `value`,
  so only "All Store Views" (value `0`) survived. The list is now built by recursively
  walking the tree and collecting the numeric store-view leaves (with the indentation
  whitespace stripped from labels).

## [1.0.4] - 2026-05-20

### Fixed
- Email logo rendered with an empty `src` attribute in the preview. The sample-data
  providers (`AdminMockBuilder`, `CustomDataProvider`, `LastCustomerProvider`) passed
  `logo_url => ''`, which satisfies `isset()` in Magento's
  `AbstractTemplate::addEmailVariables()` and suppresses resolution of the configured
  `design/email/logo`. The key is now omitted so Magento resolves the real (or default)
  email logo URL.
