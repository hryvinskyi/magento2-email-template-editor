<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Preview;

use Hryvinskyi\EmailTemplateEditor\Api\LegacyTemplateRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Preview\ConfiguredTemplateResolver;
use Magento\Email\Model\BackendTemplate;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Which template a store includes as its header, whatever the configuration points at.
 */
class ConfiguredTemplateResolverTest extends TestCase
{
    private const PATH = 'design/email/header_template';
    private const STOCK = 'design_email_header_template';

    /**
     * Configuration value per store
     *
     * @var array<int, mixed>
     */
    private array $config = [];

    /**
     * Legacy rows by id, as [orig_template_code, template_code]
     *
     * @var array<int, array{0: string|null, 1: string|null}>
     */
    private array $legacyRows = [];

    /**
     * Unconfigured, a path stands for its stock template alone
     *
     * @return void
     */
    public function testAnUnconfiguredPathStandsForItsStockTemplate(): void
    {
        self::assertSame([self::STOCK], $this->resolver()->identifiersFor(self::PATH, 1));
    }

    /**
     * A file template configured by name is added after the stock one
     *
     * @return void
     */
    public function testATemplateConfiguredByNameIsAdded(): void
    {
        $this->config[1] = 'acme_header';

        self::assertSame([self::STOCK, 'acme_header'], $this->resolver()->identifiersFor(self::PATH, 1));
    }

    /**
     * A legacy row created from a stock template is known by that template
     *
     * @return void
     */
    public function testALegacyRowIsKnownByTheTemplateItWasCreatedFrom(): void
    {
        $this->config[3] = '12';
        $this->legacyRows[12] = ['sales_email_order_template', 'Order copy'];

        self::assertSame(
            [self::STOCK, 'sales_email_order_template'],
            $this->resolver()->identifiersFor(self::PATH, 3)
        );
    }

    /**
     * A legacy row created from no template is known by its own code
     *
     * @return void
     */
    public function testALegacyRowWithoutOriginIsKnownByItsOwnCode(): void
    {
        $this->config[3] = 12;
        $this->legacyRows[12] = [null, 'Custom header'];

        self::assertSame([self::STOCK, 'Custom header'], $this->resolver()->identifiersFor(self::PATH, 3));
    }

    /**
     * A configured row that no longer exists adds nothing
     *
     * @return void
     */
    public function testAMissingLegacyRowAddsNothing(): void
    {
        $this->config[3] = '99';

        self::assertSame([self::STOCK], $this->resolver()->identifiersFor(self::PATH, 3));
    }

    /**
     * The stock template configured explicitly is listed once
     *
     * @return void
     */
    public function testTheStockTemplateConfiguredExplicitlyIsListedOnce(): void
    {
        $this->config[1] = self::STOCK;

        self::assertSame([self::STOCK], $this->resolver()->identifiersFor(self::PATH, 1));
    }

    /**
     * Build the resolver over the configuration and legacy rows of the test
     *
     * @return ConfiguredTemplateResolver
     */
    private function resolver(): ConfiguredTemplateResolver
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path, string $scope, int $storeId): mixed
                => $path === self::PATH && $scope === ScopeInterface::SCOPE_STORE
                    ? ($this->config[$storeId] ?? self::STOCK)
                    : null
        );

        $legacyTemplates = $this->createMock(LegacyTemplateRepositoryInterface::class);
        $legacyTemplates->method('getById')->willReturnCallback(
            function (int $templateId): ?BackendTemplate {
                if (!isset($this->legacyRows[$templateId])) {
                    return null;
                }

                [$origin, $code] = $this->legacyRows[$templateId];
                $row = $this->createMock(BackendTemplate::class);
                $row->method('getData')->willReturnMap([
                    ['orig_template_code', null, $origin],
                    ['template_code', null, $code],
                ]);

                return $row;
            }
        );

        return new ConfiguredTemplateResolver($scopeConfig, $legacyTemplates);
    }
}
