<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Css\EmailStylesheetCollectorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\EditorContextFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PreviewPlaceholderInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateAreaResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplatePreviewFramerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateRendererInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\UtilityCssGeneratorInterface;
use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use Magento\Framework\App\State;
use Magento\Framework\Escaper;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class TemplateRenderer implements TemplateRendererInterface
{
    /**
     * @param TemplateAreaResolverInterface $areaResolver
     * @param EmailStylesheetCollectorInterface $stylesheets
     * @param UtilityCssGeneratorInterface $utilityCssGenerator
     * @param ThemeRepositoryInterface $themeRepository
     * @param State $appState
     * @param Emulation $appEmulation
     * @param TemplateFactory $templateFactory
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param EditorContextFlagInterface $editorContextFlag
     * @param TemplatePreviewFramerInterface $previewFramer
     * @param PreviewPlaceholderInterface $placeholder
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly TemplateAreaResolverInterface $areaResolver,
        private readonly EmailStylesheetCollectorInterface $stylesheets,
        private readonly UtilityCssGeneratorInterface $utilityCssGenerator,
        private readonly ThemeRepositoryInterface $themeRepository,
        private readonly State $appState,
        private readonly Emulation $appEmulation,
        private readonly TemplateFactory $templateFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly EditorContextFlagInterface $editorContextFlag,
        private readonly TemplatePreviewFramerInterface $previewFramer,
        private readonly PreviewPlaceholderInterface $placeholder,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritDoc
     */
    public function render(
        string $content,
        array $variables,
        int $storeId,
        ?string $customCss = null,
        ?string $tailwindCss = null,
        ?string $templateIdentifier = null,
        bool $isMockData = false,
        ?string $themeCssOverride = null
    ): string {
        $emulationStarted = false;

        // Mark this render as happening inside the editor preview so the EmailTemplatePlugin
        // applies overrides on included templates (header/footer) even when the module's
        // "enabled" config is off - the toggle gates live transactional emails, not the
        // editor's own preview. Without this, included headers render as the base default.
        $this->editorContextFlag->enable();

        try {
            $area = $templateIdentifier !== null
                ? $this->areaResolver->resolve($templateIdentifier)
                : $this->resolveAreaForContent($content);

            if ($storeId === 0) {
                $storeId = (int)$this->getDefaultFrontendStoreId();
            }

            // A header or footer is half of a document; framed, it renders as the email it is part of.
            if ($templateIdentifier !== null) {
                $content = $this->previewFramer->frame($templateIdentifier, $content, $storeId);
            }

            if ($isMockData) {
                $content = $this->replaceLayoutDirectives($content);
            }

            $this->appEmulation->startEnvironmentEmulation($storeId, $area, true);
            $emulationStarted = true;

            $template = $this->templateFactory->create();
            $template->setTemplateType(Template::TYPE_HTML);
            $template->setTemplateText($content);

            // The draft's CSS is this template's own sheet. The render collects it together with
            // the CSS of every header and footer the content includes and inlines the lot once,
            // into the finished email - the same pass a real send goes through. When the editor
            // sends the theme it is showing, that theme supplies the tokens; otherwise the store's
            // default theme does.
            $themeCss = $themeCssOverride !== null && trim($themeCssOverride) !== ''
                ? $this->utilityCssGenerator->generate($themeCssOverride)
                : $this->resolveThemeCss($storeId);
            $this->stylesheets->assign($template, (string)$themeCss, (string)$tailwindCss, (string)$customCss);

            $processedContent = $this->processInArea($area, $template, $variables);

            $this->appEmulation->stopEnvironmentEmulation();
            $emulationStarted = false;

            return $processedContent;
        } catch (\Exception $e) {
            $this->logger->error('Template rendering failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            // escapeHtmlAttr() without single-quote escaping is escapeHtml() for a string, typed as one.
            return '<div style="color:red;padding:20px;">Rendering Error: '
                . $this->escaper->escapeHtmlAttr($e->getMessage(), false) . '</div>';
        } finally {
            if ($emulationStarted) {
                $this->appEmulation->stopEnvironmentEmulation();
            }

            $this->editorContextFlag->disable();
        }
    }

    /**
     * @inheritDoc
     */
    public function renderPlain(
        string $text,
        array $variables,
        int $storeId,
        ?string $templateIdentifier = null
    ): string {
        $emulationStarted = false;

        try {
            $area = $templateIdentifier !== null
                ? $this->areaResolver->resolve($templateIdentifier)
                : 'frontend';

            if ($storeId === 0) {
                $storeId = (int)$this->getDefaultFrontendStoreId();
            }

            $this->appEmulation->startEnvironmentEmulation($storeId, $area, true);
            $emulationStarted = true;

            $template = $this->templateFactory->create();
            $template->setTemplateType(Template::TYPE_TEXT);
            $template->setTemplateText($text);

            $result = $this->processInArea($area, $template, $variables);

            $this->appEmulation->stopEnvironmentEmulation();
            $emulationStarted = false;

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Plain text rendering failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $text;
        } finally {
            if ($emulationStarted) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * Process a template's directives with the given area's design loaded
     *
     * @param string $area
     * @param Template $template
     * @param array<string,mixed> $variables
     * @return string
     * @throws \Exception When processing fails or yields no text
     */
    private function processInArea(string $area, Template $template, array $variables): string
    {
        $processed = $this->appState->emulateAreaCode(
            $area,
            static fn (): string => $template->getProcessedTemplate($variables)
        );

        if (!is_string($processed)) {
            throw new \UnexpectedValueException('Template processing returned no text.');
        }

        return $processed;
    }

    /**
     * Resolve theme CSS for the given store by loading the default theme
     *
     * @param int $storeId
     * @return string|null
     */
    private function resolveThemeCss(int $storeId): ?string
    {
        try {
            $theme = $this->themeRepository->getDefaultTheme($storeId);
            if ($theme !== null && $theme->getThemeCss() !== null) {
                return $this->utilityCssGenerator->generate($theme->getThemeCss());
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to resolve theme CSS: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Replace {{layout}} directives with placeholder HTML since they require real DB entities
     *
     * @param string $content
     * @return string
     */
    private function replaceLayoutDirectives(string $content): string
    {
        // A failed match run answers null; casting that to a string would blank the whole email.
        return preg_replace_callback(
            '/\{\{layout\s+([^}]*)\}\}/i',
            function (array $matches): string {
                $handle = '';

                if (preg_match('/handle\s*=\s*"([^"]+)"/', $matches[1], $handleMatch)) {
                    $handle = $handleMatch[1];
                }

                $label = $handle !== '' ? $handle : (string)__('layout block');

                return $this->placeholder->render((string)__('%1 requires real data to render', $label));
            },
            $content
        ) ?? $content;
    }

    /**
     * Resolve the application area based on template content heuristics
     *
     * @param string $content
     * @return string
     */
    private function resolveAreaForContent(string $content): string
    {
        if (str_contains($content, 'adminhtml') || str_contains($content, 'backend')) {
            return 'adminhtml';
        }

        return 'frontend';
    }

    /**
     * Get the first available frontend store ID
     *
     * @return int
     */
    private function getDefaultFrontendStoreId(): int
    {
        try {
            $stores = $this->storeManager->getStores(false);

            foreach ($stores as $store) {
                if ((bool)$store->getIsActive()) {
                    return (int)$store->getId();
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to resolve default frontend store: ' . $e->getMessage());
        }

        return 1;
    }
}
