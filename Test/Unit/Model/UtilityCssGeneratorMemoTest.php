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
use Hryvinskyi\EmailTemplateEditor\Model\UtilityCssGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The generator's memo.
 *
 * Rendering one email derives the same theme's utility CSS several times, and each derivation is a
 * dozen regular-expression sweeps over the whole theme source. The result depends on nothing but
 * the input string, so the repeats are answered from a per-request array.
 *
 * "It did not recompute" cannot be shown by counting collaborators: this class has none, and its
 * helpers are private. The memo itself is the instrument instead — seeding it with a value the
 * generator could never produce proves the cached branch was taken.
 */
class UtilityCssGeneratorMemoTest extends TestCase
{
    private const THEME = "@theme {\n  --color-primary: #131CCF;\n  --spacing-4: 16px;\n}";
    private const OTHER_THEME = "@theme {\n  --color-primary: #FF0000;\n}";

    private UtilityCssGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new UtilityCssGenerator(new CssStructureParser(new CssSyntaxScanner()), new CssSyntaxScanner());
    }

    public function testASeededEntryIsReturnedInsteadOfBeingRecomputed(): void
    {
        $sentinel = '/* served from the memo, not derived */';
        $this->seedMemo(md5(self::THEME), $sentinel);

        self::assertSame(
            $sentinel,
            $this->generator->generate(self::THEME),
            'A hit on the memo must short-circuit the whole derivation.'
        );
    }

    public function testTheMemoIsKeyedOnTheUntrimmedArgumentAsGiven(): void
    {
        $padded = "\n  " . self::THEME . "  \n";
        $sentinel = '/* keyed on the raw argument */';
        $this->seedMemo(md5($padded), $sentinel);

        self::assertSame($sentinel, $this->generator->generate($padded));
    }

    public function testRepeatingOneThemeLeavesASingleEntry(): void
    {
        $this->generator->generate(self::THEME);
        $this->generator->generate(self::THEME);
        $this->generator->generate(self::THEME);

        self::assertCount(1, $this->readMemo());
    }

    public function testTwoDistinctThemesGetTwoEntries(): void
    {
        $this->generator->generate(self::THEME);
        $this->generator->generate(self::OTHER_THEME);

        self::assertSame(
            [md5(self::THEME), md5(self::OTHER_THEME)],
            array_keys($this->readMemo())
        );
    }

    public function testARepeatedCallReturnsExactlyWhatTheFirstOneDid(): void
    {
        $first = $this->generator->generate(self::THEME);
        $second = $this->generator->generate(self::THEME);

        self::assertSame($first, $second);
        self::assertSame(
            $first,
            (new UtilityCssGenerator(new CssStructureParser(new CssSyntaxScanner()), new CssSyntaxScanner()))->generate(self::THEME),
            'The memo may only change the cost of a call, never its output.'
        );
    }

    public function testAnEmptyResultIsCachedToo(): void
    {
        self::assertSame('', $this->generator->generate(''));
        self::assertSame(
            [md5('') => ''],
            $this->readMemo(),
            'An empty derivation is still an answer; recomputing it would be wasted work.'
        );
    }

    public function testResetStateEmptiesTheMemo(): void
    {
        $this->generator->generate(self::THEME);
        $this->generator->generate(self::OTHER_THEME);
        self::assertCount(2, $this->readMemo());

        $this->generator->_resetState();

        self::assertSame([], $this->readMemo(), 'A long-lived process must not accumulate themes.');
    }

    public function testGenerationResumesAfterAReset(): void
    {
        $expected = $this->generator->generate(self::THEME);
        $this->generator->_resetState();

        self::assertSame($expected, $this->generator->generate(self::THEME));
    }

    /**
     * Write one entry straight into the private memo
     *
     * @param string $key
     * @param string $css
     * @return void
     * @throws \ReflectionException
     */
    private function seedMemo(string $key, string $css): void
    {
        (new \ReflectionProperty(UtilityCssGenerator::class, 'generatedCss'))
            ->setValue($this->generator, [$key => $css]);
    }

    /**
     * Read the private memo
     *
     * @return array<string, string>
     * @throws \ReflectionException
     */
    private function readMemo(): array
    {
        /** @var array<string, string> $value */
        $value = (new \ReflectionProperty(UtilityCssGenerator::class, 'generatedCss'))
            ->getValue($this->generator);

        return $value;
    }
}
