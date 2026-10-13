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
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Template\SendTestEmail;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The test email is rendered the way the preview is: same theme, same sample data placeholders.
 */
class SendTestEmailTest extends TestCase
{
    /**
     * Arguments the renderer was called with
     *
     * @var array<int|string, mixed>
     */
    private array $rendered = [];

    /**
     * The theme the editor shows and the mock-data placeholders reach the render
     *
     * @return void
     */
    public function testTheEditorsThemeAndMockDataReachTheRender(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => [
                'recipient_email' => 'qa@example.com',
                'template_content' => '<p>x</p>',
                'template_identifier' => 'design_email_footer_template',
                'store_id' => '4',
                'theme_css' => '@theme { --color-primary: #131ccf; }',
                'provider_code' => 'mock',
            ][$key] ?? $default
        );
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnSelf();
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            function (mixed ...$arguments): string {
                $this->rendered = $arguments;

                return '<p>x</p>';
            }
        );

        $provider = $this->createMock(SampleDataProviderInterface::class);
        $provider->method('getVariables')->willReturn([]);
        $providers = $this->createMock(SampleDataProviderPoolInterface::class);
        $providers->method('getProvider')->willReturn($provider);
        $merger = $this->createMock(CustomVariableMergerInterface::class);
        $merger->method('merge')->willReturnArgument(0);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $steps = ['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'];
        foreach ($steps as $step) {
            $transportBuilder->method($step)->willReturnSelf();
        }
        $transportBuilder->method('getTransport')->willReturn($this->createMock(TransportInterface::class));

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('store@example.com');

        (new SendTestEmail(
            $context,
            $jsonFactory,
            $renderer,
            $providers,
            $merger,
            $transportBuilder,
            $storeManager,
            $scopeConfig,
            $this->createMock(LoggerInterface::class)
        ))->execute();

        self::assertTrue($this->rendered[6] ?? null, 'Mock data shows layout blocks as placeholders.');
        self::assertSame('@theme { --color-primary: #131ccf; }', $this->rendered[7] ?? null);
    }
}
