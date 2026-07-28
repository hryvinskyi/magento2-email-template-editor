<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\DirectiveReferenceParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DirectiveReferenceParserTest extends TestCase
{
    private DirectiveReferenceParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DirectiveReferenceParser();
    }

    /**
     * The browser sends canonical strings back and expects to get the same entry it was shown, so a
     * reference that does not survive a trip through its own canonical form is a lookup miss the
     * admin reads as "not documented".
     *
     * @param string $kind Published directive kind
     * @param string $expression Raw expression as a directive might carry it
     * @return void
     * @dataProvider publishedKindProvider
     */
    public function testEveryPublishedKindRoundTripsThroughItsCanonicalString(
        string $kind,
        string $expression
    ): void {
        $reference = $this->parser->create($kind, $expression);
        $reparsed = $this->parser->parse($reference->toCanonicalString());

        self::assertTrue($reference->equals($reparsed), $reference->toCanonicalString());
        self::assertSame($reference->toCanonicalString(), $reparsed->toCanonicalString());
    }

    /**
     * A kind added to the published set without a round-trip fixture would go untested, so the sweep
     * asserts it is exhaustive rather than merely broad.
     *
     * @return void
     */
    public function testTheRoundTripSweepCoversEveryPublishedKind(): void
    {
        $covered = array_values(array_unique(array_column(self::publishedKindProvider(), 0)));
        $published = array_keys(DirectiveReferenceParser::KINDS);
        sort($covered);
        sort($published);

        self::assertSame($published, $covered);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function publishedKindProvider(): array
    {
        return [
            'var' => ['var', ' store.getFormattedAddress() '],
            'config' => ['config', '"general/store_information/name"'],
            'customVar' => ['customVar', 'my_code'],
            'trans' => ['trans', '"Thank you   for your order"'],
            'block' => ['block', 'Magento\Cms\Block\Block'],
            'layout' => ['layout', 'sales_email_order_items'],
            'media' => ['media', 'wysiwyg/logo.png'],
            'store' => ['store', 'customer/account/index'],
            'css' => ['css', 'css/email-inline.css'],
            'template' => ['template', 'design/email/header_template'],
            'view' => ['view', 'Magento_Email::logo_email.png'],
            'protocol' => ['protocol', 'http'],
            'inlinecss' => ['inlinecss', 'css/email-inline.css'],
            'depend' => ['depend', 'order.increment_id'],
            'if' => ['if', 'customer.name'],
            'for' => ['for', 'item in order.items'],
        ];
    }

    /**
     * @param string $canonical Canonical string whose expression carries colons
     * @param string $expectedKind Kind the split must yield
     * @param string $expectedExpression Expression the split must leave intact
     * @return void
     * @dataProvider colonBearingExpressionProvider
     */
    public function testTheSplitTakesTheFirstColonOnly(
        string $canonical,
        string $expectedKind,
        string $expectedExpression
    ): void {
        $reference = $this->parser->parse($canonical);

        self::assertSame($expectedKind, $reference->getKind());
        self::assertSame($expectedExpression, $reference->getExpression());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function colonBearingExpressionProvider(): array
    {
        return [
            'config path' => [
                'config:general/store_information/name',
                'config',
                'general/store_information/name',
            ],
            'block class with a static-call separator' => [
                'block:Magento\Cms\Block\Block::toHtml',
                'block',
                'Magento\Cms\Block\Block::toHtml',
            ],
            'view file with a module separator' => [
                'view:Magento_Email::logo_email.png',
                'view',
                'Magento_Email::logo_email.png',
            ],
            'message containing a colon' => [
                'trans:Order status: shipped',
                'trans',
                'Order status: shipped',
            ],
        ];
    }

    /**
     * @param string $kind Published directive kind
     * @param string $expression Raw expression
     * @param string $expected Expression after the kind's normalisation
     * @return void
     * @dataProvider normalisationProvider
     */
    public function testTheExpressionIsNormalisedForItsKind(
        string $kind,
        string $expression,
        string $expected
    ): void {
        self::assertSame($expected, $this->parser->create($kind, $expression)->getExpression());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function normalisationProvider(): array
    {
        return [
            'a variable directive may be written with inner spaces' => [
                'var',
                '  store . getFormattedAddress()  ',
                'store.getFormattedAddress()',
            ],
            'a variable path keeps no whitespace at all' => [
                'var',
                "order.increment_id\t",
                'order.increment_id',
            ],
            'a config path is only trimmed and unquoted' => [
                'config',
                ' "general/store_information/name" ',
                'general/store_information/name',
            ],
            'a message collapses runs of whitespace to one space' => [
                'trans',
                '"Thank you   for  your order"',
                'Thank you for your order',
            ],
            'a message collapses tabs as well as spaces' => [
                'trans',
                "\"Thank you \t for your order\"",
                'Thank you for your order',
            ],
            'a block class is trimmed' => [
                'block',
                '  Magento\Cms\Block\Block  ',
                'Magento\Cms\Block\Block',
            ],
            'a kind with no identity of its own keeps no expression' => [
                'depend',
                'order.increment_id',
                '',
            ],
            'a loop keeps no expression either' => [
                'for',
                'item in order.items',
                '',
            ],
        ];
    }

    /**
     * This module's variable chooser emits `{{customVar code=my_code}}` while Magento's own config
     * variable source emits `{{config path="..."}}`, and both feed the same chooser - so the quoted
     * and unquoted spellings have to land on one key or a chooser row and the identical directive in
     * the document would describe two different things.
     *
     * @param string $kind Published directive kind
     * @param string $expression Raw expression, quoted one way or another
     * @param string $expected Expression after unquoting
     * @return void
     * @dataProvider quotingProvider
     */
    public function testQuotedAndUnquotedParametersProduceTheSameReference(
        string $kind,
        string $expression,
        string $expected
    ): void {
        self::assertSame($expected, $this->parser->create($kind, $expression)->getExpression());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function quotingProvider(): array
    {
        return [
            'bare code' => ['customVar', 'my_code', 'my_code'],
            'double quoted code' => ['customVar', '"my_code"', 'my_code'],
            'single quoted code' => ['customVar', "'my_code'", 'my_code'],
            'bare config path' => ['config', 'web/unsecure/base_url', 'web/unsecure/base_url'],
            'double quoted config path' => ['config', '"web/unsecure/base_url"', 'web/unsecure/base_url'],
            'unmatched opening quote belongs to the value' => ['customVar', '"my_code', '"my_code'],
            'unmatched closing quote belongs to the value' => ['customVar', "my_code'", "my_code'"],
            'mismatched pair belongs to the value' => ['customVar', '"my_code\'', '"my_code\''],
            'one lone quote is not a pair' => ['customVar', '"', '"'],
        ];
    }

    public function testAllThreeSpellingsOfACustomVariableCodeAreOneReference(): void
    {
        $bare = $this->parser->create('customVar', 'my_code');
        $doubleQuoted = $this->parser->create('customVar', '"my_code"');
        $singleQuoted = $this->parser->create('customVar', "'my_code'");

        self::assertTrue($bare->equals($doubleQuoted));
        self::assertTrue($bare->equals($singleQuoted));
        self::assertSame('customVar:my_code', $doubleQuoted->toCanonicalString());
    }

    /**
     * The email filter reaches a directive through a reflected method call and PHP method names are
     * case-insensitive, so a directive written in another case renders identically and must describe
     * the same thing.
     *
     * @param string $canonical Canonical string with the kind spelled in some other case
     * @param string $expectedKind Canonical spelling the parser must settle on
     * @return void
     * @dataProvider kindCasingProvider
     */
    public function testAKindIsMatchedWithoutRegardToCase(string $canonical, string $expectedKind): void
    {
        self::assertSame($expectedKind, $this->parser->parse($canonical)->getKind());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function kindCasingProvider(): array
    {
        return [
            'upper case var' => ['VAR:order.increment_id', 'var'],
            'lower case custom variable' => ['customvar:my_code', 'customVar'],
            'mixed case custom variable' => ['CustomVar:my_code', 'customVar'],
        ];
    }

    /**
     * @param string $kind Kind to build with
     * @param string $expression Expression to build with
     * @param string $expectedMessageFragment Part of the refusal that names the rule that was broken
     * @return void
     * @dataProvider rejectionProvider
     */
    public function testAMalformedReferenceIsRefused(
        string $kind,
        string $expression,
        string $expectedMessageFragment
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessageFragment);

        $this->parser->create($kind, $expression);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rejectionProvider(): array
    {
        return [
            'empty kind' => ['', 'my_code', 'must not be empty'],
            'kind with a non-letter' => ['custom_var', 'my_code', 'one to sixteen letters'],
            'kind longer than the grammar allows' => [
                'abcdefghijklmnopq',
                'my_code',
                'one to sixteen letters',
            ],
            'kind outside the published set' => ['banana', 'my_code', 'not a published directive kind'],
            'expression with an opening brace' => ['var', 'order.{increment_id', 'braces, line breaks or NUL'],
            'expression with a closing brace' => ['var', 'order.increment_id}', 'braces, line breaks or NUL'],
            'expression with a carriage return' => ["var", "order.\rincrement_id", 'braces, line breaks or NUL'],
            'expression with a line feed' => ['var', "order.\nincrement_id", 'braces, line breaks or NUL'],
            'expression with a NUL byte' => ['var', "order.\0increment_id", 'braces, line breaks or NUL'],
        ];
    }

    public function testACanonicalStringWithoutASeparatorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain a colon');

        $this->parser->parse('varorder.increment_id');
    }

    public function testACanonicalStringWithAnEmptyKindIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->parser->parse(':order.increment_id');
    }

    /**
     * Long translated messages are the common case, not the edge: refusing them would make every
     * `{{trans}}` carrying one silently unclickable, with no explanation anywhere.
     *
     * @return void
     */
    public function testAnOverlongExpressionIsTruncatedRatherThanRefused(): void
    {
        // Two-byte characters, so the byte at the limit falls in the middle of one and a cut that
        // counted bytes alone would leave a half-encoded character behind.
        $expression = str_repeat("\u{00e9}", 200);
        self::assertFalse(
            mb_check_encoding(substr($expression, 0, 255), 'UTF-8'),
            'The fixture must straddle a character boundary at the limit, or it proves nothing.'
        );

        $reference = $this->parser->create('trans', $expression);

        self::assertTrue($reference->isOverlong());
        self::assertLessThanOrEqual(255, strlen($reference->getExpression()));
        self::assertTrue(mb_check_encoding($reference->getExpression(), 'UTF-8'));
        self::assertStringStartsWith($reference->getExpression(), $expression);
    }

    /**
     * A truncated key still has to survive the trip to the browser and back, so the cut may not leave
     * trailing whitespace behind: re-parsing trims, and the two references would stop being equal.
     *
     * @return void
     */
    public function testATruncatedExpressionStillRoundTrips(): void
    {
        $expression = str_repeat('ab ', 100);

        $reference = $this->parser->create('trans', $expression);

        self::assertTrue($reference->isOverlong());
        self::assertSame(rtrim($reference->getExpression()), $reference->getExpression());
        self::assertTrue($reference->equals($this->parser->parse($reference->toCanonicalString())));
    }

    /**
     * The flag describes the input the reference was built from. A canonical string is already within
     * the limit, so the reference parsed back from one reports false while still being the same
     * reference.
     *
     * @return void
     */
    public function testAReparsedTruncatedReferenceIsNoLongerFlagged(): void
    {
        $reference = $this->parser->create('trans', str_repeat('a', 300));

        self::assertFalse($this->parser->parse($reference->toCanonicalString())->isOverlong());
    }

    public function testAnExpressionAtTheLimitIsNotTruncated(): void
    {
        $expression = str_repeat('a', 255);

        $reference = $this->parser->create('trans', $expression);

        self::assertFalse($reference->isOverlong());
        self::assertSame($expression, $reference->getExpression());
    }

    public function testAReferenceBuiltFromDirectivePartsEqualsTheOneParsedFromItsCanonicalString(): void
    {
        $fromParts = $this->parser->create('config', '"general/store_information/name"');
        $fromCanonical = $this->parser->parse('config:general/store_information/name');

        self::assertTrue($fromParts->equals($fromCanonical));
    }

    public function testTheSameExpressionUnderTwoKindsIsNotTheSameReference(): void
    {
        self::assertFalse(
            $this->parser->create('config', 'my_code')->equals($this->parser->create('customVar', 'my_code'))
        );
    }
}
