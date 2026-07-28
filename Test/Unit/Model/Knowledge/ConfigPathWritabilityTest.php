<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathReadability;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathWritability;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config\Value;
use Magento\Variable\Model\Source\Variables as ConfigVariables;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The gates that decide whether a configuration value may be written from the editor.
 *
 * Each gate gets a test of its own, failing for its own reason, because the whole point of the
 * arrangement is that a refusal names what is missing rather than reporting a general "no".
 */
class ConfigPathWritabilityTest extends TestCase
{
    /**
     * The paths a {{config}} directive may read, as the email filter's own source lists them
     */
    private const AVAILABLE_VARS = [
        'web/unsecure/base_url',
        'web/secure/base_url',
        'trans_email/ident_general/name',
        'trans_email/ident_general/email',
        'general/store_information/name',
        'general/store_information/phone',
        'general/store_information/hours',
        'general/store_information/country_id',
        'general/store_information/region_id',
        'general/store_information/city',
    ];

    private Structure&MockObject $configStructure;

    private ConfigVariables&MockObject $configVariables;

    private ConfigPathWritability $writability;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->configStructure = $this->createMock(Structure::class);
        $this->configVariables = $this->createMock(ConfigVariables::class);
        // The real shape: a list of paths, not a map keyed by them. The readable-path decision is a
        // collaborator rather than a mock here, so that a regression to searching the values of the
        // neighbouring keyed map - which can never match - fails these tests as well as its own.
        $this->configVariables->method('getAvailableVars')->willReturn(self::AVAILABLE_VARS);

        $this->writability = new ConfigPathWritability(
            $this->configStructure,
            new ConfigPathReadability($this->configVariables)
        );
    }

    /**
     * A plain text field on the readable list, offered at the scope being written, with no backend
     * model in the way.
     *
     * @return void
     */
    public function testAPlainStoreInformationFieldIsWritable(): void
    {
        $this->structureAnswers([
            'general/store_information/name' => $this->field(['label' => 'Store Name', 'store' => true]),
        ]);

        $verdict = $this->writability->evaluate('general/store_information/name', 3);

        self::assertTrue($verdict->isWritable());
        self::assertSame('', $verdict->getReason());
    }

    /**
     * The point of routing the write through the path the configuration form itself uses: an
     * ordinary backend model runs, so it is no reason to refuse.
     *
     * @return void
     */
    public function testAFieldWithAnOrdinaryBackendModelIsWritable(): void
    {
        $this->structureAnswers([
            'trans_email/ident_general/email' => $this->field([
                'label' => 'Sender Email',
                'store' => true,
                'backendModel' => $this->createMock(Value::class),
            ]),
        ]);

        self::assertTrue($this->writability->evaluate('trans_email/ident_general/email', 3)->isWritable());
    }

    /**
     * @return void
     */
    public function testAFieldWithAnEncryptedBackendModelIsRefused(): void
    {
        $this->structureAnswers([
            'trans_email/ident_general/name' => $this->field([
                'label' => 'Sender Name',
                'store' => true,
                'backendModel' => $this->createMock(Encrypted::class),
            ]),
        ]);

        $verdict = $this->writability->evaluate('trans_email/ident_general/name', 3);

        self::assertFalse($verdict->isWritable());
        self::assertStringContainsString('stored encrypted', $verdict->getReason());
    }

    /**
     * Not knowing what a backend model does is not a reason to write through it.
     *
     * @return void
     */
    public function testAFieldWhoseBackendModelCannotBeBuiltIsRefused(): void
    {
        $field = $this->field(['label' => 'Sender Name', 'store' => true]);
        $field->method('hasBackendModel')->willReturn(true);
        $field->method('getBackendModel')->willThrowException(new \RuntimeException('no such class'));

        $this->structureAnswers(['trans_email/ident_general/name' => $field]);

        $verdict = $this->writability->evaluate('trans_email/ident_general/name', 3);

        self::assertFalse($verdict->isWritable());
        self::assertStringContainsString('cannot be loaded', $verdict->getReason());
    }

    /**
     * A path outside the filter's list renders as an empty string, so editing it would change
     * nothing an administrator could observe.
     *
     * @return void
     */
    public function testAPathTheFilterCannotReadIsRefused(): void
    {
        $verdict = $this->writability->evaluate('general/locale/timezone', 0);

        self::assertFalse($verdict->isWritable());
        self::assertStringContainsString('renders as an empty string', $verdict->getReason());
        self::assertStringContainsString('general/locale/timezone', $verdict->getReason());
    }

    /**
     * A blast-radius rule: the base URL fields validate perfectly well and are still not edited
     * from here.
     *
     * @dataProvider baseUrlPathProvider
     *
     * @param string $path Base URL configuration path
     * @param int $storeId Scope to try
     * @return void
     */
    public function testABaseUrlPathIsRefusedAtEveryScope(string $path, int $storeId): void
    {
        $this->structureAnswers([
            $path => $this->field(['label' => 'Base URL', 'store' => true]),
        ]);

        $verdict = $this->writability->evaluate($path, $storeId);

        self::assertFalse($verdict->isWritable());
        self::assertStringContainsString('not editable from the email template editor', $verdict->getReason());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public function baseUrlPathProvider(): array
    {
        return [
            'unsecure at default scope' => ['web/unsecure/base_url', 0],
            'unsecure at store scope' => ['web/unsecure/base_url', 3],
            'secure at store scope' => ['web/secure/base_url', 3],
        ];
    }

    /**
     * The value the directive renders is not the value that is stored, so an editor pre-filled from
     * the preview would store a name where a code or an id belongs.
     *
     * @dataProvider renderedDifferentlyPathProvider
     *
     * @param string $path Configuration path whose rendered value diverges from the stored one
     * @param int $storeId Scope to try
     * @return void
     */
    public function testAPathWhoseRenderedValueIsNotTheStoredValueIsRefusedAtEveryScope(
        string $path,
        int $storeId
    ): void {
        $this->structureAnswers([
            $path => $this->field(['label' => 'Country', 'store' => true]),
        ]);

        $verdict = $this->writability->evaluate($path, $storeId);

        self::assertFalse($verdict->isWritable());
        self::assertStringContainsString('does not', $verdict->getReason());
        self::assertStringContainsString('render', $verdict->getReason());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public function renderedDifferentlyPathProvider(): array
    {
        return [
            'country at default scope' => ['general/store_information/country_id', 0],
            'country at store scope' => ['general/store_information/country_id', 3],
            'region at default scope' => ['general/store_information/region_id', 0],
            'region at store scope' => ['general/store_information/region_id', 3],
        ];
    }

    /**
     * A field the configuration form does not offer for a store view can still be written for one,
     * and the result is an override the administrator can never see or clear - so it is refused at
     * store scope and allowed at the default scope.
     *
     * @return void
     */
    public function testAFieldHiddenAtStoreScopeIsRefusedThereAndAllowedAtTheDefaultScope(): void
    {
        $this->structureAnswers([
            'general/store_information/city' => $this->field([
                'label' => 'City',
                'default' => true,
                'website' => true,
                'store' => false,
            ]),
        ]);

        $refused = $this->writability->evaluate('general/store_information/city', 3);

        self::assertFalse($refused->isWritable());
        self::assertStringContainsString('single store view', $refused->getReason());
        self::assertTrue($this->writability->evaluate('general/store_information/city', 0)->isWritable());
    }

    /**
     * @return void
     */
    public function testAFieldOfferedAtStoreScopeIsWritableAtBothScopes(): void
    {
        $this->structureAnswers([
            'general/store_information/name' => $this->field(['label' => 'Store Name', 'store' => true]),
        ]);

        self::assertTrue($this->writability->evaluate('general/store_information/name', 3)->isWritable());
        self::assertTrue($this->writability->evaluate('general/store_information/name', 0)->isWritable());
    }

    /**
     * @return void
     */
    public function testAFieldHiddenAtTheDefaultScopeIsRefusedThere(): void
    {
        $this->structureAnswers([
            'general/store_information/phone' => $this->field([
                'label' => 'Store Phone Number',
                'default' => false,
                'website' => true,
                'store' => true,
            ]),
        ]);

        $verdict = $this->writability->evaluate('general/store_information/phone', 0);

        self::assertFalse($verdict->isWritable());
        self::assertStringContainsString('default scope', $verdict->getReason());
    }

    /**
     * The structure answers a path it has never heard of with a placeholder element rather than
     * with null, so a field with nothing on it is what "no such field" looks like.
     *
     * @return void
     */
    public function testAPathTheStructureDoesNotDeclareIsRefused(): void
    {
        $this->structureAnswers([
            'general/store_information/hours' => $this->field([]),
        ]);

        $verdict = $this->writability->evaluate('general/store_information/hours', 0);

        self::assertFalse($verdict->isWritable());
        self::assertStringContainsString('No field is declared', $verdict->getReason());
    }

    /**
     * @return void
     */
    public function testAnEmptyPathIsRefused(): void
    {
        $verdict = $this->writability->evaluate('', 0);

        self::assertFalse($verdict->isWritable());
        self::assertStringContainsString('names no configuration path', $verdict->getReason());
    }

    /**
     * Teach the structure mock what it holds, answering anything else with the placeholder element
     * the real structure would return.
     *
     * @param array<string, Field&MockObject> $elements Configuration path to the field behind it
     * @return void
     */
    private function structureAnswers(array $elements): void
    {
        $this->configStructure
            ->method('getElementByConfigPath')
            ->willReturnCallback(fn (string $path): Field => $elements[$path] ?? $this->field([]));
    }

    /**
     * A configuration field mock
     *
     * @param array{label?: string, default?: bool, website?: bool, store?: bool, type?: string,
     *              backendModel?: object, comment?: string, options?: array<int, mixed>} $spec
     *              What the field declares; anything absent is declared as absent
     * @return Field&MockObject
     */
    private function field(array $spec): Field
    {
        $field = $this->createMock(Field::class);
        $field->method('getLabel')->willReturn($spec['label'] ?? '');
        $field->method('getComment')->willReturn($spec['comment'] ?? '');
        $field->method('getType')->willReturn($spec['type'] ?? 'text');
        $field->method('getOptions')->willReturn($spec['options'] ?? []);
        // An empty specification is the placeholder the structure hands back for a path it has
        // never heard of: no label and none of the scope flags.
        $declared = $spec !== [];
        $field->method('showInDefault')->willReturn($spec['default'] ?? $declared);
        $field->method('showInWebsite')->willReturn($spec['website'] ?? $declared);
        $field->method('showInStore')->willReturn($spec['store'] ?? false);

        if (isset($spec['backendModel'])) {
            $field->method('hasBackendModel')->willReturn(true);
            $field->method('getBackendModel')->willReturn($spec['backendModel']);
        }

        return $field;
    }
}
