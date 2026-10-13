<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Preview;

use Hryvinskyi\EmailTemplateEditor\Api\ConfiguredTemplateResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\LegacyTemplateRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ConfiguredTemplateResolver implements ConfiguredTemplateResolverInterface
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param LegacyTemplateRepositoryInterface $legacyTemplates
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LegacyTemplateRepositoryInterface $legacyTemplates
    ) {
    }

    /**
     * @inheritDoc
     *
     * A file template's identifier is its configuration path with the slashes made underscores,
     * which is how Magento names the stock template of every design/email path. A numeric value is
     * the id of a legacy row, which the editor knows by the template it was created from, or by
     * its own code when it was created from none - the same key the runtime overlay looks it up by.
     */
    public function identifiersFor(string $configPath, int $storeId): array
    {
        $identifiers = [str_replace('/', '_', $configPath)];
        $configured = $this->configuredIdentifier(
            $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE, $storeId)
        );

        if ($configured !== null && !in_array($configured, $identifiers, true)) {
            $identifiers[] = $configured;
        }

        return $identifiers;
    }

    /**
     * Identifier of the template a configuration value points at
     *
     * @param mixed $value Raw configuration value
     * @return string|null
     */
    private function configuredIdentifier(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            return $value;
        }

        $legacy = $this->legacyTemplates->getById((int)$value);

        if ($legacy === null) {
            return null;
        }

        foreach (['orig_template_code', 'template_code'] as $field) {
            $code = $legacy->getData($field);

            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return null;
    }
}
