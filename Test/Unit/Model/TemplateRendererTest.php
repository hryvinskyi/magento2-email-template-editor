<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Api\ConfiguredTemplateResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\EditorContextFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateAreaResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\UtilityCssGeneratorInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Css\EmailStylesheetCollector;
use Hryvinskyi\EmailTemplateEditor\Model\Preview\PreviewPlaceholder;
use Hryvinskyi\EmailTemplateEditor\Model\Preview\SurroundingTemplatesFrame;
use Hryvinskyi\EmailTemplateEditor\Model\Preview\TemplatePreviewFramer;
use Hryvinskyi\EmailTemplateEditor\Model\TemplateRenderer;
use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use Magento\Framework\App\State;
use Magento\Framework\Escaper;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the renderer hands Magento's template processor.
 *
 * Magento itself is replaced by a template double that echoes the text it was given, so these
 * tests prove the content the editor builds around a template and the stylesheet it hands the
 * render - not what the filter then makes of the directives in it, nor the inlining, which the
 * render's own stylesheet scope performs.
 */
class TemplateRendererTest extends TestCase
{
    private const FOOTER_INCLUDE = '{{template config_path="design/email/footer_template"}}';

    /**
     * Text the template double was last given
     *
     * @var string
     */
    private string $processedText = '';

    /**
     * Renderer under test
     *
     * @var TemplateRenderer
     */
    private TemplateRenderer $renderer;

    /**
     * The template double every render is given
     *
     * @var Template&MockObject
     */
    private MockObject $template;

    /**
     * The real stylesheet collector the render hands the draft's CSS to
     *
     * @var EmailStylesheetCollector
     */
    private EmailStylesheetCollector $stylesheets;

    /**
     * Build the renderer with the real preview framing and doubles for Magento's machinery
     *
     * @return void
     */
    protected function setUp(): void
    {
        $template = $this->createMock(Template::class);
        $this->template = $template;
        $template->method('setTemplateText')->willReturnCallback(
            function (string $text) use ($template): Template {
                $this->processedText = $text;

                return $template;
            }
        );
        $template->method('getProcessedTemplate')->willReturnCallback(fn (): string => $this->processedText);

        $templateFactory = $this->createMock(TemplateFactory::class);
        $templateFactory->method('create')->willReturn($template);

        $appState = $this->createMock(State::class);
        $appState->method('emulateAreaCode')->willReturnCallback(
            static fn (string $area, callable $callback): mixed => $callback()
        );

        $this->stylesheets = new EmailStylesheetCollector();

        $generator = $this->createMock(UtilityCssGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(static fn (string $json): string => 'generated:' . $json);

        $configuredTemplates = $this->createMock(ConfiguredTemplateResolverInterface::class);
        $configuredTemplates->method('identifiersFor')->willReturnCallback(
            static fn (string $configPath): array => [str_replace('/', '_', $configPath)]
        );

        $areaResolver = $this->createMock(TemplateAreaResolverInterface::class);
        $areaResolver->method('resolve')->willReturn('frontend');

        $placeholder = new PreviewPlaceholder(new Escaper());

        $this->renderer = new TemplateRenderer(
            $areaResolver,
            $this->stylesheets,
            $generator,
            $this->createMock(ThemeRepositoryInterface::class),
            $appState,
            $this->createMock(Emulation::class),
            $templateFactory,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(EditorContextFlagInterface::class),
            new TemplatePreviewFramer($configuredTemplates, [
                'design/email/header_template' => new SurroundingTemplatesFrame(
                    $placeholder,
                    '',
                    'design/email/footer_template'
                ),
            ]),
            $placeholder,
            new Escaper()
        );
    }

    /**
     * A header is rendered inside the email it opens, closed by the configured footer
     *
     * @return void
     */
    public function testHeaderIsRenderedAsAWholeEmail(): void
    {
        $html = $this->renderer->render('<html><body><table>', [], 1, null, null, 'design_email_header_template');

        $this->assertStringStartsWith('<html><body><table>', $html);
        $this->assertStringContainsString('The email content appears here', $html);
        $this->assertStringEndsWith(self::FOOTER_INCLUDE, $html);
    }

    /**
     * Content rendered without an identifier is not guessed at
     *
     * @return void
     */
    public function testContentWithoutIdentifierIsRenderedAsGiven(): void
    {
        $this->assertSame('<p>Hi</p>', $this->renderer->render('<p>Hi</p>', [], 1));
    }

    /**
     * With mock data a layout block is shown as a labelled placeholder rather than rendered
     *
     * @return void
     */
    public function testLayoutDirectiveBecomesPlaceholderWithMockData(): void
    {
        $html = $this->renderer->render(
            '<div>{{layout handle="sales_email_order_items" order_id=$order_id area="frontend"}}</div>',
            [],
            1,
            null,
            null,
            'sales_email_order_template',
            true
        );

        $this->assertStringContainsString('sales_email_order_items requires real data to render', $html);
        $this->assertStringNotContainsString('{{layout', $html);
    }

    /**
     * The draft's CSS becomes the rendered template's own sheet, the editor's theme supplying the tokens
     *
     * The render's stylesheet scope then inlines it into the finished email together with the CSS
     * of every header and footer the content includes, exactly as a real send does.
     *
     * @return void
     */
    public function testDraftCssIsTheRenderedTemplatesOwnSheet(): void
    {
        $this->template->expects(self::never())->method('setTemplateStyles');

        $this->renderer->render('<p class="p-4">Hi</p>', [], 1, '.x{}', '.p-4{}', null, false, '{"theme":1}');

        $this->stylesheets->begin();
        self::assertSame(
            ['theme' => 'generated:{"theme":1}', 'utility' => '.p-4{}', 'custom' => '.x{}'],
            $this->stylesheets->end($this->template)
        );
    }
}
