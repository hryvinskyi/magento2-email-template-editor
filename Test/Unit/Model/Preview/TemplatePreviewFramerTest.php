<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Preview;

use Hryvinskyi\EmailTemplateEditor\Api\ConfiguredTemplateResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Preview\PreviewPlaceholder;
use Hryvinskyi\EmailTemplateEditor\Model\Preview\SurroundingTemplatesFrame;
use Hryvinskyi\EmailTemplateEditor\Model\Preview\TemplatePreviewFramer;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

/**
 * A header or a footer is previewed inside the email it belongs to.
 *
 * The framer is built the way di.xml builds it, with the real frames and the real placeholder, so
 * what is proved here is the content the renderer is handed. Which template a store includes by
 * each path is answered by a fake standing in for its design configuration.
 */
class TemplatePreviewFramerTest extends TestCase
{
    private const HEADER_INCLUDE = '{{template config_path="design/email/header_template"}}';
    private const FOOTER_INCLUDE = '{{template config_path="design/email/footer_template"}}';
    private const PLACEHOLDER_TEXT = 'The email content appears here';

    /**
     * Framer wired the way di.xml wires it
     *
     * @var TemplatePreviewFramer
     */
    private TemplatePreviewFramer $framer;

    /**
     * Templates each store's design configuration includes, by config path; the stock one is implied
     *
     * @var array<int, array<string, string>>
     */
    private array $configured = [];

    /**
     * Build the framer with the header and footer frames di.xml configures
     *
     * @return void
     */
    protected function setUp(): void
    {
        $placeholder = new PreviewPlaceholder(new Escaper());

        $configuredTemplates = $this->createMock(ConfiguredTemplateResolverInterface::class);
        $configuredTemplates->method('identifiersFor')->willReturnCallback(
            fn (string $configPath, int $storeId): array => array_values(array_unique(array_filter([
                str_replace('/', '_', $configPath),
                $this->configured[$storeId][$configPath] ?? '',
            ])))
        );

        $this->framer = new TemplatePreviewFramer($configuredTemplates, [
            'design/email/header_template' => new SurroundingTemplatesFrame(
                $placeholder,
                '',
                'design/email/footer_template'
            ),
            'design/email/footer_template' => new SurroundingTemplatesFrame(
                $placeholder,
                'design/email/header_template'
            ),
        ]);
    }

    /**
     * The edited header comes first, then the body placeholder, then the configured footer
     *
     * @return void
     */
    public function testHeaderIsFollowedByPlaceholderAndConfiguredFooter(): void
    {
        $framed = $this->framer->frame('design_email_header_template', '<html><body><table><tr><td>', 1);

        $this->assertStringStartsWith('<html><body><table><tr><td>', $framed);
        $this->assertStringEndsWith(self::FOOTER_INCLUDE, $framed);
        $this->assertStringNotContainsString(self::HEADER_INCLUDE, $framed);
        $this->assertLessThan(
            (int)strpos($framed, self::FOOTER_INCLUDE),
            (int)strpos($framed, self::PLACEHOLDER_TEXT)
        );
    }

    /**
     * The configured header comes first, then the body placeholder, then the edited footer
     *
     * @return void
     */
    public function testFooterIsPrecededByConfiguredHeaderAndPlaceholder(): void
    {
        $framed = $this->framer->frame('design_email_footer_template', '</td></tr></table></body></html>', 1);

        $this->assertStringStartsWith(self::HEADER_INCLUDE, $framed);
        $this->assertStringEndsWith('</td></tr></table></body></html>', $framed);
        $this->assertStringNotContainsString(self::FOOTER_INCLUDE, $framed);
        $this->assertGreaterThan(
            (int)strpos($framed, self::HEADER_INCLUDE),
            (int)strpos($framed, self::PLACEHOLDER_TEXT)
        );
    }

    /**
     * An override saved against a theme is the same template and gets the same frame
     *
     * @return void
     */
    public function testThemeSpecificIdentifierIsFramedLikeTheBareOne(): void
    {
        $framed = $this->framer->frame('design_email_footer_template/Magento/luma', '<p>footer</p>', 1);

        $this->assertStringStartsWith(self::HEADER_INCLUDE, $framed);
    }

    /**
     * A template that is a whole email already is left exactly as it is
     *
     * @return void
     */
    public function testTemplateWithoutFrameIsReturnedUnchanged(): void
    {
        $content = '{{template config_path="design/email/header_template"}}<p>Hi</p>';

        $this->assertSame($content, $this->framer->frame('contact_email_email_template', $content, 1));
    }

    /**
     * A store whose configuration points its header at another template frames that one as a header
     *
     * @return void
     */
    public function testTheTemplateAStoreIsConfiguredWithIsFramedLikeTheStockOne(): void
    {
        $this->configured[2]['design/email/header_template'] = 'acme_header';

        self::assertStringEndsWith(self::FOOTER_INCLUDE, $this->framer->frame('acme_header', '<html><body>', 2));
    }

    /**
     * What one store is configured with says nothing about another store
     *
     * @return void
     */
    public function testAnotherStoresConfigurationDoesNotFrameATemplate(): void
    {
        $this->configured[2]['design/email/header_template'] = 'acme_header';

        self::assertSame('<html><body>', $this->framer->frame('acme_header', '<html><body>', 1));
    }
}
