<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVariableDeclarationsInterface;
use Magento\Email\Model\TemplateFactory;
use Psr\Log\LoggerInterface;

/**
 * Loads a template's own variable declarations from the template file behind it.
 *
 * Two things about the load are not obvious and both matter.
 *
 * The override overlay is switched off around it. Only the template's own declarations are wanted,
 * the overlay costs several collection loads to produce a result that is thrown away with the
 * template, and the flag that switches it off has to come back down in a finally - left raised it
 * would silently suppress overrides for the rest of the request, which is far worse than the cost it
 * saves.
 *
 * The answer is remembered per template for the life of the request. A description request may ask
 * about hundreds of directives at once and every one of them would otherwise repeat the same file
 * load. A load that failed is remembered too: repeating it would not make it succeed and would fill
 * the log with the same line hundreds of times.
 */
class TemplateVariableDeclarations implements TemplateVariableDeclarationsInterface
{
    /**
     * Declarations already read in this request, keyed by template identifier
     *
     * @var array<string, array<string, string>>
     */
    private array $declarations = [];

    /**
     * @param TemplateFactory $templateFactory Builds the template the declarations are read from
     * @param PluginBypassFlagInterface $pluginBypassFlag Switches the override overlay off for the load
     * @param LoggerInterface $logger Records a template that could not be read
     */
    public function __construct(
        private readonly TemplateFactory $templateFactory,
        private readonly PluginBypassFlagInterface $pluginBypassFlag,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getDeclarations(string $templateId): array
    {
        if (!isset($this->declarations[$templateId])) {
            $this->declarations[$templateId] = $this->read($templateId);
        }

        return $this->declarations[$templateId];
    }

    /**
     * Read the declarations of one template
     *
     * @param string $templateId Identifier of the template to read
     * @return array<string, string> Directive without its braces, mapped to the declared label
     */
    private function read(string $templateId): array
    {
        try {
            $template = $this->templateFactory->create();
            $template->setForcedArea($templateId);

            $this->pluginBypassFlag->enable();

            try {
                $template->loadDefault($templateId);
            } finally {
                $this->pluginBypassFlag->disable();
            }

            return $this->decode($template->getData('orig_template_variables'));
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to load template variables for "' . $templateId . '": ' . $e->getMessage()
            );

            return [];
        }
    }

    /**
     * Turn the raw annotation into directive interiors mapped to labels
     *
     * Anything that is not a list of declarations - an absent annotation, one that is not valid
     * JSON, one holding something other than a map - yields no declarations at all. A template is
     * entitled to declare nothing, and a malformed annotation is indistinguishable from that here.
     *
     * @param mixed $rawDeclarations Value of the template's declaration annotation
     * @return array<string, string> Directive without its braces, mapped to the declared label
     */
    private function decode(mixed $rawDeclarations): array
    {
        if (!is_string($rawDeclarations) || $rawDeclarations === '') {
            return [];
        }

        $decoded = json_decode($rawDeclarations, true);

        if (!is_array($decoded)) {
            return [];
        }

        $declarations = [];

        foreach ($decoded as $directive => $label) {
            $interior = $this->stripBraces((string)$directive);

            if ($interior === '') {
                continue;
            }

            $declarations[$interior] = is_scalar($label) ? (string)$label : '';
        }

        return $declarations;
    }

    /**
     * Remove the directive braces from a declaration key, if it carries them
     *
     * Templates write the key both ways round, and the two spell the same directive. Unwrapping here
     * is what lets every caller stop caring which way a particular template happened to write it.
     *
     * @param string $directive Declaration key as written in the template
     * @return string Directive interior, trimmed
     */
    private function stripBraces(string $directive): string
    {
        $value = trim($directive);

        if (str_starts_with($value, '{{') && str_ends_with($value, '}}') && strlen($value) >= 4) {
            $value = substr($value, 2, -2);
        }

        return trim($value);
    }
}
