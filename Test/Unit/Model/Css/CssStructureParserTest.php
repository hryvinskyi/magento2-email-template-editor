<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Css;

use Hryvinskyi\EmailTemplateEditor\Model\Css\CssStructureParser;
use Hryvinskyi\EmailTemplateEditor\Model\Css\CssSyntaxScanner;
use PHPUnit\Framework\TestCase;

class CssStructureParserTest extends TestCase
{
    private CssStructureParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CssStructureParser(new CssSyntaxScanner());
    }

    public function testRuleListIsSplitIntoPreludeAndBodyPairs(): void
    {
        $nodes = $this->parser->splitRuleList('.a { color: red; } .b { color: blue; }');

        self::assertCount(2, $nodes);
        self::assertSame('.a ', $nodes[0]['prelude']);
        self::assertSame(' color: red; ', $nodes[0]['body']);
        self::assertSame(' .b ', $nodes[1]['prelude']);
        self::assertSame(' color: blue; ', $nodes[1]['body']);
    }

    /**
     * Every caller rebuilds the stylesheet from these pieces, so a split that loses a byte
     * loses it from the email too.
     */
    public function testSplittingARuleListIsLossless(): void
    {
        $css = "/* lead */\n@import url('a.css');\n.a { content: '}'; }\n@media x { .b { color: red } }\ntrailing";

        $rebuilt = '';
        foreach ($this->parser->splitRuleList($css) as $node) {
            $rebuilt .= $node['prelude'];
            if ($node['body'] !== null) {
                $rebuilt .= '{' . $node['body'] . '}';
            }
        }

        self::assertSame($css, $rebuilt);
    }

    public function testNestedBlocksStayInsideTheirParentBody(): void
    {
        $nodes = $this->parser->splitRuleList('@media screen { .a { color: red; } }');

        self::assertCount(1, $nodes);
        self::assertSame('@media screen ', $nodes[0]['prelude']);
        self::assertSame(' .a { color: red; } ', $nodes[0]['body']);
    }

    public function testUnbalancedInputBecomesATrailingNodeRatherThanAGuess(): void
    {
        $nodes = $this->parser->splitRuleList('.a { color: red;');

        self::assertCount(1, $nodes);
        self::assertSame('.a { color: red;', $nodes[0]['prelude']);
        self::assertNull($nodes[0]['body']);
    }

    public function testEmptyInputProducesNoNodes(): void
    {
        self::assertSame([], $this->parser->splitRuleList(''));
    }

    public function testDeclarationsAreSplitOnTheirSeparatingSemicolons(): void
    {
        self::assertSame(
            [' color: red', ' background: blue', ' '],
            $this->parser->splitDeclarations(' color: red; background: blue; ')
        );
    }

    /**
     * A `data:` URI carries its own semicolon. Splitting on it truncates the declaration and
     * leaves the rest of the value behind as garbage the browser drops the whole block over.
     */
    public function testSemicolonInsideParenthesesDoesNotSplitADeclaration(): void
    {
        self::assertSame(
            [' background: url(data:image/svg+xml;base64,AAA)', ' color: red', ''],
            $this->parser->splitDeclarations(' background: url(data:image/svg+xml;base64,AAA); color: red;')
        );
    }

    public function testSemicolonInsideAStringDoesNotSplitADeclaration(): void
    {
        self::assertSame(
            [' content: "a;b"', ' color: red', ''],
            $this->parser->splitDeclarations(' content: "a;b"; color: red;')
        );
    }

    public function testSemicolonInsideANestedRuleDoesNotSplitTheOuterBlock(): void
    {
        self::assertSame(
            [' color: red', ' &:hover { color: blue; } ', ''],
            $this->parser->splitDeclarations(' color: red; &:hover { color: blue; } ;')
        );
    }

    public function testSplittingDeclarationsIsLossless(): void
    {
        $body = ' background: url(data:image/svg+xml;base64,AAA); content: "a;b"; color: red ';

        self::assertSame($body, implode(';', $this->parser->splitDeclarations($body)));
    }

    public function testStatementAtRulesAreSeparatedFromTheSelectorThatFollowsThem(): void
    {
        $split = $this->parser->splitPrelude("@import url('a.css');\n.a ");

        self::assertSame("@import url('a.css');", $split['statements']);
        self::assertSame("\n.a ", $split['selector']);
    }

    public function testSemicolonInsideAnImportUrlDoesNotEndTheStatement(): void
    {
        $split = $this->parser->splitPrelude('@import url("a.css?x=1;y=2");.a ');

        self::assertSame('@import url("a.css?x=1;y=2");', $split['statements']);
        self::assertSame('.a ', $split['selector']);
    }

    public function testPlainSelectorHasNoStatementPart(): void
    {
        $split = $this->parser->splitPrelude(' .a, .b ');

        self::assertSame('', $split['statements']);
        self::assertSame(' .a, .b ', $split['selector']);
    }

    public function testAtRuleNameIsLowerCasedAndStrippedOfItsSigil(): void
    {
        self::assertSame('media', $this->parser->resolveAtRuleName("\n@MEDIA (max-width: 600px) "));
    }

    public function testPlainSelectorHasNoAtRuleName(): void
    {
        self::assertNull($this->parser->resolveAtRuleName('.a, .b > td:first-child '));
    }

    /**
     * A selector following an `@import` must not be read as an `@import` block, or the block
     * it opens is treated as a descriptor list and skipped.
     */
    public function testSelectorFollowingAStatementAtRuleIsNotReadAsThatAtRule(): void
    {
        self::assertNull($this->parser->resolveAtRuleName("@import url('a.css');\n.a "));
    }

    public function testCommentedOutAtRuleDoesNotNameTheBlock(): void
    {
        self::assertNull($this->parser->resolveAtRuleName('/* @media print */ .a '));
    }
}
