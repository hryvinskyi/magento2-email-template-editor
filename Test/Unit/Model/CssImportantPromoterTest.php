<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model;

use Hryvinskyi\EmailTemplateEditor\Model\Css\CssStructureParser;
use Hryvinskyi\EmailTemplateEditor\Model\Css\CssSyntaxScanner;
use Hryvinskyi\EmailTemplateEditor\Model\CssImportantPromoter;
use PHPUnit\Framework\TestCase;

class CssImportantPromoterTest extends TestCase
{
    private CssImportantPromoter $promoter;

    protected function setUp(): void
    {
        $this->promoter = new CssImportantPromoter(new CssStructureParser(new CssSyntaxScanner()));
    }

    public function testEveryDeclarationOfAStyleRuleIsPromoted(): void
    {
        $out = $this->promoter->promote('.x { color: red; background: blue; }');

        self::assertStringContainsString('color: red !important;', $out);
        self::assertStringContainsString('background: blue !important', $out);
    }

    public function testLastDeclarationWithoutTrailingSemicolonIsPromoted(): void
    {
        $out = $this->promoter->promote('.x { color: red }');

        self::assertStringContainsString('color: red !important', $out);
    }

    public function testAlreadyImportantDeclarationIsNotDuplicated(): void
    {
        $out = $this->promoter->promote('.x { color: red !important; }');

        self::assertSame(1, substr_count($out, '!important'));
    }

    public function testSelectorsAndStructureArePreserved(): void
    {
        $out = $this->promoter->promote('.a, .b > td:first-child { color: red; }');

        self::assertStringContainsString('.a, .b > td:first-child {', $out);
    }

    public function testCustomPropertiesAreLeftAlone(): void
    {
        $out = $this->promoter->promote('.x { --tw-invert: invert(100%); filter: invert(100%); }');

        self::assertStringContainsString('--tw-invert: invert(100%);', $out);
        self::assertStringNotContainsString('--tw-invert: invert(100%) !important', $out);
        self::assertStringContainsString('filter: invert(100%) !important', $out);
    }

    public function testMediaQueryContentsArePromoted(): void
    {
        $out = $this->promoter->promote('@media (max-width: 600px) { .x { color: red; } }');

        self::assertStringContainsString('@media (max-width: 600px) {', $out);
        self::assertStringContainsString('color: red !important', $out);
    }

    public function testNestedSupportsInsideMediaIsPromoted(): void
    {
        $css = '@media screen { @supports (display: grid) { .x { color: red; } } }';
        $out = $this->promoter->promote($css);

        self::assertStringContainsString('color: red !important', $out);
    }

    public function testFontFaceDescriptorsAreNotPromoted(): void
    {
        $css = '@font-face { font-family: "Accord"; src: url(a.woff2) format("woff2"); }';
        $out = $this->promoter->promote($css);

        self::assertStringNotContainsString('!important', $out);
    }

    public function testKeyframeStepsAreNotPromoted(): void
    {
        $css = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        $out = $this->promoter->promote($css);

        self::assertStringNotContainsString('!important', $out);
    }

    public function testPrefixedKeyframeStepsAreNotPromoted(): void
    {
        $css = '@-webkit-keyframes spin { to { transform: rotate(360deg); } }';
        $out = $this->promoter->promote($css);

        self::assertStringNotContainsString('!important', $out);
    }

    public function testSemicolonInsideAValueDoesNotSplitTheDeclaration(): void
    {
        $css = '.x { background: url(data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=); color: red; }';
        $out = $this->promoter->promote($css);

        self::assertStringContainsString(
            'background: url(data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=) !important;',
            $out
        );
        self::assertStringContainsString('color: red !important', $out);
    }

    public function testColonInsideAValueDoesNotConfusePropertyDetection(): void
    {
        $out = $this->promoter->promote('.x { background-image: url(https://example.com/a.png); }');

        self::assertStringContainsString('background-image: url(https://example.com/a.png) !important', $out);
    }

    public function testCommentsBetweenRulesArePreserved(): void
    {
        $out = $this->promoter->promote("/* header */\n.x { color: red; }");

        self::assertStringContainsString('/* header */', $out);
        self::assertStringContainsString('color: red !important', $out);
    }

    public function testEmptyAndWhitespaceOnlyInputIsReturnedUnchanged(): void
    {
        self::assertSame('', $this->promoter->promote(''));
        self::assertSame("  \n", $this->promoter->promote("  \n"));
    }

    public function testUnbalancedInputIsNotCorrupted(): void
    {
        $css = '.x { color: red;';

        self::assertSame($css, $this->promoter->promote($css));
    }

    public function testAtImportStatementIsPreserved(): void
    {
        $css = "@import url('https://fonts.googleapis.com/css2?family=Inter');\n.x { color: red; }";
        $out = $this->promoter->promote($css);

        self::assertStringContainsString("@import url('https://fonts.googleapis.com/css2?family=Inter');", $out);
        self::assertStringContainsString('color: red !important', $out);
    }
}
