<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Controller\Adminhtml\Template;

use Hryvinskyi\EmailTemplateEditor\Api\CustomVariableMergerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\SampleDataProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Api\SampleDataProviderPoolInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateRendererInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Template\Preview;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

/**
 * The preview opened in a tab of its own is a page nothing in a template can act from.
 */
class PreviewTest extends TestCase
{
    /**
     * Headers the raw result was given
     *
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * Request parameters
     *
     * @var array<string, mixed>
     */
    private array $params = ['raw' => '1', 'template_content' => '<p>x</p>', 'store_id' => '1'];

    /**
     * The rendered email is served sandboxed, with its type stated and not sniffed
     *
     * @return void
     */
    public function testTheTabPreviewIsSandboxed(): void
    {
        $this->controller()->execute();

        self::assertSame('sandbox', $this->headers['Content-Security-Policy'] ?? null);
        self::assertSame('nosniff', $this->headers['X-Content-Type-Options'] ?? null);
        self::assertSame('text/html; charset=UTF-8', $this->headers['Content-Type'] ?? null);
    }

    /**
     * So is the page saying there was nothing to render
     *
     * @return void
     */
    public function testTheEmptyContentPageIsSandboxedToo(): void
    {
        $this->params['template_content'] = '';

        $this->controller()->execute();

        self::assertSame('sandbox', $this->headers['Content-Security-Policy'] ?? null);
    }

    /**
     * Build the controller over doubles recording the raw result's headers
     *
     * @return Preview
     */
    private function controller(): Preview
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default
        );

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $raw = $this->createMock(Raw::class);
        $raw->method('setHeader')->willReturnCallback(
            function (string $name, string $value) use ($raw): Raw {
                $this->headers[$name] = $value;

                return $raw;
            }
        );
        $raw->method('setContents')->willReturnSelf();

        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->method('render')->willReturn('<html><body><script>alert(1)</script></body></html>');

        $provider = $this->createMock(SampleDataProviderInterface::class);
        $provider->method('getVariables')->willReturn([]);
        $providers = $this->createMock(SampleDataProviderPoolInterface::class);
        $providers->method('getProvider')->willReturn($provider);

        $merger = $this->createMock(CustomVariableMergerInterface::class);
        $merger->method('merge')->willReturnArgument(0);

        return new Preview(
            $context,
            $this->createMock(JsonFactory::class),
            $rawFactory,
            $renderer,
            $providers,
            $merger,
            new Escaper()
        );
    }
}
