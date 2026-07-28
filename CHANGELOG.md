# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-07-28

Contains the changes listed under 1.1.3, which was written but never tagged.

### Added
- **Every `{{...}}` directive in the editor is interactive.** Clicking one opens an inspector that
  states what the directive is, how its value is produced, what it currently renders as, what its
  `|...` chain does, and where the value can be changed — a deep link into the admin, written
  instructions when no page edits it, or an editor that writes the value back to its origin.
- **`etc/email_variables.xml`** — the knowledge base, a merged configuration with an XSD that any
  module can contribute to. ~90 hand-written entries: every directive kind, the configuration paths a
  message may read, the store and custom variables, the variables each family of templates is sent,
  and the design values. Three derived providers fill the rest from Magento's own configuration
  structure, the custom-variable table and each template's `@vars` annotation. Providers resolve
  first-match-wins by `sortOrder`, so an entry written by hand always beats a derived one.
- **A directive nothing describes falls back to its kind's entry**, returned under the reference that
  was asked about and carrying a caveat saying so. Without it every `{{trans}}`, `{{block}}` and
  `{{layout}}` reads as undocumented, because their expression cannot be enumerated.
- **The formatting of a directive is editable from the inspector.** The rewrite is bounded to the
  modifier span and re-derived from a live mark before every write, so a formatting change cannot
  alter what the directive points at, and a write is refused outright when the directive moved or
  changed underneath.
- **Values can be edited in place where that is safe.** Configuration paths and custom variables only,
  behind six gates, writing through `PreparedValueFactory` and the configuration resource so the
  field's backend model validates the value exactly as the admin form would. The scope is named before
  the write and the scope that actually landed is read back and shown; a write into the default scope
  needs an explicit confirmation.
- **`{{trans}}` shows what it renders**, read inside a frontend environment emulation for the store
  view being edited — read as the request stands, the phrase would be translated against the
  administrator's locale, which is a different answer that looks like the right one.
- **`i18n/en_US.csv`** — the module's first dictionary, 894 rows, covering the whole module rather than
  only the new work. Hand-maintained and pinned by a coverage test in both directions:
  `i18n:collect-phrases` cannot see a `$t()` literal split across lines, nor XML text nodes at all.
- **`Test/Js/`** — the module's first JavaScript test harness. `node Test/Js/run.js` from the module
  root. Each module is evaluated in a context whose only global is `define`, so one that reaches for
  `document` or declares a dependency fails to load.

### Fixed
- **The variable chooser's request was invisible to the editor** — not counted by the busy indicator,
  not reachable by the cancellation sweep, and with no failure handler at all, so a failed load left
  the panel spinning in silence. It is now tracked, reported, and guarded against a superseded answer.
- **Variable groups were keyed by their English label**, which was also the key of the collapsed-state
  map: in any non-English admin locale every collapsed group was forgotten, two groups translated alike
  collapsed together, and searching reopened them. Groups now carry a code beside their label.
- **The theme scope selector offered a single option.** It was built from a store list read out of the
  page's global configuration object, which does not carry one — so the control documented as the only
  supported way to return a theme to the global scope had never worked. The same defect blanked the
  store view named by an inline write. Both components now ask the orchestrator, which owns the store
  switcher.
- **The unit suite depended on `generated/code`.** Several tests mock DI-generated factories, which have
  no source file and are written by the running application, so the suite failed and passed on alternate
  runs. The bootstrap now stands in for a factory that is genuinely absent, after Composer's autoloader
  so a real one always wins.

## [1.1.3] - 2026-07-28

### Added
- **`oklch()` / `oklab()` are converted to sRGB.** Tailwind v4's entire default palette is
  authored in OKLCH, so `border-gray-700`, `text-red-500` and every other stock colour
  utility emitted `oklch(37.3% .034 259.733)` into the inline styles. Outlook, Yahoo and
  every pre-2023 client drop such a declaration outright, which for a colour property means
  falling back to `currentColor` or the inherited value. New `CssColorConverterInterface` /
  `CssColorConverter` converts both notations through OKLab to sRGB (Björn Ottosson's
  matrices, out-of-gamut values clamped in linear light), emitting `#rrggbb` or `rgba()`
  when the colour carries an alpha. Verified against the palette Tailwind documents:
  `oklch(37.3% .034 259.733)` → `#364153`, `oklch(63.7% .237 25.331)` → `#fb2c36`,
  `oklch(62.3% .214 259.815)` → `#2b7fff`. Percentage and unitless lightness, percentage
  chroma, `deg`/`grad`/`rad`/`turn` hues, `none` channels and `/ alpha` are all handled;
  `calc()` channels, unresolved `var()` and the relative-colour `from` syntax are left
  untouched rather than mangled. The conversion runs after variable substitution, since the
  palette lives behind `--color-*` variables.

### Fixed
- **Border utilities rendered no border.** Tailwind v4 emits `border-2` as
  `border-style: var(--tw-border-style); border-width: 2px` and keeps the actual value in
  an `@property --tw-border-style { … initial-value: solid }` registration, mirrored by an
  `@layer properties` fallback. `CssLayerFlattener` dropped *both*, so the `var()` stayed
  unresolvable, inlined verbatim, and computed to `border-style: none` — a 2px border that
  is never painted. The flattener now harvests every `initial-value` it registers and
  hoists them into a leading `:root { … }` block before dropping the `@property` rules, so
  the resolver can substitute them, and hoisting them *first* means any later declaration
  still wins in the resolver's last-wins map. This also repairs the other v4 composition
  slots that were left dangling for the same reason (`--tw-shadow`,
  `--tw-ring-offset-shadow`, `--tw-inset-shadow`, …). Note the pre-existing limitation this
  does **not** fix: the resolver's variable map is document-global, so a single
  `.border-dashed` rule anywhere in the stylesheet still makes every `border-*` utility
  dashed. That needs per-rule scope in the resolver and is tracked separately.
- **Editor CSS lost to the stock template styles.** Magento's `{{inlinecss}}` directive
  writes `css/email-inline.css` into `style="…"` attributes from an after-filter callback
  inside `AbstractTemplate::getProcessedTemplate()` — strictly before this module inlines
  the override's Tailwind/custom CSS. Emogrifier deliberately re-applies pre-existing
  `style` attributes *after* every stylesheet rule, so those declarations won regardless of
  selector specificity: `class="text-black"` on a link inside an included header/footer
  still rendered the theme's `a { color: … }`. New `CssImportantPromoterInterface` /
  `CssImportantPromoter` promotes every declaration of the editor's own CSS to
  `!important`, which is the one thing Emogrifier honours over a pre-existing inline value.
  Applied in `CssInliner` (theme/tailwind/custom parameters) and in `EmailTemplatePlugin`
  before the override CSS is embedded as a `<style>` block, so an override on a template
  pulled in via `{{template config_path="…"}}` behaves the same as one edited directly.
  Writing `!text-black` is no longer necessary. Emogrifier strips the annotation from the
  final inline styles, so the sent email carries no `!important` in its `style` attributes.
  Base-template `<style>` blocks travelling in the markup are deliberately left alone.
- **Escaped Tailwind classes were ranked as element selectors.** Emogrifier weighs selector
  precedence by counting `.`/`[`/`:` followed by a *word* character, so every class that
  needs escaping — `.\!text-black`, `.p-\[10px\]`, `.w-1\/2`, `.p-1\.5` — scored 1 instead
  of 100 and was applied *before* any plain class rule rather than after it. `CssInliner`
  now restates escape-bearing class selectors as the equivalent `[class~="…"]` attribute
  selector, which matches the same elements and is weighed as a class.

- The modern-syntax `rgb()` / `hsl()` conversion missed a leading-dot alpha (`rgb(255 0 0 /
  .5)`), which is what minified Tailwind output emits, so the whole colour survived in a
  notation Emogrifier's parser and older clients do not understand. An angle unit on the
  `hsl()` hue (`hsl(200deg 50% 50%)`) had the same effect. Both are now accepted.
- `CssInliner` concatenated its CSS parameters custom → tailwind → theme while
  `EmailTemplatePlugin::buildCombinedCss` used theme → tailwind → custom, so the editor
  preview and the delivered email resolved a conflict between two equally specific rules in
  opposite directions. Both now use theme → tailwind → custom.

### Changed
- Because the editor's CSS is now uniformly `!important`, source order decides between two
  equally specific rules inside it. Custom CSS comes last in the bundle and therefore still
  wins; a `!`-prefixed utility no longer outranks a later same-specificity rule from the
  same bundle purely on the strength of its flag.

## [1.1.2] - 2026-06-19

### Fixed
- Opening an existing legacy DB-customised email template from Magento's
  "Transactional Emails" grid (`iopaneladmin/admin/email_template/index/`) previously
  rendered the bundled file default in the new editor instead of the override the admin
  had set in the legacy grid. The redirect plugin dropped the legacy row's primary key
  and `TemplateLoader` never consulted the legacy `email_template` table for content, so
  any customisation looked like it had vanished. The editor now opens with the legacy
  row's stored `template_text` / `template_subject` / `template_styles` seeded for
  editing, and shows a "Seeded from a legacy Magento email template" banner until the
  first save migrates the content into a managed override.

### Added
- **Legacy `email_template` row integration.** Templates customised via Magento's
  standard "Transactional Emails" grid now appear in the editor sidebar as a
  "Magento DB"-badged override child of their `orig_template_code` parent, scoped
  to the store views their `template_id` is referenced from in `core_config_data`
  (resolved through `BackendTemplate::getSystemConfigPathsWhereCurrentlyUsed`). A
  legacy entry is hidden once a managed override exists for the same identifier in
  either the requested scope or the default scope, since the runtime overlay
  represents it through the managed row in that case. Save Draft / Publish create a
  managed `hryvinskyi_email_template_override` through the existing flow; the legacy
  row and `core_config_data` are left untouched.
- `EmailTemplatePlugin` gained an `afterLoad` hook (alongside the existing
  `afterLoadDefault`) that overlays a matching managed override onto loads from the
  `email_template` table — i.e. system-config bindings that reference a numeric
  `template_id`. Combined with the seed-on-edit flow, edits to legacy templates apply
  at send time without rewriting any `core_config_data` row. The overlay body is
  factored into a shared `applyOverlay` helper. Legacy rows without an
  `orig_template_code` fall back to `template_code` for the overlay lookup so wholly
  custom legacy templates also pick up edits.
- New `Api/LegacyTemplateRepositoryInterface` + `Model/LegacyTemplateRepository`
  wraps `Magento\Email\Model\BackendTemplate` to expose `getByOrigCode`, `getById`
  (with `pluginBypassFlag->enable()` so the runtime overlay does not recursively
  fire for the editor's own reads), and `getScopeBindings` — which resolves the
  default scope to `[0]`, websites to their expanded store list, and stores to the
  specific id.

## [1.1.1] - 2026-06-19

### Fixed
- Tailwind v4's `@layer base` block contains nested `@supports` chains 3-4 levels
  deep (the `::placeholder` `color-mix` fallback uses a doubly-nested `@supports`).
  `CssLayerFlattener`'s drop regex capped at 2 levels of brace nesting, so the
  whole `@layer base` block silently survived flattening. Emogrifier then inlined
  the v4 preflight rules (`*, ::after { box-sizing: border-box; … }`,
  `img, video { display: block; max-width: 100%; height: auto; … }`) onto every
  email element, which leaked `display: block`/`max-width: 100%`/etc. into the
  inline styles. Rewrote the flattener with PCRE recursive subpatterns (`(?2)`)
  and possessive quantifiers so arbitrary nesting is handled in linear time.
- Same nesting cap occasionally allowed `@layer properties { @supports { *, ::before,
  ::after { --tw-invert: initial; … } } }` to survive too. The resolver would then
  pick up `--tw-invert: initial` as the global value and substitute it back into
  `.invert`'s `filter:` declaration, producing `filter: initial initial …` which
  Emogrifier dropped as invalid - so `<img class="invert">` got no inline filter
  at all. With proper recursive flattening, the scope-reset is dropped consistently
  and only the local `--tw-invert: invert(100%)` survives.
- Tailwind v4's class-name scanner is text-based and can miss `class="…"` on
  elements whose attribute list is interleaved with Magento `{{if …}}` / `{{var …}}`
  directives (e.g. the typical `<img {{if logo_width}}…{{/if}} class="invert"/>`
  pattern). The iframe now also bakes a hidden shadow `<div>` carrying every class
  name extracted from the source via a robust regex pass, so the scanner sees every
  utility regardless of how messy the surrounding markup is.

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
