<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Plugin;

use Hryvinskyi\EmailTemplateEditor\Api\ConfigInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssImportantPromoterInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssInlinerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssLayerFlattenerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssVariableResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Api\EditorContextFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\UtilityCssGeneratorInterface;
use Magento\Email\Model\AbstractTemplate;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class EmailTemplatePlugin implements ResetAfterRequestInterface
{
    /**
     * Override CSS waiting to be embedded, keyed by the template object it belongs to
     *
     * Keyed by the object rather than by spl_object_id(): PHP recycles object handles once an
     * object is collected, so an entry left behind by a template that was loaded but never
     * processed could be picked up by an unrelated template and put one override's CSS onto
     * another's markup. A WeakMap key cannot collide, and the entry disappears with the
     * template instead of accumulating.
     *
     * @var \WeakMap<AbstractTemplate, string>
     */
    private \WeakMap $pendingCssMap;

    /**
     * Overrides already resolved during this request, keyed by "templateId|storeId|themeCode"
     *
     * A null value is a cached "there is no override", so a template without one does not repeat
     * the lookup on every include. The theme code belongs in the key because it decides which
     * identifiers are searched, so the same template rendered under two themes must not share an
     * entry.
     *
     * @var array<string, TemplateOverrideInterface|null>
     */
    private array $resolvedOverrides = [];

    /**
     * @param ConfigInterface $config
     * @param TemplateOverrideRepositoryInterface $overrideRepository
     * @param ThemeRepositoryInterface $themeRepository
     * @param UtilityCssGeneratorInterface $cssGenerator
     * @param CssInlinerInterface $cssInliner
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param PluginBypassFlagInterface $pluginBypassFlag
     * @param EditorContextFlagInterface $editorContextFlag
     * @param CssVariableResolverInterface $cssVariableResolver
     * @param CssLayerFlattenerInterface $layerFlattener
     * @param CssImportantPromoterInterface $importantPromoter
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly TemplateOverrideRepositoryInterface $overrideRepository,
        private readonly ThemeRepositoryInterface $themeRepository,
        private readonly UtilityCssGeneratorInterface $cssGenerator,
        private readonly CssInlinerInterface $cssInliner,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly PluginBypassFlagInterface $pluginBypassFlag,
        private readonly EditorContextFlagInterface $editorContextFlag,
        private readonly CssVariableResolverInterface $cssVariableResolver,
        private readonly CssLayerFlattenerInterface $layerFlattener,
        private readonly CssImportantPromoterInterface $importantPromoter,
        private readonly TimezoneInterface $timezone
    ) {
        $this->pendingCssMap = new \WeakMap();
    }

    /**
     * After loading default template, apply the best matching published override
     *
     * @param AbstractTemplate $subject
     * @param AbstractTemplate $result
     * @param string $templateId
     * @return AbstractTemplate
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterLoadDefault(
        AbstractTemplate $subject,
        AbstractTemplate $result,
        string $templateId
    ): AbstractTemplate {
        if ($this->pluginBypassFlag->isBypassed()) {
            return $result;
        }

        $this->applyOverlay($result, $templateId);

        return $result;
    }

    /**
     * After loading by primary key (legacy email_template row), overlay a managed override
     *
     * Mirrors afterLoadDefault for the path AbstractTemplate::loadByConfigPath takes
     * when system configuration stores a numeric template_id rather than a file
     * identifier — i.e. a legacy Magento "Transactional Emails" override. Without this
     * hook the legacy row's stored template_text/subject is what gets sent, even when
     * an admin has subsequently edited the template through this module's editor.
     *
     * @param AbstractTemplate $subject
     * @param AbstractTemplate $result
     * @return AbstractTemplate
     */
    public function afterLoad(
        AbstractTemplate $subject,
        AbstractTemplate $result
    ): AbstractTemplate {
        if ($this->pluginBypassFlag->isBypassed()) {
            return $result;
        }

        // Mirror AdminEditControllerPlugin's fallback: a legacy row without an
        // orig_template_code (a wholly custom template_code) gets seeded into a
        // managed override under its template_code, so the overlay must look it
        // up by that same key for the runtime substitution to fire.
        $identifier = (string)($subject->getOrigTemplateCode() ?? '');
        if ($identifier === '') {
            $identifier = (string)($subject->getTemplateCode() ?? '');
        }

        if ($identifier === '') {
            return $result;
        }

        $this->applyOverlay($result, $identifier);

        return $result;
    }

    /**
     * Apply the best-matching managed override's content/subject/CSS to a loaded template
     *
     * Shared between afterLoadDefault (file-load path) and afterLoad (legacy-row
     * path). The "enabled" config gates live transactional emails; inside the admin
     * editor preview the override is always applied so the editor reflects it
     * regardless of the module switch.
     *
     * @param AbstractTemplate $result
     * @param string $templateId
     * @return void
     */
    private function applyOverlay(AbstractTemplate $result, string $templateId): void
    {
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();

            if (!$this->config->isEnabled($storeId) && !$this->editorContextFlag->isActive()) {
                return;
            }

            // No second look at whether the override is switched on: the ranking passes over
            // switched-off rows outright, so anything it hands back is one that applies. Testing
            // it again here is what used to let a switched-off row win its rung and then stop the
            // overlay, taking every lower-ranked override down with it.
            $override = $this->loadPublishedOverride($templateId, $storeId, $this->resolveThemeCode($result));

            if ($override === null) {
                return;
            }

            $result->setTemplateText($override->getTemplateContent());

            if ($override->getTemplateSubject()) {
                $result->setTemplateSubject($override->getTemplateSubject());
            }

            $combinedCss = $this->buildCombinedCss($override, $storeId);
            if ($combinedCss !== '') {
                $this->pendingCssMap[$result] = $combinedCss;
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'EmailTemplateEditor plugin error for template "' . $templateId . '": ' . $e->getMessage()
            );
        }
    }

    /**
     * After processing the template, embed the override CSS as a style block
     *
     * The CSS is injected as a <style> element rather than inlined here, because the
     * processed result may be an included fragment (e.g. a header that opens the document
     * for the footer to close). Running a separate inliner on such a fragment would force a
     * complete document and orphan everything that follows it. Embedding a <style> block lets
     * the single top-level inliner - the parent email's {{inlinecss}} on a real send, or the
     * editor's renderer in preview - apply it to the fully assembled document instead.
     *
     * @param AbstractTemplate $subject
     * @param string $result
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetProcessedTemplate(AbstractTemplate $subject, string $result): string
    {
        if (!isset($this->pendingCssMap[$subject])) {
            return $result;
        }

        $css = $this->pendingCssMap[$subject];
        unset($this->pendingCssMap[$subject]);

        try {
            // Flatten Tailwind `@layer` wrappers BEFORE resolving and embedding. Otherwise the
            // override's stored tailwind_css (compiled by v4 with `.invert` etc. wrapped in
            // `@layer utilities {...}`) would land in the processed sub-template as a <style>
            // block that Emogrifier silently ignores, leaving the override classes unstyled
            // in any parent email that includes this template via {{template config_path=...}}.
            $resolvedCss = trim(
                $this->cssVariableResolver->resolve($this->layerFlattener->flatten($css))
            );
            if ($resolvedCss === '') {
                return $result;
            }

            // Whoever inlines the assembled document does so *after* Magento's own
            // {{inlinecss}} pass has already written css/email-inline.css into `style="…"`
            // attributes, and Emogrifier gives those pre-existing attributes the last word
            // unless the competing declaration is !important. Without this an override class
            // on an included header/footer (e.g. `text-black` on a link) silently loses to
            // the stock `a { color: … }` once the fragment is pulled into a parent template.
            return $this->embedStyleBlock($result, $this->importantPromoter->promote($resolvedCss));
        } catch (\Exception $e) {
            $this->logger->error('Failed to embed override CSS for email: ' . $e->getMessage());

            return $result;
        }
    }

    /**
     * Insert a style block into processed template HTML
     *
     * Placed before </head> when present so it travels with the document head; otherwise
     * prepended so it is still picked up by the downstream inliner.
     *
     * @param string $html
     * @param string $css
     * @return string
     */
    private function embedStyleBlock(string $html, string $css): string
    {
        $styleBlock = '<style type="text/css">' . "\n" . $css . "\n" . '</style>';

        if (preg_match('#</head>#i', $html)) {
            return (string)preg_replace('#</head>#i', $styleBlock . '$0', $html, 1);
        }

        return $styleBlock . $html;
    }

    /**
     * Load the best matching published override with theme and store fallback
     *
     * Every candidate for every combination of identifier and store scope is fetched in one
     * lookup and ranked here, because ranking the rows costs nothing next to asking the
     * database once per rung.
     *
     * Identifier priority: the theme-specific id ("<templateId>/<themeCode>") first, then
     * the bare id. This lets an override created against the active theme apply even when the
     * template is pulled in by its base id, e.g. a header included via
     * {{template config_path="design/email/header_template"}} on a themed store view.
     *
     * Store priority: the specific store first, then the default scope (0 = all store views).
     * Window priority: an active override whose availability window is open now first — a window
     * left open at one end counts, from or until the bound it does carry — then an override that
     * carries no window at all.
     *
     * @param string $templateId
     * @param int $storeId
     * @param string|null $themeCode
     * @return TemplateOverrideInterface|null
     */
    private function loadPublishedOverride(
        string $templateId,
        int $storeId,
        ?string $themeCode = null
    ): ?TemplateOverrideInterface {
        $cacheKey = $templateId . '|' . $storeId . '|' . ($themeCode ?? '');

        // array_key_exists, not isset: a resolved "no override" is stored as null and must
        // still count as answered, or every template without one repeats the lookup.
        if (array_key_exists($cacheKey, $this->resolvedOverrides)) {
            return $this->resolvedOverrides[$cacheKey];
        }

        $identifiers = [];
        if ($themeCode !== null && $themeCode !== '' && !str_contains($templateId, '/')) {
            $identifiers[] = $templateId . '/' . $themeCode;
        }
        $identifiers[] = $templateId;

        $storeIds = [$storeId];
        if ($storeId !== 0) {
            $storeIds[] = 0;
        }

        $candidates = $this->overrideRepository->getOverridesForIdentifiers(
            $identifiers,
            $storeIds,
            [TemplateOverrideInterface::STATUS_PUBLISHED]
        );

        $override = $this->pickHighestPriority($candidates, $identifiers, $storeIds);
        $this->resolvedOverrides[$cacheKey] = $override;

        return $override;
    }

    /**
     * Rank the fetched candidates and return the winner
     *
     * The availability window is the outermost dimension, so a candidate whose window is open
     * right now outranks every undated one regardless of identifier or store — a bare,
     * default-scope windowed override beats a themed, store-specific undated one. Identifier comes
     * next, store last.
     *
     * @param array<string, TemplateOverrideInterface[]> $candidates Rows grouped by identifier
     * @param string[] $identifiers Identifiers in descending priority
     * @param int[] $storeIds Store scopes in descending priority
     * @return TemplateOverrideInterface|null
     */
    private function pickHighestPriority(
        array $candidates,
        array $identifiers,
        array $storeIds
    ): ?TemplateOverrideInterface {
        $now = $this->timezone->date()->format('Y-m-d H:i:s');

        $windows = [
            fn (TemplateOverrideInterface $o): bool => $this->isWithinOpenWindow($o, $now),
            fn (TemplateOverrideInterface $o): bool => $this->carriesNoWindow($o),
        ];

        foreach ($windows as $matchesWindow) {
            foreach ($identifiers as $identifier) {
                foreach ($storeIds as $scopeId) {
                    $winner = $this->lowestIdMatching($candidates[$identifier] ?? [], $scopeId, $matchesWindow);

                    if ($winner !== null) {
                        return $winner;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Pick the lowest-id row of one scope that satisfies a window test
     *
     * The single place where a switched-off override is passed over, for every rung of the ladder.
     * Switching an override off means it is not there, so it must not win a rung it cannot then
     * fill: a row that wins and is then discarded further down suppresses the override standing
     * behind it, and an admin who switches off a store-view override gets stock templates instead
     * of the All Store Views one they were expecting to fall back to. Testing it here rather than
     * in each window predicate is what keeps the two rungs from drifting apart on the point.
     *
     * Ascending entity id is the tie-break because that is the order the single-row queries this
     * replaced happened to receive rows in, and one of several equally ranked overrides has
     * always been chosen that way.
     *
     * @param TemplateOverrideInterface[] $candidates
     * @param int $storeId
     * @param callable(TemplateOverrideInterface): bool $matchesWindow
     * @return TemplateOverrideInterface|null
     */
    private function lowestIdMatching(
        array $candidates,
        int $storeId,
        callable $matchesWindow
    ): ?TemplateOverrideInterface {
        $winner = null;

        foreach ($candidates as $candidate) {
            if (!$candidate->getIsActive()
                || $candidate->getStoreId() !== $storeId
                || !$matchesWindow($candidate)
            ) {
                continue;
            }

            if ($winner === null || (int)$candidate->getEntityId() < (int)$winner->getEntityId()) {
                $winner = $candidate;
            }
        }

        return $winner;
    }

    /**
     * Whether an override's availability window is open at the given moment
     *
     * Each bound is optional and a bound that is not set is an open end rather than a missing
     * window: a row carrying only active_from applies from that moment onward, one carrying only
     * active_to applies until it, and one carrying both applies between them, both bounds
     * included. The publish dialog asks for at least one date and not for both, so half-open rows
     * are ordinary rows rather than malformed ones.
     *
     * The explicit null checks are what make an open end safe to read. A bound compared while
     * unset is cast to an empty string, which sorts before every timestamp, so an unset start
     * would read as "began long ago" and an unset end as "already over" — each bound would then
     * decide the opposite of what leaving it out means.
     *
     * A row carrying neither bound is refused here and answered by carriesNoWindow() alone, so the
     * two rungs stay disjoint and no row is claimed by both.
     *
     * Whether the override is switched on is not asked here. That belongs to the ranking, which
     * applies it to every rung at once.
     *
     * @param TemplateOverrideInterface $override
     * @param string $now Current time in the store's timezone, formatted Y-m-d H:i:s
     * @return bool
     */
    private function isWithinOpenWindow(TemplateOverrideInterface $override, string $now): bool
    {
        $activeFrom = $override->getActiveFrom();
        $activeTo = $override->getActiveTo();

        if ($activeFrom === null && $activeTo === null) {
            return false;
        }

        // Zero-padded Y-m-d H:i:s sorts lexicographically in chronological order.
        return ($activeFrom === null || $activeFrom <= $now)
            && ($activeTo === null || $activeTo >= $now);
    }

    /**
     * Whether an override applies with no window at all, i.e. from publication until replaced
     *
     * This is the exact complement of isWithinOpenWindow()'s bound test — that rung refuses a row
     * carrying neither bound, this one accepts only such a row — so every candidate belongs to one
     * rung or the other and never to both.
     *
     * Whether the override is switched on is not asked here either, for the same reason.
     *
     * @param TemplateOverrideInterface $override
     * @return bool
     */
    private function carriesNoWindow(TemplateOverrideInterface $override): bool
    {
        return $override->getActiveFrom() === null && $override->getActiveTo() === null;
    }

    /**
     * Resolve the active design theme code for the template being rendered
     *
     * @param AbstractTemplate $template
     * @return string|null
     */
    private function resolveThemeCode(AbstractTemplate $template): ?string
    {
        try {
            $theme = $template->getDesignParams()['theme'] ?? null;

            return is_string($theme) && $theme !== '' ? $theme : null;
        } catch (\Exception $e) {
            // Falling back to the bare identifier is the right behaviour, but it drops every
            // theme-specific rung of the lookup, so an override created against the admin's
            // theme just stops applying. Without this line nothing anywhere records why.
            $this->logger->warning(
                'EmailTemplateEditor could not resolve the design theme code; theme-specific '
                . 'overrides will not be considered: ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Build combined CSS from theme, tailwind, and custom CSS
     *
     * @param TemplateOverrideInterface $override
     * @param int $storeId
     * @return string
     */
    private function buildCombinedCss(TemplateOverrideInterface $override, int $storeId): string
    {
        $parts = [];

        $themeId = $override->getThemeId();
        try {
            if ($themeId) {
                $theme = $this->themeRepository->getById($themeId);
                $themeCss = $this->cssGenerator->generate($theme->getThemeCss());
            } else {
                $defaultTheme = $this->themeRepository->getDefaultTheme($storeId);
                $themeCss = $defaultTheme
                    ? $this->cssGenerator->generate($defaultTheme->getThemeCss())
                    : '';
            }
            if ($themeCss !== '') {
                $parts[] = $themeCss;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to load theme CSS: ' . $e->getMessage());
        }

        $tailwindCss = $override->getTailwindCss();
        if ($tailwindCss !== null && $tailwindCss !== '') {
            $parts[] = $tailwindCss;
        }

        $customCss = $override->getCustomCss();
        if ($customCss !== null && $customCss !== '') {
            $parts[] = $customCss;
        }

        return implode("\n", $parts);
    }

    /**
     * @inheritDoc
     *
     * Both maps are per-request state on a shared singleton. The order-email cron sends a whole
     * batch of messages in one PHP process, each loading its template through this plugin, so
     * without this the resolved overrides of the first message would be reused for every
     * following one and any CSS left pending by a template that was never processed would
     * outlive the send it belonged to.
     */
    public function _resetState(): void
    {
        $this->pendingCssMap = new \WeakMap();
        $this->resolvedOverrides = [];
    }
}
