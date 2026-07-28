<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Css;

use Hryvinskyi\EmailTemplateEditor\Model\Css\CssSyntaxScanner;
use PHPUnit\Framework\TestCase;

class CssSyntaxScannerTest extends TestCase
{
    private CssSyntaxScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new CssSyntaxScanner();
    }

    public function testStringIsSkippedToJustPastItsClosingQuote(): void
    {
        $css = 'a"bc"d';

        self::assertSame(5, $this->scanner->skipString($css, 1));
    }

    public function testEscapedQuoteDoesNotTerminateAString(): void
    {
        $css = '"a\\"b"x';

        self::assertSame(6, $this->scanner->skipString($css, 0));
    }

    public function testUnterminatedStringRunsToTheEndOfTheInput(): void
    {
        $css = '"never closed';

        self::assertSame(13, $this->scanner->skipString($css, 0));
    }

    public function testSingleQuotesAreNotClosedByADoubleQuote(): void
    {
        $css = "'a\"b'c";

        self::assertSame(5, $this->scanner->skipString($css, 0));
    }

    public function testCommentIsSkippedToJustPastItsClosingDelimiter(): void
    {
        $css = 'a/* x */b';

        self::assertSame(8, $this->scanner->skipComment($css, 1));
    }

    public function testUnterminatedCommentRunsToTheEndOfTheInput(): void
    {
        $css = '/* never closed';

        self::assertSame(15, $this->scanner->skipComment($css, 0));
    }

    public function testNestedBlocksAreCountedWhenFindingABlockEnd(): void
    {
        $css = '@media x { .a { color: red } }';

        self::assertSame(strlen($css) - 1, $this->scanner->findBlockEnd($css, 9));
    }

    /**
     * A `content: "}"` declaration is enough to make a brace-counting regular expression cut
     * the rule short, which silently reshapes every rule that follows it.
     */
    public function testBraceInsideAStringIsNotCountedAsAStructuralBrace(): void
    {
        $css = '.a { content: "}"; color: red; }';

        self::assertSame(strlen($css) - 1, $this->scanner->findBlockEnd($css, 3));
    }

    public function testBraceInsideACommentIsNotCountedAsAStructuralBrace(): void
    {
        $css = '.a { /* } */ color: red; }';

        self::assertSame(strlen($css) - 1, $this->scanner->findBlockEnd($css, 3));
    }

    public function testUnbalancedBlockIsReportedRatherThanGuessedAt(): void
    {
        self::assertSame(-1, $this->scanner->findBlockEnd('.a { color: red;', 3));
    }

    public function testNestedParenthesesAreCountedWhenFindingAParenthesisEnd(): void
    {
        $css = 'var(--a, rgb(1 2 3))x';

        self::assertSame(19, $this->scanner->findParenthesisEnd($css, 3));
    }

    public function testParenthesisInsideAStringIsNotCountedAsStructural(): void
    {
        $css = 'url(")") ';

        self::assertSame(7, $this->scanner->findParenthesisEnd($css, 3));
    }

    public function testUnbalancedParenthesisIsReportedRatherThanGuessedAt(): void
    {
        self::assertSame(-1, $this->scanner->findParenthesisEnd('var(--a', 3));
    }
}
