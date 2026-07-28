<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathReadability;
use Magento\Variable\Model\Source\Variables as ConfigVariables;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The list of configuration paths a {{config}} directive renders.
 *
 * The stub answers in the shape the real source answers in - a plain list of paths - so that asking
 * it the wrong way round, which is the one mistake this class exists to make impossible, fails here.
 */
class ConfigPathReadabilityTest extends TestCase
{
    private const AVAILABLE_VARS = [
        'web/unsecure/base_url',
        'trans_email/ident_general/name',
        'general/store_information/name',
    ];

    private ConfigVariables&MockObject $configVariables;

    private ConfigPathReadability $readability;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('getAvailableVars')->willReturn(self::AVAILABLE_VARS);

        $this->readability = new ConfigPathReadability($this->configVariables);
    }

    /**
     * @dataProvider readablePathProvider
     *
     * @param string $path Path the email filter reads
     * @return void
     */
    public function testAPathOnTheFiltersListIsReadable(string $path): void
    {
        self::assertTrue($this->readability->isReadable($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public function readablePathProvider(): array
    {
        return [
            'a base URL' => ['web/unsecure/base_url'],
            'a sender name' => ['trans_email/ident_general/name'],
            'the store name' => ['general/store_information/name'],
        ];
    }

    /**
     * A path outside the list renders as an empty string however plausible it looks, and looking
     * plausible is exactly why it has to be checked rather than assumed.
     *
     * @return void
     */
    public function testAPathOutsideTheFiltersListIsNotReadable(): void
    {
        self::assertFalse($this->readability->isReadable('general/locale/timezone'));
        self::assertFalse($this->readability->isReadable('general/store_information/name_extra'));
    }

    /**
     * The comparison is exact: a path is a whole path, not a prefix of one.
     *
     * @return void
     */
    public function testAGroupContainingReadablePathsIsNotItselfReadable(): void
    {
        self::assertFalse($this->readability->isReadable('general/store_information'));
    }

    public function testAnEmptyPathIsNotReadable(): void
    {
        $this->configVariables->expects(self::never())->method('getAvailableVars');

        self::assertFalse($this->readability->isReadable(''));
    }
}
