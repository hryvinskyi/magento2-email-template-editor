<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Plugin;

use Hryvinskyi\EmailTemplateEditor\Api\ConfigInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssInlinerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Api\EditorContextFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\UtilityCssGeneratorInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Color\ColorMixer;
use Hryvinskyi\EmailTemplateEditor\Model\Color\ColorParser;
use Hryvinskyi\EmailTemplateEditor\Model\Css\CssStructureParser;
use Hryvinskyi\EmailTemplateEditor\Model\Css\CssSyntaxScanner;
use Hryvinskyi\EmailTemplateEditor\Model\Css\EmailStylesheetCollector;
use Hryvinskyi\EmailTemplateEditor\Model\CssColorConverter;
use Hryvinskyi\EmailTemplateEditor\Model\CssImportantPromoter;
use Hryvinskyi\EmailTemplateEditor\Model\CssInliner;
use Hryvinskyi\EmailTemplateEditor\Model\CssLayerFlattener;
use Hryvinskyi\EmailTemplateEditor\Model\CssVariableResolver;
use Hryvinskyi\EmailTemplateEditor\Plugin\EmailTemplatePlugin;
use Magento\Email\Model\AbstractTemplate;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Override resolution: the priority ladder, and the single query that now walks it.
 *
 * Resolving one included template used to cost up to eight collection loads — two schedule windows
 * × two identifiers × two store scopes, each its own round trip, on the header and the footer of
 * every render. All eight are now fetched together and ranked in PHP, which is a change of cost
 * only: the winner must be the same row it has always been.
 *
 * The ranking has three dimensions and they are NOT independent. The schedule window is outermost,
 * the identifier next, the store scope last, so a bare default-scope scheduled override outranks a
 * themed store-specific immediate one. A suite that varied one dimension at a time would pass
 * against an identifier-outermost ordering, so the ladder is exercised whole.
 */
class EmailTemplatePluginTest extends TestCase
{
    private const TEMPLATE_ID = 'sales_email_order_template';
    private const THEME_CODE = 'Magento/luma';
    private const THEMED_ID = self::TEMPLATE_ID . '/' . self::THEME_CODE;
    private const STORE_ID = 5;

    private const NOW = '2026-07-28 12:00:00';
    private const WINDOW_OPEN_FROM = '2026-07-01 00:00:00';
    private const WINDOW_OPEN_TO = '2026-08-31 00:00:00';
    private const WINDOW_PAST_FROM = '2026-01-01 00:00:00';
    private const WINDOW_PAST_TO = '2026-02-01 00:00:00';
    private const WINDOW_FUTURE_FROM = '2026-09-01 00:00:00';
    private const WINDOW_FUTURE_TO = '2026-10-01 00:00:00';

    /**
     * The eight rungs in descending priority: [identifier, store scope, carries a schedule]
     */
    private const LADDER = [
        1 => [self::THEMED_ID, self::STORE_ID, true],
        2 => [self::THEMED_ID, 0, true],
        3 => [self::TEMPLATE_ID, self::STORE_ID, true],
        4 => [self::TEMPLATE_ID, 0, true],
        5 => [self::THEMED_ID, self::STORE_ID, false],
        6 => [self::THEMED_ID, 0, false],
        7 => [self::TEMPLATE_ID, self::STORE_ID, false],
        8 => [self::TEMPLATE_ID, 0, false],
    ];

    /**
     * Module configuration double
     *
     * @var ConfigInterface&MockObject
     */
    private MockObject $config;

    /**
     * Override repository double answering from $candidates
     *
     * @var TemplateOverrideRepositoryInterface&MockObject
     */
    private MockObject $overrideRepository;

    /**
     * Theme repository double
     *
     * @var ThemeRepositoryInterface&MockObject
     */
    private MockObject $themeRepository;

    /**
     * Store manager double reporting the current store
     *
     * @var StoreManagerInterface&MockObject
     */
    private MockObject $storeManager;

    /**
     * Logger double
     *
     * @var LoggerInterface&MockObject
     */
    private MockObject $logger;

    /**
     * Bypass flag double
     *
     * @var PluginBypassFlagInterface&MockObject
     */
    private MockObject $pluginBypassFlag;

    /**
     * Editor context flag double
     *
     * @var EditorContextFlagInterface&MockObject
     */
    private MockObject $editorContextFlag;

    /**
     * The real stylesheet collector the plugin scopes renders with
     *
     * @var EmailStylesheetCollector
     */
    private EmailStylesheetCollector $stylesheets;

    /**
     * Every call the inliner received: [html, custom, utility, theme]
     *
     * @var list<array{0: string, 1: string|null, 2: string|null, 3: string|null}>
     */
    private array $inlined = [];

    /**
     * Plugin under test
     *
     * @var EmailTemplatePlugin
     */
    private EmailTemplatePlugin $plugin;

    /**
     * @var array<string, TemplateOverrideInterface[]>
     */
    private array $candidates = [];

    /**
     * @var list<array{0: string[], 1: int[], 2: string[]}>
     */
    private array $lookups = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->overrideRepository = $this->createMock(TemplateOverrideRepositoryInterface::class);
        $this->themeRepository = $this->createMock(ThemeRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->pluginBypassFlag = $this->createMock(PluginBypassFlagInterface::class);
        $this->editorContextFlag = $this->createMock(EditorContextFlagInterface::class);
        $this->stylesheets = new EmailStylesheetCollector();

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->method('isEnabled')->willReturn(true);
        $this->pluginBypassFlag->method('isBypassed')->willReturn(false);
        $this->editorContextFlag->method('isActive')->willReturn(false);

        // No theme row, so the combined CSS is whatever the override itself carries.
        $this->themeRepository->method('getDefaultTheme')->willReturn(null);

        // Every lookup is recorded so the tests can count them without an inline mock
        // expectation, whose failure the plugin's own catch block would swallow.
        $this->overrideRepository->method('getOverridesForIdentifiers')
            ->willReturnCallback(
                /**
                 * @param string[] $identifiers
                 * @param int[] $storeIds
                 * @param string[] $statuses
                 * @return array<string, TemplateOverrideInterface[]>
                 */
                function (array $identifiers, array $storeIds, array $statuses): array {
                    /** @var array{0: string[], 1: int[], 2: string[]} $lookup */
                    $lookup = [$identifiers, $storeIds, $statuses];
                    $this->lookups[] = $lookup;

                    return $this->candidates;
                }
            );

        $this->plugin = $this->pluginWith($this->storeManager, $this->recordingInliner());
    }

    // -------------------------------------------------------------------------------------
    //  The priority ladder
    // -------------------------------------------------------------------------------------

    /**
     * Peel the ladder from the top: with rungs N..8 present, rung N must win, for every N.
     *
     * This pins all eight positions and every cross-dimension relationship between them at once.
     */
    public function testEachRungWinsOnceEveryRungAboveItIsGone(): void
    {
        foreach (array_keys(self::LADDER) as $top) {
            $this->candidates = $this->ladderRows(range($top, 8));
            $this->plugin->_resetState();

            self::assertSame(
                'rung-' . $top,
                $this->applyAndCaptureContent(self::THEME_CODE),
                'With rungs ' . $top . '..8 available, rung ' . $top . ' must be the winner.'
            );
        }
    }

    public function testDefaultScopeScheduledOverrideBeatsThemedStoreSpecificImmediateOne(): void
    {
        // Rung 4 against rung 5 - the pair that only an outermost schedule dimension gets right.
        $this->candidates = $this->ladderRows([4, 5]);

        self::assertSame('rung-4', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    public function testThemedIdentifierBeatsBareOneWithinTheSameWindowAndStore(): void
    {
        $this->candidates = $this->ladderRows([5, 7]);

        self::assertSame('rung-5', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    public function testSpecificStoreBeatsDefaultScopeWithinTheSameWindowAndIdentifier(): void
    {
        $this->candidates = $this->ladderRows([7, 8]);

        self::assertSame('rung-7', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    public function testThemedRungsAreSkippedWhenNoThemeCodeResolves(): void
    {
        $this->candidates = $this->ladderRows([1, 8]);

        self::assertSame(
            'rung-8',
            $this->applyAndCaptureContent(null),
            'Without a theme code the themed identifier is never searched.'
        );
        self::assertSame([self::TEMPLATE_ID], $this->lookups[0][0]);
    }

    public function testAnIdentifierThatAlreadyEncodesAThemeIsNotThemedTwice(): void
    {
        $this->candidates = [];

        $this->plugin->afterLoadDefault(
            $this->template(self::THEME_CODE),
            $this->template(self::THEME_CODE),
            self::THEMED_ID
        );

        self::assertSame([self::THEMED_ID], $this->lookups[0][0]);
    }

    public function testOnlyTheDefaultScopeIsSearchedFromStoreZero(): void
    {
        $plugin = $this->pluginForStore(0);
        $this->candidates = [];

        $plugin->afterLoadDefault($this->template(null), $this->template(null), self::TEMPLATE_ID);

        self::assertSame([0], $this->lookups[0][1]);
    }

    // -------------------------------------------------------------------------------------
    //  Window semantics
    // -------------------------------------------------------------------------------------

    public function testAnUndatedRowNeverCountsAsScheduled(): void
    {
        // The undated row sits at the top rung by identifier and store; the dated one sits at the
        // bottom of the scheduled block. Reading a null bound as "always open" would hand the win
        // to the undated row, because the scheduled block outranks the immediate one wholesale.
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'undated', null, null),
            $this->override(2, self::TEMPLATE_ID, 0, 'scheduled', self::WINDOW_OPEN_FROM, self::WINDOW_OPEN_TO),
        ]);

        self::assertSame('scheduled', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
     */
    public static function windowShapeProvider(): array
    {
        return [
            'both bounds, now inside the window' => [self::WINDOW_OPEN_FROM, self::WINDOW_OPEN_TO, true],
            'both bounds, window has not started' => [self::WINDOW_FUTURE_FROM, self::WINDOW_FUTURE_TO, false],
            'both bounds, window is over' => [self::WINDOW_PAST_FROM, self::WINDOW_PAST_TO, false],
            'start only, already reached' => [self::WINDOW_OPEN_FROM, null, true],
            'start only, still ahead' => [self::WINDOW_FUTURE_FROM, null, false],
            'end only, still ahead' => [null, self::WINDOW_OPEN_TO, true],
            'end only, already passed' => [null, self::WINDOW_PAST_TO, false],
            'no window at all' => [null, null, true],
            'both bounds, starting exactly now' => [self::NOW, self::WINDOW_OPEN_TO, true],
            'both bounds, ending exactly now' => [self::WINDOW_OPEN_FROM, self::NOW, true],
            'start only, exactly now' => [self::NOW, null, true],
            'end only, exactly now' => [null, self::NOW, true],
        ];
    }

    /**
     * Every shape an admin can publish, and whether that override reaches the email
     *
     * A bound that is not set is an open end: the row applies from the bound it does carry, or
     * until it. Both bounds are inclusive. The same twelve shapes are pinned against the
     * repository's lookups, because it is exactly this rule existing in two implementations that
     * let a published override apply to nobody for as long as it did.
     *
     * @dataProvider windowShapeProvider
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @param bool $expected
     * @return void
     */
    public function testEveryWindowShapeIsAppliedForExactlyAsLongAsItSays(
        ?string $activeFrom,
        ?string $activeTo,
        bool $expected
    ): void {
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'applied', $activeFrom, $activeTo),
        ]);

        self::assertSame($expected ? 'applied' : null, $this->applyAndCaptureContent(null));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function halfOpenWindowProvider(): array
    {
        return [
            'only a start, already reached' => [self::WINDOW_OPEN_FROM, null],
            'only an end, still ahead' => [null, self::WINDOW_OPEN_TO],
        ];
    }

    /**
     * A window left open at one end is a window, and so outranks an override carrying none
     *
     * The half-open row sits at the worst rung and the undated one at the best, so only the window
     * dimension can hand it the win. This is what the publish dialog produces from a single date,
     * and it used to satisfy neither rung: published, listed in the admin as live, and reaching
     * nobody.
     *
     * @dataProvider halfOpenWindowProvider
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @return void
     */
    public function testAWindowOpenAtOneEndOutranksAnUndatedOverride(?string $activeFrom, ?string $activeTo): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'half-open', $activeFrom, $activeTo),
            $this->override(2, self::THEMED_ID, self::STORE_ID, 'undated', null, null),
        ]);

        self::assertSame('half-open', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    /**
     * @dataProvider halfOpenWindowProvider
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @return void
     */
    public function testAHalfOpenRowThatWasSwitchedOffLeavesTheRowBelowItApplying(
        ?string $activeFrom,
        ?string $activeTo
    ): void {
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'inactive', $activeFrom, $activeTo, false),
            $this->override(2, self::TEMPLATE_ID, 0, 'active', null, null),
        ]);

        self::assertSame(
            'active',
            $this->applyAndCaptureContent(self::THEME_CODE),
            'is_active is required on the window rung, so the switched-off row does not mask.'
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function closedHalfOpenWindowProvider(): array
    {
        return [
            'only a start, still ahead' => [self::WINDOW_FUTURE_FROM, null],
            'only an end, already passed' => [null, self::WINDOW_PAST_TO],
        ];
    }

    /**
     * @dataProvider closedHalfOpenWindowProvider
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @return void
     */
    public function testAHalfOpenWindowOutsideItsPeriodDoesNotBlockTheUndatedRow(
        ?string $activeFrom,
        ?string $activeTo
    ): void {
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'half-open', $activeFrom, $activeTo),
            $this->override(2, self::TEMPLATE_ID, 0, 'undated', null, null),
        ]);

        self::assertSame(
            'undated',
            $this->applyAndCaptureContent(self::THEME_CODE),
            'A window that is not open loses its rung outright rather than masking what stands behind it.'
        );
    }

    public function testAClosedScheduleWindowIsSkippedInFavourOfAnUndatedRow(): void
    {
        $this->candidates = $this->group([
            $this->override(
                1,
                self::THEMED_ID,
                self::STORE_ID,
                'expired',
                '2026-01-01 00:00:00',
                '2026-02-01 00:00:00'
            ),
            $this->override(2, self::TEMPLATE_ID, 0, 'undated', null, null),
        ]);

        self::assertSame('undated', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    public function testTheWindowIsMeasuredInTheStoreTimezoneNotTheServerClock(): void
    {
        // A window that closed one minute before the store's "now" but would still be open on a
        // clock running a couple of hours behind it.
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'expired', self::WINDOW_OPEN_FROM, '2026-07-28 11:59:00'),
        ]);

        self::assertNull($this->applyAndCaptureContent(null));
    }

    // -------------------------------------------------------------------------------------
    //  is_active
    // -------------------------------------------------------------------------------------

    /**
     * Switching a store-view override off falls through to the All Store Views one
     *
     * Switching an override off means it is not there, so the next one down applies. The
     * switched-off row must not win its rung and then decline to fill it, which is what used to
     * hand the admin stock templates instead of the override they were falling back to.
     *
     * @return void
     */
    public function testSwitchingAStoreViewOverrideOffLetsTheDefaultScopeOneApply(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'switched-off', null, null, false),
            $this->override(2, self::TEMPLATE_ID, 0, 'all-store-views', null, null),
        ]);

        self::assertSame('all-store-views', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    public function testSwitchingTheOnlyOverrideOffFallsThroughToTheStockTemplate(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'switched-off', null, null, false),
        ]);

        self::assertNull(
            $this->applyAndCaptureContent(self::THEME_CODE),
            'With nothing standing behind it, the email is the template Magento shipped.'
        );
    }

    /**
     * @dataProvider windowShapeProvider
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @return void
     */
    public function testNoWindowShapeSurvivesBeingSwitchedOff(?string $activeFrom, ?string $activeTo): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'switched-off', $activeFrom, $activeTo, false),
        ]);

        self::assertNull(
            $this->applyAndCaptureContent(null),
            'Being switched off settles it before the window is even considered.'
        );
    }

    public function testAnInactiveScheduledRowIsSkippedSoALowerRowStillApplies(): void
    {
        $this->candidates = $this->group([
            $this->override(
                1,
                self::THEMED_ID,
                self::STORE_ID,
                'inactive',
                self::WINDOW_OPEN_FROM,
                self::WINDOW_OPEN_TO,
                false
            ),
            $this->override(2, self::TEMPLATE_ID, 0, 'active', null, null),
        ]);

        self::assertSame('active', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    // -------------------------------------------------------------------------------------
    //  Ties
    // -------------------------------------------------------------------------------------

    public function testTiesOnOneRungResolveToTheLowestEntityId(): void
    {
        $this->candidates = $this->group([
            $this->override(9, self::TEMPLATE_ID, 0, 'nine', null, null),
            $this->override(4, self::TEMPLATE_ID, 0, 'four', null, null),
            $this->override(7, self::TEMPLATE_ID, 0, 'seven', null, null),
        ]);

        self::assertSame('four', $this->applyAndCaptureContent(null));
    }

    public function testTiesAreResolvedIndependentlyOfTheOrderRowsArriveIn(): void
    {
        $this->candidates = $this->group([
            $this->override(4, self::TEMPLATE_ID, 0, 'four', null, null),
            $this->override(9, self::TEMPLATE_ID, 0, 'nine', null, null),
        ]);

        self::assertSame(
            'four',
            $this->applyAndCaptureContent(null),
            'The batched lookup makes no ordering promise, so the tie-break cannot lean on one.'
        );
    }

    // -------------------------------------------------------------------------------------
    //  The single lookup, and its memo
    // -------------------------------------------------------------------------------------

    public function testResolutionCostsOneLookupWhereItUsedToCostUpToEight(): void
    {
        $this->candidates = $this->ladderRows(range(1, 8));

        $this->plugin->afterLoadDefault(
            $this->template(self::THEME_CODE),
            $this->template(self::THEME_CODE),
            self::TEMPLATE_ID
        );

        self::assertCount(1, $this->lookups);
        self::assertSame([self::THEMED_ID, self::TEMPLATE_ID], $this->lookups[0][0]);
        self::assertSame([self::STORE_ID, 0], $this->lookups[0][1]);
        self::assertSame([TemplateOverrideInterface::STATUS_PUBLISHED], $this->lookups[0][2]);
    }

    public function testTheSingleRowQueriesAreNoLongerUsedForResolution(): void
    {
        $this->overrideRepository->expects(self::never())->method('getActiveScheduledPublished');
        $this->overrideRepository->expects(self::never())->method('getImmediatePublished');
        $this->candidates = $this->ladderRows(range(1, 8));

        $this->plugin->afterLoadDefault(
            $this->template(self::THEME_CODE),
            $this->template(self::THEME_CODE),
            self::TEMPLATE_ID
        );
    }

    public function testTheSameTemplateResolvedTwiceIsLookedUpOnce(): void
    {
        $this->candidates = $this->ladderRows([8]);

        $this->applyAndCaptureContent(self::THEME_CODE);
        $this->applyAndCaptureContent(self::THEME_CODE);

        self::assertCount(1, $this->lookups, 'A header and a footer render must share one lookup.');
    }

    public function testATemplateWithoutAnyOverrideIsNotLookedUpAgain(): void
    {
        $this->candidates = [];

        $this->applyAndCaptureContent(self::THEME_CODE);
        $this->applyAndCaptureContent(self::THEME_CODE);

        self::assertCount(1, $this->lookups, '"No override" is an answer worth remembering.');
    }

    public function testADifferentThemeCodeDoesNotReuseAnotherThemesEntry(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID . '/Magento/blank', self::STORE_ID, 'blank', null, null),
            $this->override(2, self::THEMED_ID, self::STORE_ID, 'luma', null, null),
        ]);

        self::assertSame('luma', $this->applyAndCaptureContent(self::THEME_CODE));
        self::assertSame('blank', $this->applyAndCaptureContent('Magento/blank'));
        self::assertCount(2, $this->lookups, 'The theme code decides which identifiers are searched.');
    }

    public function testResetStateDropsTheResolvedOverrides(): void
    {
        $this->candidates = $this->ladderRows([8]);

        $this->applyAndCaptureContent(self::THEME_CODE);
        $this->plugin->_resetState();
        $this->applyAndCaptureContent(self::THEME_CODE);

        self::assertCount(2, $this->lookups, 'A batch send must not reuse the first message\'s answer.');
    }

    // -------------------------------------------------------------------------------------
    //  One stylesheet per email, inlined once
    // -------------------------------------------------------------------------------------

    public function testAnIncludedFootersCssIsInlinedOnceIntoTheWholeEmail(): void
    {
        $footer = $this->loadedWithCss('design_email_footer_template', '.border-t-3{}');
        $email = $this->template(null);

        $html = $this->render($this->plugin, $email, fn (): string => '<html><body>'
            . $this->render($this->plugin, $footer, static fn (): string => '<td class="border-t-3">f</td>')
            . '</body></html>');

        self::assertCount(1, $this->inlined, 'Only the outermost render inlines.');
        self::assertSame('<html><body><td class="border-t-3">f</td></body></html>', $this->inlined[0][0]);
        self::assertSame('.border-t-3{}', $this->inlined[0][2]);
        self::assertStringStartsWith('[inlined]', $html);
    }

    public function testAnIncludedTemplateReturnsItsMarkupWithoutAStyleBlock(): void
    {
        $footer = $this->loadedWithCss('design_email_footer_template', '.border-t-3{}');
        $fragment = null;

        $this->render($this->plugin, $this->template(null), function () use ($footer, &$fragment): string {
            $fragment = $this->render($this->plugin, $footer, static fn (): string => '</td></tr><tr><td>');

            return (string)$fragment;
        });

        self::assertSame('</td></tr><tr><td>', $fragment);
    }

    public function testTheMessagesOwnCssComesAfterTheCssOfWhatItIncludes(): void
    {
        $header = $this->loadedWithCss('design_email_header_template', '.header-rule{}');
        $email = $this->loadedWithCss(self::TEMPLATE_ID, '.message-rule{}');

        $this->render($this->plugin, $email, fn (): string
            => $this->render($this->plugin, $header, static fn (): string => '<html><body>') . '</body></html>');

        self::assertSame(".header-rule{}\n.message-rule{}", $this->inlined[0][2]);
    }

    public function testAPlainTextEmailIsNotStyled(): void
    {
        $email = $this->loadedWithCss(self::TEMPLATE_ID, '.x{}', AbstractTemplate::TYPE_TEXT);

        self::assertSame('Hello', $this->render($this->plugin, $email, static fn (): string => 'Hello'));
        self::assertSame([], $this->inlined);
    }

    public function testAnEmailProcessedTwiceIsStyledBothTimes(): void
    {
        $email = $this->loadedWithCss(self::TEMPLATE_ID, '.brand{}');

        $this->render($this->plugin, $email, static fn (): string => '<p class="brand">a</p>');
        $this->render($this->plugin, $email, static fn (): string => '<p class="brand">a</p>');

        self::assertSame('.brand{}', $this->inlined[1][2] ?? null, 'The sheet is not used up by the first render.');
    }

    public function testASheetNeverReachesAnUnrelatedTemplate(): void
    {
        $this->loadedWithCss(self::TEMPLATE_ID, '.brand{}');

        $this->render($this->plugin, $this->template(null), static fn (): string => '<p>x</p>');

        self::assertSame('', $this->inlined[0][2]);
    }

    public function testResolvedOverridesAreForgottenOnceAnEmailIsRendered(): void
    {
        $this->candidates = $this->ladderRows([8]);

        $first = $this->template(self::THEME_CODE);
        $this->plugin->afterLoadDefault($first, $first, self::TEMPLATE_ID);
        $this->render($this->plugin, $first, static fn (): string => 'x');

        $second = $this->template(self::THEME_CODE);
        $this->plugin->afterLoadDefault($second, $second, self::TEMPLATE_ID);

        self::assertCount(2, $this->lookups, 'A long-running sender must see an override replaced meanwhile.');
    }

    public function testAFailedRenderLeavesNoScopeOpenForTheNextEmail(): void
    {
        try {
            $this->render($this->plugin, $this->template(null), $this->failingRender());
            self::fail('The failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('filter failed', $e->getMessage());
        }

        $this->render($this->plugin, $this->template(null), static fn (): string => '<p>x</p>');

        self::assertCount(1, $this->inlined, 'The next email is outermost again, so it is inlined.');
    }

    public function testABypassedRenderIsLeftAlone(): void
    {
        $bypass = $this->createMock(PluginBypassFlagInterface::class);
        $bypass->method('isBypassed')->willReturn(true);
        $this->pluginBypassFlag = $bypass;
        $plugin = $this->pluginWith($this->storeManager, $this->recordingInliner());

        self::assertSame('<p>x</p>', $this->render($plugin, $this->template(null), static fn (): string => '<p>x</p>'));
        self::assertSame([], $this->inlined);
    }

    /**
     * The footer's border, end to end: stored with the footer, inlined into the email that includes it
     *
     * This is the class a footer override lost when its CSS travelled as a style block inside the
     * body, where the core filter's own inlining never looks.
     */
    public function testAFootersBorderEndsUpInlineOnItsCell(): void
    {
        $footer = $this->loadedWithCss(
            'design_email_footer_template',
            '@layer utilities { .border-t-3 { border-top-width: 3px; } .border-black { border-color: #000; } }'
        );
        $plugin = $this->pluginWith($this->storeManager, $this->realInliner());

        $html = $this->render($plugin, $this->template(null), fn (): string
            => '<html><head></head><body><table><tr><td>content</td></tr>'
            . $this->render($plugin, $footer, static fn (): string
                => '<tr><td class="footer border-t-3 border-black">f</td></tr>')
            . '</table></body></html>');

        self::assertMatchesRegularExpression(
            '/<td class="footer[^"]*"[^>]*style="[^"]*border-top-width:\s*3px/',
            $html
        );
        self::assertStringNotContainsString('.border-t-3', $html, 'No style block is left behind for it.');
    }

    // -------------------------------------------------------------------------------------
    //  Theme resolution failure
    // -------------------------------------------------------------------------------------

    public function testAFailingDesignLookupIsLoggedAndFallsBackToTheBareIdentifier(): void
    {
        $this->candidates = $this->ladderRows([8]);

        $template = $this->templateMock();
        $template->method('getDesignParams')
            ->willThrowException(new \RuntimeException('no design theme under emulation'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('no design theme under emulation'));

        $this->plugin->afterLoadDefault($template, $template, self::TEMPLATE_ID);

        self::assertSame([self::TEMPLATE_ID], $this->lookups[0][0]);
        self::assertSame('rung-8', $template->getData('template_text'));
    }

    // -------------------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------------------

    /**
     * Run the overlay and report which override's content reached the template
     *
     * @param string|null $themeCode Design theme the template reports, or null for none
     * @return string|null The applied content, or null when no overlay was applied
     */
    private function applyAndCaptureContent(?string $themeCode): ?string
    {
        $template = $this->template($themeCode);

        $this->plugin->afterLoadDefault($template, $template, self::TEMPLATE_ID);

        $applied = $template->getData('template_text');

        return is_string($applied) ? $applied : null;
    }

    /**
     * Build a template mock reporting the given design theme
     *
     * @param string|null $themeCode
     * @param int $type AbstractTemplate::TYPE_HTML or TYPE_TEXT
     * @return AbstractTemplate&MockObject
     */
    private function template(?string $themeCode, int $type = AbstractTemplate::TYPE_HTML): AbstractTemplate&MockObject
    {
        $template = $this->templateMock();
        $template->method('getDesignParams')
            ->willReturn($themeCode === null ? [] : ['theme' => $themeCode]);
        $template->method('getType')->willReturn($type);

        return $template;
    }

    /**
     * Build a bare template mock
     *
     * Only what the plugin reads and the abstract methods are replaced. The template text and
     * subject writers are left real - declared on the class in some releases, served by the data
     * object's magic in others - so what the overlay wrote is read back from the template's data.
     *
     * @return AbstractTemplate&MockObject
     */
    private function templateMock(): AbstractTemplate&MockObject
    {
        return $this->getMockBuilder(AbstractTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDesignParams', 'getType', 'getFilterFactory'])
            ->getMock();
    }

    /**
     * Build the plugin bound to a different current store
     *
     * @param int $storeId
     * @return EmailTemplatePlugin
     */
    private function pluginForStore(int $storeId): EmailTemplatePlugin
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $this->pluginWith($storeManager, $this->recordingInliner());
    }

    /**
     * Build the plugin around the test's doubles and the real stylesheet collector
     *
     * @param StoreManagerInterface $storeManager
     * @param CssInlinerInterface $inliner
     * @return EmailTemplatePlugin
     */
    private function pluginWith(
        StoreManagerInterface $storeManager,
        CssInlinerInterface $inliner
    ): EmailTemplatePlugin {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime(self::NOW));

        return new EmailTemplatePlugin(
            $this->config,
            $this->overrideRepository,
            $this->themeRepository,
            $this->createMock(UtilityCssGeneratorInterface::class),
            $inliner,
            $storeManager,
            $this->logger,
            $this->pluginBypassFlag,
            $this->editorContextFlag,
            $this->stylesheets,
            $timezone
        );
    }

    /**
     * An inliner that records what it was asked to inline and tags the markup it returns
     *
     * @return CssInlinerInterface
     */
    private function recordingInliner(): CssInlinerInterface
    {
        $inliner = $this->createMock(CssInlinerInterface::class);
        $inliner->method('inline')->willReturnCallback(
            function (string $html, ?string $custom = null, ?string $utility = null, ?string $theme = null): string {
                $this->inlined[] = [$html, $custom, $utility, $theme];

                return '[inlined]' . $html;
            }
        );

        return $inliner;
    }

    /**
     * The module's real inliner, flattening, resolving and promoting as an installation does
     *
     * @return CssInlinerInterface
     */
    private function realInliner(): CssInlinerInterface
    {
        $scanner = new CssSyntaxScanner();
        $parser = new CssStructureParser($scanner);

        return new CssInliner(
            new CssVariableResolver(new CssColorConverter(new ColorParser(), new ColorMixer()), $parser, $scanner),
            new NullLogger(),
            new CssLayerFlattener($parser),
            new CssImportantPromoter($parser)
        );
    }

    /**
     * Render a template through the plugin, the way Magento's interceptor does
     *
     * @param EmailTemplatePlugin $plugin
     * @param AbstractTemplate $template
     * @param callable(): string $render What the template's own processing produces
     * @return string
     */
    private function render(EmailTemplatePlugin $plugin, AbstractTemplate $template, callable $render): string
    {
        return $plugin->aroundGetProcessedTemplate(
            $template,
            static fn (array $variables): string => $render(),
            []
        );
    }

    /**
     * A template's own processing that fails, as a filter error does
     *
     * @return callable(): string
     */
    private function failingRender(): callable
    {
        return static function (): string {
            throw new \RuntimeException('filter failed');
        };
    }

    /**
     * Load a template through the overlay with an override carrying the given utility CSS
     *
     * @param string $identifier
     * @param string $utilityCss
     * @param int $type AbstractTemplate::TYPE_HTML or TYPE_TEXT
     * @return AbstractTemplate&MockObject
     */
    private function loadedWithCss(
        string $identifier,
        string $utilityCss,
        int $type = AbstractTemplate::TYPE_HTML
    ): AbstractTemplate&MockObject {
        $this->candidates = $this->group([
            $this->override(1, $identifier, 0, 'body of ' . $identifier, null, null, true, $utilityCss),
        ]);

        $template = $this->template(null, $type);
        $this->plugin->afterLoadDefault($template, $template, $identifier);
        $this->plugin->_resetState();

        return $template;
    }

    /**
     * Build one override per named rung, each tagged with the rung it occupies
     *
     * @param int[] $rungs
     * @return array<string, TemplateOverrideInterface[]>
     */
    private function ladderRows(array $rungs): array
    {
        $rows = [];

        foreach ($rungs as $rung) {
            [$identifier, $storeId, $scheduled] = self::LADDER[$rung];

            $rows[] = $this->override(
                $rung,
                $identifier,
                $storeId,
                'rung-' . $rung,
                $scheduled ? self::WINDOW_OPEN_FROM : null,
                $scheduled ? self::WINDOW_OPEN_TO : null
            );
        }

        return $this->group($rows);
    }

    /**
     * Group rows by identifier the way the batched lookup does
     *
     * The rows are shuffled first: the lookup groups by identifier but promises nothing about the
     * order within a group, so no ranking rule may depend on it.
     *
     * @param TemplateOverrideInterface[] $rows
     * @return array<string, TemplateOverrideInterface[]>
     */
    private function group(array $rows): array
    {
        $grouped = [];

        foreach (array_reverse($rows) as $row) {
            $grouped[(string)$row->getTemplateIdentifier()][] = $row;
        }

        return $grouped;
    }

    /**
     * Build one override row
     *
     * @param int $entityId
     * @param string $identifier
     * @param int $storeId
     * @param string $content
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @param bool $isActive
     * @param string|null $tailwindCss
     * @return TemplateOverrideInterface&MockObject
     */
    private function override(
        int $entityId,
        string $identifier,
        int $storeId,
        string $content,
        ?string $activeFrom,
        ?string $activeTo,
        bool $isActive = true,
        ?string $tailwindCss = null
    ): TemplateOverrideInterface&MockObject {
        $override = $this->createMock(TemplateOverrideInterface::class);
        $override->method('getEntityId')->willReturn($entityId);
        $override->method('getTemplateIdentifier')->willReturn($identifier);
        $override->method('getStoreId')->willReturn($storeId);
        $override->method('getStatus')->willReturn(TemplateOverrideInterface::STATUS_PUBLISHED);
        $override->method('getTemplateContent')->willReturn($content);
        $override->method('getTemplateSubject')->willReturn(null);
        $override->method('getActiveFrom')->willReturn($activeFrom);
        $override->method('getActiveTo')->willReturn($activeTo);
        $override->method('getIsActive')->willReturn($isActive);
        $override->method('getThemeId')->willReturn(null);
        $override->method('getTailwindCss')->willReturn($tailwindCss);
        $override->method('getCustomCss')->willReturn(null);

        return $override;
    }
}
