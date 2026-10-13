<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Controller\Adminhtml\Template;

use Hryvinskyi\EmailTemplateEditor\Api\TemplateDraftSaverInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Template\SaveDraft;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

/**
 * The save request is passed on as it was sent: what it leaves out stays out.
 */
class SaveDraftTest extends TestCase
{
    /**
     * Request parameters
     *
     * @var array<string, mixed>
     */
    private array $params = [
        'template_identifier' => 'design_email_footer_template',
        'store_id' => '4',
        'template_content' => '<p>x</p>',
    ];

    /**
     * Arguments the saver was called with
     *
     * @var array<int|string, mixed>
     */
    private array $saved = [];

    /**
     * Answer sent back to the editor
     *
     * @var mixed
     */
    private mixed $payload = null;

    /**
     * Only the fields the request carries reach the saver; an empty one is passed on as empty
     *
     * @return void
     */
    public function testOnlyTheFieldsTheRequestCarriesArePassedOn(): void
    {
        $this->params += ['custom_css' => '', 'provider_code' => 'mock', 'entity_id' => '7'];

        $this->execute();

        self::assertSame(['custom_css' => '', 'sample_provider_code' => 'mock'], $this->saved[3]);
        self::assertSame(7, $this->saved[4]);
        self::assertFalse($this->saved[6], 'A save is not a publish unless it says so.');
    }

    /**
     * The publish flow says so, and the editor is told when its edit was moved to a draft
     *
     * @return void
     */
    public function testThePublishIntentIsPassedOnAndAForkIsReported(): void
    {
        $this->params['intent'] = 'publish';

        $this->execute();

        self::assertTrue($this->saved[6]);
        self::assertIsArray($this->payload);
        self::assertSame(['success' => true, 'entity_id' => 9, 'forked' => true], array_slice($this->payload, 0, 3));
    }

    /**
     * Run the controller over a saver that records its arguments
     *
     * @return void
     */
    private function execute(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default
        );

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(
            function (mixed $data) use ($json): Json {
                $this->payload = $data;

                return $json;
            }
        );
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $saver = $this->createMock(TemplateDraftSaverInterface::class);
        $saver->method('save')->willReturnCallback(
            function (mixed ...$arguments): array {
                $this->saved = $arguments;

                return ['entity_id' => 9, 'forked' => true];
            }
        );

        // Nobody is signed in: the session's user is served by its magic accessor.
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $session->method('__call')->willReturn(null);

        (new SaveDraft($context, $jsonFactory, $saver, $session))->execute();
    }
}
