# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-10-13

### Fixed
- **Override CSS never reached a sent email inlined.** Each override embedded its CSS as a `<style>`
  block in its own output - the header's in the head, the footer's in the middle of the body - after
  Magento's `{{inlinecss}}` pass, which never parses style blocks. Clients that drop style blocks, or
  read them only from the head, showed the override unstyled; the preview inlined everything and hid
  it. Every template of an email now hands its stylesheet to the outermost render, which inlines the
  whole sheet once into the finished document (`EmailStylesheetCollectorInterface`). The theme's CSS
  is in it once instead of once per override, so a message with a header and a footer override no
  longer passes Gmail's 102 KB clipping limit (a Contact Form email on a test store: 55 KB → 20 KB).
  The preview, the version preview and the test email go through the same pass.
- **Embedding CSS could corrupt it.** The `<style>` block was spliced in with `preg_replace`, which
  read `\201C`, `\32xl` or `$1` in the CSS as back-references. The splice is gone with the embed.
- **A plain-text email received a style block** from an override's CSS. It is no longer styled.
- **A template processed twice lost its CSS the second time**, and a long-running sender (cron, a
  queue consumer) kept the overrides it had resolved for its first email. A template's stylesheet
  now lasts as long as the template, and resolved overrides are forgotten after every email.
- **The rule after a Tailwind layer-order statement was never inlined.** Tailwind opens its output
  with a `/*! tailwindcss … */` banner, which hid the `@layer theme, base, components, utilities;`
  after it from the flattener; the inliner then read that statement and the next rule as one unknown
  at-rule (`.mb-11` on a stored header). Tailwind source stored by earlier versions
  (`@import "tailwindcss"`, `@theme { … }`) is now dropped before inlining as well.
- **The preview put the draft's theme below the CSS of the header and footer it includes**, and
  inlined the theme's own `<style>` blocks, which a real send leaves alone. Within each tier the
  draft now comes last, and the document's style blocks are left as the core leaves them. The
  preview no longer fills `template_styles` with the theme's CSS either.
- **A version was previewed with the store's default theme** instead of the theme it was saved with.
- **A draft save reset fields it did not carry.** An absent theme, Tailwind CSS, schedule or sample
  provider was stored as empty. Only a field sent empty clears now; an absent one keeps its value.
- **A save aimed at the published override changed what customers receive without a version.** Only
  the publish flow (`intent: publish`) writes a published override; any other save goes to the
  template's working draft, created from the published override when there is none, and the answer
  says `forked: true` (`TemplateDraftSaverInterface`).
- **The preview opened in a new tab ran on the admin's origin.** It is served with
  `Content-Security-Policy: sandbox`, so nothing in a template can act with the viewer's session.
- **No module could add to the two shipped knowledge entries whose expression holds quotes and
  brackets** (`this.getUrl($store,'customer/account/',…)`). The framework's merge wrote the
  expression into an XPath predicate unescaped; the contribution became a second entry and the
  schema refused the merged file. The knowledge reader now merges with a `Dom` that writes proper
  XPath literals.
- **The test email showed `{{layout}}` blocks for mock data** instead of the placeholders the
  preview shows.
- **Utility classes on a footer's own cell were never compiled.** A footer opens by closing the cell
  the header left open (`</td></tr><tr><td class="footer ...">`). The compiler read class names from
  the markup *after* parsing it, and the parser drops a row and cell that stray, class attribute and
  all, so a class used only there (`border-t-3`) never reached the stored `tailwind_css` and never
  reached an email. Classes that also appeared in the header still rendered, borrowed from the
  header's CSS, which hid the cause. Class names are now read from the source text. An override
  saved before this fix keeps its incomplete CSS until it is saved again.
- **A template loaded without stored Tailwind CSS kept the previous template's.** The editor only
  replaced its stylesheet when the loaded override carried one, so the next save stored one
  template's styles under another's name. Every load now replaces it, an empty one included.
- **Reload Template saved the stored stylesheet again instead of recompiling.** Loading put the
  stored CSS back while the editor still remembered a compile of the same content, so no compile ran
  and the stored CSS - including CSS stored by a compiler that missed classes - was saved again.
  Every load now forgets the remembered compile.
- **A save that stopped waiting for the Tailwind compile stored the previous revision's CSS and
  reported the draft clean.** After 2 s the save went ahead with whatever CSS was current. A slow
  compile is now waited for (the status bar says so); one that never answers within 15 s stores,
  publishes or sends nothing and leaves the draft dirty, so the auto-save tries again.
- **The stored Tailwind CSS carried the raw theme and the template's own `<style>` blocks.** The
  compiler read back every `<style>` in its frame that looked like Tailwind, its own theme input
  and the template's styles included, and could settle on those before the bundle had written
  anything. It now reads only the stylesheet the bundle appends.
- **Format Code changed the text of `{{trans}}` directives.** Breaking lines between adjacent tags
  also broke them inside a directive (`{{trans "Hello,<br><br>%name"}}`), changing the key the
  translation is looked up by. Directive interiors are now left byte-identical.
- **The custom sample-data skeleton missed directives written over several lines.**
- **A late version preview or comparison replaced the one being looked at.** Only the answer to the
  latest click now changes the screen.
- **The Restore Version dialog showed `<strong>` literally.** The detail is plain text, as the
  dialog renders it.
- **A preview error message from the server was written into the preview frame as markup.** It is
  escaped now.
- **A draft save sent an empty theme or Tailwind CSS it did not know.** A theme still loading was
  sent as `''`, and a stylesheet never compiled for the content was sent as the last one compiled
  for something else. Both are now left out, so the stored value is kept.
- **The test email used the store's default theme** instead of the theme on screen; it now sends
  `theme_css` like the preview does.

### Changed
- **A header or footer is previewed as the email it belongs to.** Rendered alone, a header is an
  unclosed document and a footer starts with closing tags. The preview, the version preview and the
  test email now place the edited header before a content placeholder and the configured footer, or
  the configured header and a placeholder before the edited footer. The frames are a `di.xml` pool
  (`TemplatePreviewFramerInterface`) keyed by the configuration path a store includes the template
  by, so the template `design/email/header_template` points at - the stock one, a legacy
  Transactional Emails row or a template of another name - is framed as the header
  (`ConfiguredTemplateResolverInterface`).
- `EmailTemplatePlugin` styles an email in `aroundGetProcessedTemplate`; `afterGetProcessedTemplate`
  and the plugin's CSS flattener, resolver and promoter dependencies are gone. `CssInliner` no
  longer parses the document's own style blocks.
- The `{{layout}}` placeholder of a mock-data preview is translatable and shares its markup with the
  new content placeholder.
- The Tailwind browser bundle is pinned to `@tailwindcss/browser@4.3.3` (the release `@4` resolved
  to). A floating range let an upstream release change the CSS stored with every override.
- The save made on the way to publishing carries `intent: publish`; a draft save the server forks
  into the working draft (`forked: true`) is followed like a fork made in the browser.

### Tests
- The plugin is tested rendering an email that includes a footer, with the real stylesheet collector
  and, end to end, the real inliner: the footer's `border-t-3` ends up in its cell's `style`. The
  collector, the draft saver (on the real override model), the configured-template resolver, the
  knowledge merge and the preview, version preview, test email and save controllers have tests.
- `ModuleContainer` builds Magento's own object manager from the module's `etc/di.xml` — read and
  schema-validated by the framework's DI reader — so wiring is tested as an installation resolves it.
  The preview frames are the first wiring tested that way.
- The template double in `EmailTemplatePluginTest` no longer assumes which `template_text` /
  `template_subject` writers a Magento release declares; on 2.4.8 the whole class errored.
- `I18nCoverageTest` is removed: it asserted the text of the module's own source files. Dictionary
  drift is now a release check (`bin/magento i18n:collect-phrases` against `i18n/en_US.csv`), with
  the gaps that collector has.
- The tests that read the module's own files are replaced by tests that run the code: the modifier
  vocabulary comes from `ModuleContainer`, the editor's ACL resource from Magento's ACL reader and
  loader, and the knowledge file is read through the module's reader, schema, converter and provider
  (fixtures prove every PHP-published kind is accepted and an unknown one refused). The layout,
  Knockout-template and popover-width lints in `Test/Js` are removed; nothing in this repository can
  render the admin page they describe. Data providers are static, which clears the PHPUnit
  deprecations they raised.
- The editor's persistence rules run against the real `email-editor.js` component with a compiler
  the test answers by hand: restored and recompiled CSS, the give-up path, the fields a save leaves
  out, the publish intent, the server-side fork, the test email's theme and the escaped preview
  error. New pure modules (`compile-wait`, `markup-formatter`, `custom-data-skeleton`,
  `draft-payload`, `html-text`) and the version history panel's request ordering are covered too.

## [1.3.0] - 2026-07-28

Five defects that each gave a recipient something other than what the administrator saw, and none of
which announced itself. Plus the module's first coverage for the browser code.

### Fixed
- **A published override carrying only one date reached nobody.** The send path required *both*
  `active_from` and `active_to` to be set, while the row that carries neither is handled by a
  separate branch — so a row with exactly one date fell between them and the stock template was sent
  instead. The publish dialog asks for "at least one date", so this was ordinary, not exotic. An
  unset bound is now an open end on that side, both bounds inclusive, and the rule is pinned by the
  same twelve window shapes on both sides of it — send-time resolution and the repository lookups —
  because the rule living in two implementations is what let it survive.
- **Switching an override off did not fall back to the next one.** A switched-off override still won
  its rung and then declined to fill it, so turning off a store-view override sent the stock template
  rather than the All Store Views one. Switching off now means the override is not there. One gate
  decides it, applied before any window is considered; the publisher separately keeps the undated
  slot to a single row, asking about occupancy through a lookup of its own rather than through the
  one that answers about liveness.
- **A stored cross-site-scripting hole in the confirmation dialog.** The detail line was rendered as
  markup while three call sites concatenated a draft name and a version comment into it — both free
  text. Publishing with a script tag in the comment and then opening Delete on that row ran it. The
  value is now text and the emphasis is the stylesheet's; a test carries the two remaining markup
  bindings with the reason each is safe, so a third has to be argued for.
- **Custom properties resolved from the whole document rather than from their own rule.** One
  `.border-dashed` made every `border-*` utility dashed, a `prefers-color-scheme: dark` override
  leaked into every message, a `;` inside a `data:` URI truncated the declaration, and a brace inside
  a string stopped anything being inlined at all. The resolver is now a scope-aware tokenizer sharing
  its primitives with the `!important` promoter, and one invalid UTF-8 byte no longer turns the whole
  stylesheet into an empty string.
- **The editor was unreachable for roles that could use it, and hijacked the screen from roles that
  could not.** The redirect out of Magento's own template screen now happens only when the role holds
  the destination's own permission, the row exists, its code names a template the editor has, and the
  module is switched on somewhere — it is per store view, so asking only the default scope hid the
  editor from a merchant who enabled it for one store.
- **A theme token holding a `data:` URI shipped a broken value.** Both the token reader and the
  injection guard split on every semicolon; they now know that one inside a string or parentheses is
  part of the value, while one that would escape the declaration is still removed.
- **The `config` cache is invalidated even when a field's own after-save step fails**, and such a
  failure is reported as a completed write with a warning rather than as a refusal of a change that
  already happened.

### Added
- **The module's first coverage for its browser code** — 201 tests over a DOM stub written for the
  purpose, with no npm dependencies and no `package.json`. It covers request generations, the cache
  and its store-view invalidation, the modifier-toggle decision, the decorator against a fake editor,
  and the structural checks that catch a binding on a bare property name or a component registered in
  two of the three places it needs to be.

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
