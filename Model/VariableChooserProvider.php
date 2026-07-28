<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model;

use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\CustomVariableIndexInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateVariableDeclarationsInterface;
use Hryvinskyi\EmailTemplateEditor\Api\VariableChooserProviderInterface;
use InvalidArgumentException;
use Magento\Email\Model\Template\Config as EmailConfig;
use Magento\Variable\Model\Source\Variables as ConfigVariables;
use Psr\Log\LoggerInterface;

/**
 * What the variable chooser offers, in the three groups it offers it in.
 *
 * Every row carries three things: the directive to insert, a label to read, and the canonical
 * reference that directive points at. The reference is what makes the chooser and the content one
 * surface rather than two - a variable picked here and the same variable already written in the
 * template resolve to a single knowledge entry, so the explanation an administrator reads is the
 * same explanation either way round.
 *
 * The sources merged here disagree about quoting. This module writes a custom variable directive
 * unquoted and Magento's configuration source writes a configuration one quoted, and both spellings
 * have to arrive at the same reference as the directive typed by hand into the content. Nothing here
 * decides that: the parameter is handed to the reference parser exactly as its source wrote it, and
 * the parser is the one place that knows quoting is not part of what a directive points at.
 */
class VariableChooserProvider implements VariableChooserProviderInterface
{
    /**
     * Code identifying the group of configuration variables Magento publishes
     *
     * The codes are not shown anywhere. The label beside each is what is read, and it is translated
     * - which is precisely why it cannot also be the key: a panel that remembered which groups were
     * collapsed by their English names would forget every one of them in any other language.
     */
    private const GROUP_SYSTEM = 'system';

    /**
     * Code identifying the group of merchant-defined custom variables
     */
    private const GROUP_CUSTOM = 'custom';

    /**
     * Code identifying the group of variables the open template declares about itself
     */
    private const GROUP_TEMPLATE = 'template';

    /**
     * Directive kind of a configuration variable
     */
    private const KIND_CONFIG = 'config';

    /**
     * Directive kind of a merchant-defined custom variable
     */
    private const KIND_CUSTOM_VARIABLE = 'customVar';

    /**
     * Directive kind of a plain template variable
     */
    private const KIND_VARIABLE = 'var';

    /**
     * Recovers the path parameter from the directive Magento's configuration source writes
     *
     * That source builds every one of its options as `{{config path="<path>"}}` and offers the path
     * nowhere else in the option, so this is where the path comes back out. The captured value keeps
     * its quotes deliberately: unquoting is the reference parser's decision, and making it here as
     * well would be the same rule in two places, free to drift apart.
     */
    private const CONFIG_DIRECTIVE_PATTERN = '/^\{\{config\s+path=(?P<path>.*)}}$/';

    /**
     * Where a directive's expression ends and its modifier chain begins
     *
     * The email filter hands a variable directive's whole interior to `explode('|', $value, 2)`, so
     * everything from the first pipe onwards is formatting rather than part of what the directive
     * points at. A template declares its variables with that formatting included -
     * `var formattedShippingAddress|raw` is how the sales templates declare an address - and the
     * chain has to stay in the text this offers to insert while staying out of the reference.
     */
    private const MODIFIER_SEPARATOR = '|';

    /**
     * @param ConfigVariables $configVariables
     * @param CustomVariableIndexInterface $customVariableIndex
     * @param EmailConfig $emailConfig
     * @param TemplateVariableDeclarationsInterface $templateVariableDeclarations
     * @param DirectiveReferenceParserInterface $referenceParser
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigVariables $configVariables,
        private readonly CustomVariableIndexInterface $customVariableIndex,
        private readonly EmailConfig $emailConfig,
        private readonly TemplateVariableDeclarationsInterface $templateVariableDeclarations,
        private readonly DirectiveReferenceParserInterface $referenceParser,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getVariableGroups(string $templateId, int $storeId = 0): array
    {
        $groups = [];

        $systemVariables = $this->getSystemVariables();
        if (!empty($systemVariables)) {
            $groups[] = [
                'code' => self::GROUP_SYSTEM,
                'label' => (string)__('System Variables'),
                'variables' => $systemVariables,
            ];
        }

        $customVariables = $this->getCustomVariables();
        if (!empty($customVariables)) {
            $groups[] = [
                'code' => self::GROUP_CUSTOM,
                'label' => (string)__('Custom Variables'),
                'variables' => $customVariables,
            ];
        }

        $templateVariables = $this->getTemplateVariables($templateId);
        if (!empty($templateVariables)) {
            $groups[] = [
                'code' => self::GROUP_TEMPLATE,
                'label' => (string)__('Template Variables'),
                'variables' => $templateVariables,
            ];
        }

        return $groups;
    }

    /**
     * Get system configuration variables
     *
     * @return array<int, array{label: string, value: string, reference: string}>
     */
    private function getSystemVariables(): array
    {
        $variables = [];

        try {
            $configVars = $this->configVariables->toOptionArray(true);

            foreach ($configVars as $group) {
                if (!isset($group['value']) || !is_array($group['value'])) {
                    continue;
                }

                foreach ($group['value'] as $variable) {
                    if (isset($variable['value'], $variable['label'])) {
                        $directive = (string)$variable['value'];
                        $variables[] = [
                            'label' => (string)$variable['label'],
                            'value' => $directive,
                            'reference' => $this->configReference($directive),
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to load system variables: ' . $e->getMessage());
        }

        return $variables;
    }

    /**
     * Get custom variables defined in admin
     *
     * The index behind this is read once per request and shared with everything else that has to
     * turn a variable code into a name, so opening the chooser and describing the directives in the
     * content cost one query between them rather than one each.
     *
     * @return array<int, array{label: string, value: string, reference: string}>
     */
    private function getCustomVariables(): array
    {
        $variables = [];

        foreach ($this->customVariableIndex->getAll() as $variable) {
            $variables[] = [
                'label' => $variable['name'],
                'value' => '{{customVar code=' . $variable['code'] . '}}',
                'reference' => $this->canonicalReference(self::KIND_CUSTOM_VARIABLE, $variable['code']),
            ];
        }

        return $variables;
    }

    /**
     * Get template-specific variables from the template's own declarations
     *
     * The declarations come back without their braces, because a template may write them either way
     * round and the two mean the same directive. Putting the braces back on here is what turns a
     * declaration into something an author can insert into the content as it stands.
     *
     * @param string $templateId
     * @return array<int, array{label: string, value: string, reference: string}>
     */
    private function getTemplateVariables(string $templateId): array
    {
        $variables = [];

        foreach ($this->templateVariableDeclarations->getDeclarations($templateId) as $directive => $label) {
            $variables[] = [
                'label' => $label,
                'value' => '{{' . $directive . '}}',
                'reference' => $this->declarationReference($directive),
            ];
        }

        return $variables;
    }

    /**
     * The reference behind one of Magento's configuration variable options
     *
     * @param string $directive Directive the option inserts, braces included
     * @return string Canonical reference, or an empty string when the option is not shaped like one
     */
    private function configReference(string $directive): string
    {
        if (preg_match(self::CONFIG_DIRECTIVE_PATTERN, $directive, $matches) !== 1) {
            return '';
        }

        return $this->canonicalReference(self::KIND_CONFIG, $matches['path']);
    }

    /**
     * The reference behind one of a template's own variable declarations
     *
     * Only plain variable declarations get one. For those, everything after the kind is the
     * expression - up to the pipe where the renderer stops reading it - so the reference follows
     * from the declaration alone. A declaration of any other kind is identified by one of its
     * parameters, and which parameter that is has already been settled in the one place that reads
     * directives out of a document; deciding it a second time here is how two spellings of one
     * grammar drift apart until a chooser row and the identical directive in the content stop
     * meaning the same thing. Such a row is offered without a reference and simply explains nothing.
     *
     * @param string $directive Declared directive without its braces
     * @return string Canonical reference, or an empty string when this provider cannot name one
     */
    private function declarationReference(string $directive): string
    {
        $parts = preg_split('/\s+/', trim($directive), 2);

        if ($parts === false || !isset($parts[1]) || strtolower($parts[0]) !== self::KIND_VARIABLE) {
            return '';
        }

        $expression = strstr($parts[1], self::MODIFIER_SEPARATOR, true);

        return $this->canonicalReference(
            self::KIND_VARIABLE,
            $expression === false ? $parts[1] : $expression
        );
    }

    /**
     * Turn a kind and an expression into the canonical reference a row carries
     *
     * A parameter the parser refuses - one carrying a brace, a line break or a NUL byte - yields no
     * reference rather than an approximation of one. Nothing could ever look such a reference up, so
     * the honest answer is that this row has none.
     *
     * @param string $kind Directive kind
     * @param string $expression Expression exactly as its source wrote it, quotes and all
     * @return string Canonical reference, or an empty string when one cannot be built
     */
    private function canonicalReference(string $kind, string $expression): string
    {
        try {
            return $this->referenceParser->create($kind, $expression)->toCanonicalString();
        } catch (InvalidArgumentException $e) {
            return '';
        }
    }
}
