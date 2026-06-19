<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Model\ThemeJsonValidator;
use PHPUnit\Framework\TestCase;

class ThemeJsonValidatorTest extends TestCase
{
    private ThemeJsonValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ThemeJsonValidator();
    }

    public function testEmptyInputRejected(): void
    {
        self::assertFalse($this->validator->validate(''));
        self::assertNotEmpty($this->validator->getErrors());
    }

    public function testWhitespaceOnlyInputRejected(): void
    {
        self::assertFalse($this->validator->validate("\n  \t"));
    }

    public function testCssWithThemeBlockAccepted(): void
    {
        self::assertTrue($this->validator->validate("@theme { --color-primary: #131CCF; }"));
        self::assertSame([], $this->validator->getErrors());
    }

    public function testCssThemeBlockWithSurroundingContentAccepted(): void
    {
        $css = <<<CSS
@import url("https://fonts.googleapis.com/css2?family=Inter");
@theme {
  --color-primary: #131CCF;
}
.brand { background: var(--color-primary); }
CSS;
        self::assertTrue($this->validator->validate($css));
    }

    public function testCssWithoutThemeBlockRejected(): void
    {
        self::assertFalse($this->validator->validate('.brand { color: red; }'));
        self::assertNotEmpty($this->validator->getErrors());
    }

    public function testCssWithUnbalancedBracesRejected(): void
    {
        self::assertFalse($this->validator->validate('@theme { --x: red;'));
    }

    public function testPlainTextRejected(): void
    {
        self::assertFalse($this->validator->validate('this is not css'));
    }

    public function testLegacyJsonWithTokensSectionAccepted(): void
    {
        $json = json_encode(['tokens' => ['colors' => ['primary' => '#131CCF']]]);
        self::assertTrue($this->validator->validate($json));
    }

    public function testLegacyJsonWithOnlyElementsSectionAccepted(): void
    {
        $json = json_encode(['elements' => ['body' => ['color' => '#333']]]);
        self::assertTrue($this->validator->validate($json));
    }

    public function testJsonWithoutRecognisedKeysRejectedAsCss(): void
    {
        // Looks like JSON but has no `tokens|elements|utilities` -> treated as CSS,
        // and since there's no `@theme` block it's rejected with the CSS error.
        $json = json_encode(['unrelated' => 'value']);
        self::assertFalse($this->validator->validate($json));
    }

    public function testErrorsAreClearedBetweenValidations(): void
    {
        $this->validator->validate('garbage');
        self::assertNotEmpty($this->validator->getErrors());

        $this->validator->validate("@theme { --x: red; }");
        self::assertSame([], $this->validator->getErrors());
    }
}
