<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Plugin;

use Hryvinskyi\EmailTemplateEditor\Api\ConfigInterface;
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
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class EmailTemplatePlugin
{
    /**
     * @var array<string, string>
     */
    private array $pendingCssMap = [];

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
        private readonly CssLayerFlattenerInterface $layerFlattener
    ) {
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

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();

            // The "enabled" config gates live transactional emails. Inside the admin editor
            // preview the override is always applied so the editor reflects it regardless.
            if (!$this->config->isEnabled($storeId) && !$this->editorContextFlag->isActive()) {
                return $result;
            }

            $override = $this->loadPublishedOverride($templateId, $storeId, $this->resolveThemeCode($result));

            if ($override === null || !$override->getIsActive()) {
                return $result;
            }

            $result->setTemplateText($override->getTemplateContent());

            if ($override->getTemplateSubject()) {
                $result->setTemplateSubject($override->getTemplateSubject());
            }

            $combinedCss = $this->buildCombinedCss($override, $storeId);
            if ($combinedCss !== '') {
                $this->pendingCssMap[spl_object_id($result)] = $combinedCss;
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'EmailTemplateEditor plugin error for template "' . $templateId . '": ' . $e->getMessage()
            );
        }

        return $result;
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
        $objectId = spl_object_id($subject);

        if (!isset($this->pendingCssMap[$objectId])) {
            return $result;
        }

        $css = $this->pendingCssMap[$objectId];
        unset($this->pendingCssMap[$objectId]);

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

            return $this->embedStyleBlock($result, $resolvedCss);
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
     * Identifier priority: the theme-specific id ("<templateId>/<themeCode>") first, then
     * the bare id. This lets an override created against the active theme apply even when the
     * template is pulled in by its base id, e.g. a header included via
     * {{template config_path="design/email/header_template"}} on a themed store view.
     *
     * Store priority: the specific store first, then the default scope (0 = all store views).
     * Status priority: an active scheduled override (active_from/active_to covers now) first,
     * then an immediate override (no date range).
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
        $identifiers = [];
        if ($themeCode !== null && $themeCode !== '' && !str_contains($templateId, '/')) {
            $identifiers[] = $templateId . '/' . $themeCode;
        }
        $identifiers[] = $templateId;

        $storeIds = [$storeId];
        if ($storeId !== 0) {
            $storeIds[] = 0;
        }

        foreach ($identifiers as $identifier) {
            foreach ($storeIds as $sid) {
                $override = $this->overrideRepository->getActiveScheduledPublished($identifier, $sid);
                if ($override !== null) {
                    return $override;
                }
            }
        }

        foreach ($identifiers as $identifier) {
            foreach ($storeIds as $sid) {
                $override = $this->overrideRepository->getImmediatePublished($identifier, $sid);
                if ($override !== null) {
                    return $override;
                }
            }
        }

        return null;
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
}
