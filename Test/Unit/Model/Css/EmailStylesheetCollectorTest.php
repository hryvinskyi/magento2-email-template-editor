<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Css;

use Hryvinskyi\EmailTemplateEditor\Model\Css\EmailStylesheetCollector;
use PHPUnit\Framework\TestCase;

/**
 * One stylesheet per email, gathered from the message and every template it includes.
 */
class EmailStylesheetCollectorTest extends TestCase
{
    /**
     * Collector under test
     *
     * @var EmailStylesheetCollector
     */
    private EmailStylesheetCollector $collector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->collector = new EmailStylesheetCollector();
    }

    /**
     * An included template's CSS reaches the email, not the include itself
     *
     * @return void
     */
    public function testAnIncludedTemplateHandsItsSheetToTheEmail(): void
    {
        $email = new \stdClass();
        $footer = new \stdClass();
        $this->collector->assign($footer, '', '.border-t-3{}', '');

        $this->collector->begin();
        $this->collector->begin();
        self::assertNull($this->collector->end($footer), 'The footer is not where the CSS is inlined.');
        $sheet = $this->collector->end($email);

        self::assertSame(['theme' => '', 'utility' => '.border-t-3{}', 'custom' => ''], $sheet);
    }

    /**
     * Within each tier the message comes after what it includes, so it wins a tie
     *
     * @return void
     */
    public function testTheMessageOutranksWhatItIncludesWithinEachTier(): void
    {
        $email = new \stdClass();
        $header = new \stdClass();
        $this->collector->assign($header, 'T-header', 'U-header', 'C-header');
        $this->collector->assign($email, 'T-email', 'U-email', 'C-email');

        $this->collector->begin();
        $this->collector->begin();
        $this->collector->end($header);

        self::assertSame(
            ['theme' => "T-header\nT-email", 'utility' => "U-header\nU-email", 'custom' => "C-header\nC-email"],
            $this->collector->end($email)
        );
    }

    /**
     * A theme the header, the footer and the message share is in the email once
     *
     * @return void
     */
    public function testASharedThemeIsKeptOnce(): void
    {
        $email = new \stdClass();
        $header = new \stdClass();
        $footer = new \stdClass();

        foreach ([$email, $header, $footer] as $template) {
            $this->collector->assign($template, 'THEME', '', '');
        }

        $this->collector->begin();
        $this->collector->begin();
        $this->collector->end($header);
        $this->collector->begin();
        $this->collector->end($footer);

        self::assertSame('THEME', $this->collector->end($email)['theme'] ?? null);
    }

    /**
     * A template with no sheet of its own still gets a scope, and an email with no CSS gets empty tiers
     *
     * @return void
     */
    public function testAnEmailWithoutEditorCssGetsEmptyTiers(): void
    {
        $this->collector->begin();

        self::assertSame(['theme' => '', 'utility' => '', 'custom' => ''], $this->collector->end(new \stdClass()));
    }

    /**
     * Rendering a template again collects its sheet again
     *
     * @return void
     */
    public function testRenderingTheSameTemplateTwiceCollectsItsSheetBothTimes(): void
    {
        $email = new \stdClass();
        $this->collector->assign($email, '', '.p-4{}', '');

        $this->collector->begin();
        $this->collector->end($email);
        $this->collector->begin();

        self::assertSame('.p-4{}', $this->collector->end($email)['utility'] ?? null);
    }

    /**
     * A sheet belongs to the object it was assigned to
     *
     * @return void
     */
    public function testASheetNeverReachesAnotherTemplate(): void
    {
        $this->collector->assign(new \stdClass(), '', '.brand{}', '');

        $this->collector->begin();

        self::assertSame('', $this->collector->end(new \stdClass())['utility'] ?? null);
    }

    /**
     * Closing a scope that was never opened is a programming error, not an empty sheet
     *
     * @return void
     */
    public function testClosingWithNoOpenScopeIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->collector->end(new \stdClass());
    }

    /**
     * Resetting drops a scope a failed render left open, so the next email is not nested inside it
     *
     * @return void
     */
    public function testResetStateDropsOpenScopesAndSheets(): void
    {
        $template = new \stdClass();
        $this->collector->assign($template, '', '.x{}', '');
        $this->collector->begin();

        $this->collector->_resetState();
        $this->collector->begin();

        self::assertSame(['theme' => '', 'utility' => '', 'custom' => ''], $this->collector->end($template));
    }
}
