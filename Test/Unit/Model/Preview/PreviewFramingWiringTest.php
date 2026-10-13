<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Preview;

use Hryvinskyi\EmailTemplateEditor\Model\LegacyTemplateRepository;
use Hryvinskyi\EmailTemplateEditor\Api\TemplatePreviewFramerInterface;
use Hryvinskyi\EmailTemplateEditor\Test\Unit\Container\ModuleContainer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * The framer an installation hands out frames a header and a footer.
 *
 * Asked of Magento's own object manager configured from etc/di.xml, so the preference, both
 * virtual types, the frames array and the configured-template resolver are proved as the container
 * resolves them. The store configuration and the legacy template table are the installation's,
 * so a configuration with nothing set and an empty table stand in for them - the table under the
 * class the module's preference names, since that is what the container is asked for.
 */
class PreviewFramingWiringTest extends TestCase
{
    /**
     * The framer the container builds
     *
     * @var TemplatePreviewFramerInterface
     */
    private TemplatePreviewFramerInterface $framer;

    /**
     * Ask the module's container for the framer
     *
     * @return void
     */
    protected function setUp(): void
    {
        $framer = (new ModuleContainer())->build('global', [
            ScopeConfigInterface::class => $this->createMock(ScopeConfigInterface::class),
            LegacyTemplateRepository::class => $this->createMock(LegacyTemplateRepository::class),
        ])->get(TemplatePreviewFramerInterface::class);
        self::assertInstanceOf(TemplatePreviewFramerInterface::class, $framer);
        $this->framer = $framer;
    }

    /**
     * A header is closed by the configured footer
     *
     * @return void
     */
    public function testHeaderIsFramedWithTheConfiguredFooter(): void
    {
        self::assertStringEndsWith(
            '{{template config_path="design/email/footer_template"}}',
            $this->framer->frame('design_email_header_template', '<html><body>', 1)
        );
    }

    /**
     * A footer is opened by the configured header
     *
     * @return void
     */
    public function testFooterIsFramedWithTheConfiguredHeader(): void
    {
        self::assertStringStartsWith(
            '{{template config_path="design/email/header_template"}}',
            $this->framer->frame('design_email_footer_template', '</body></html>', 1)
        );
    }
}
