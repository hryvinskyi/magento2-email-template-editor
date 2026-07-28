<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\WritabilityVerdict;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WritabilityVerdictTest extends TestCase
{
    /**
     * @return void
     */
    public function testAnAllowedVerdictCarriesNoReason(): void
    {
        $verdict = WritabilityVerdict::allowed();

        self::assertTrue($verdict->isWritable());
        self::assertSame('', $verdict->getReason());
    }

    /**
     * @return void
     */
    public function testARefusedVerdictCarriesItsReason(): void
    {
        $verdict = WritabilityVerdict::refused('The value is stored encrypted.');

        self::assertFalse($verdict->isWritable());
        self::assertSame('The value is stored encrypted.', $verdict->getReason());
    }

    /**
     * A refusal with nothing written on it would reach an administrator as a value that cannot be
     * changed, with no word about why.
     *
     * @dataProvider blankReasonProvider
     *
     * @param string $reason Reason to refuse with
     * @return void
     */
    public function testARefusalWithoutAReasonIsRejected(string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry its reason');

        WritabilityVerdict::refused($reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public function blankReasonProvider(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'newline' => ["\n"],
        ];
    }
}
