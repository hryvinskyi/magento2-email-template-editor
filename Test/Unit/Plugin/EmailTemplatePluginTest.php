<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Plugin;

use Hryvinskyi\EmailTemplateEditor\Api\ConfigInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssImportantPromoterInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssInlinerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssLayerFlattenerInterface;
use Hryvinskyi\EmailTemplateEditor\Api\CssVariableResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\TemplateOverrideInterface;
use Hryvinskyi\EmailTemplateEditor\Api\EditorContextFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PluginBypassFlagInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateOverrideRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\ThemeRepositoryInterface;
use Hryvinskyi\EmailTemplateEditor\Api\UtilityCssGeneratorInterface;
use Hryvinskyi\EmailTemplateEditor\Plugin\EmailTemplatePlugin;
use Magento\Email\Model\AbstractTemplate;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    private ConfigInterface&MockObject $config;
    private TemplateOverrideRepositoryInterface&MockObject $overrideRepository;
    private ThemeRepositoryInterface&MockObject $themeRepository;
    private StoreManagerInterface&MockObject $storeManager;
    private LoggerInterface&MockObject $logger;
    private PluginBypassFlagInterface&MockObject $pluginBypassFlag;
    private EditorContextFlagInterface&MockObject $editorContextFlag;
    private CssLayerFlattenerInterface&MockObject $layerFlattener;
    private CssVariableResolverInterface&MockObject $cssVariableResolver;
    private CssImportantPromoterInterface&MockObject $importantPromoter;
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
        $this->layerFlattener = $this->createMock(CssLayerFlattenerInterface::class);
        $this->cssVariableResolver = $this->createMock(CssVariableResolverInterface::class);
        $this->importantPromoter = $this->createMock(CssImportantPromoterInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->method('isEnabled')->willReturn(true);
        $this->pluginBypassFlag->method('isBypassed')->willReturn(false);
        $this->editorContextFlag->method('isActive')->willReturn(false);

        // No theme row, so the combined CSS is whatever the override itself carries.
        $this->themeRepository->method('getDefaultTheme')->willReturn(null);

        $this->layerFlattener->method('flatten')->willReturnArgument(0);
        $this->cssVariableResolver->method('resolve')->willReturnArgument(0);
        $this->importantPromoter->method('promote')->willReturnArgument(0);

        // Every lookup is recorded so the tests can count them without an inline mock
        // expectation, whose failure the plugin's own catch block would swallow.
        $this->overrideRepository->method('getOverridesForIdentifiers')
            ->willReturnCallback(
                function (array $identifiers, array $storeIds, array $statuses): array {
                    $this->lookups[] = [$identifiers, $storeIds, $statuses];

                    return $this->candidates;
                }
            );

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime(self::NOW));

        $this->plugin = new EmailTemplatePlugin(
            $this->config,
            $this->overrideRepository,
            $this->themeRepository,
            $this->createMock(UtilityCssGeneratorInterface::class),
            $this->createMock(CssInlinerInterface::class),
            $this->storeManager,
            $this->logger,
            $this->pluginBypassFlag,
            $this->editorContextFlag,
            $this->cssVariableResolver,
            $this->layerFlattener,
            $this->importantPromoter,
            $timezone
        );
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

    public function testARowWithNoStartDateIsNotPromotedIntoTheScheduledBlock(): void
    {
        // The dangerous half of the null trap. Comparing an unset start bound directly would cast
        // it to an empty string, which sorts before every timestamp, so this row would read as
        // "started long ago, ends in the future" and jump the whole immediate block - beating a
        // genuine undated override sitting at a better identifier and store.
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'half-open', null, self::WINDOW_OPEN_TO),
            $this->override(2, self::THEMED_ID, self::STORE_ID, 'undated', null, null),
        ]);

        self::assertSame('undated', $this->applyAndCaptureContent(self::THEME_CODE));
    }

    public function testARowWithOnlyAStartDateIsInvisible(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'half-open', self::WINDOW_OPEN_FROM, null),
        ]);

        self::assertNull(
            $this->applyAndCaptureContent(self::THEME_CODE),
            'A half-open window matches neither the scheduled nor the immediate test.'
        );
    }

    public function testARowWithOnlyAnEndDateIsInvisible(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'half-open', null, self::WINDOW_OPEN_TO),
        ]);

        self::assertNull($this->applyAndCaptureContent(self::THEME_CODE));
    }

    public function testAClosedScheduleWindowIsSkippedInFavourOfAnUndatedRow(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'expired', '2026-01-01 00:00:00', '2026-02-01 00:00:00'),
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

    public function testAnInactiveUndatedRowStillMasksAnActiveRowBelowIt(): void
    {
        // is_active is filtered for the scheduled rungs and deliberately not for the immediate
        // ones, so the inactive row wins its rung and then suppresses the overlay entirely.
        $this->candidates = $this->group([
            $this->override(1, self::THEMED_ID, self::STORE_ID, 'inactive', null, null, false),
            $this->override(2, self::TEMPLATE_ID, 0, 'active', null, null),
        ]);

        self::assertNull(
            $this->applyAndCaptureContent(self::THEME_CODE),
            'An inactive undated override masks everything ranked below it.'
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

        $this->plugin->afterLoadDefault($this->template(self::THEME_CODE), $this->template(self::THEME_CODE), self::TEMPLATE_ID);

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

        $this->plugin->afterLoadDefault($this->template(self::THEME_CODE), $this->template(self::THEME_CODE), self::TEMPLATE_ID);
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
    //  Pending CSS
    // -------------------------------------------------------------------------------------

    public function testPendingCssIsEmbeddedForTheTemplateItWasResolvedFor(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'body', null, null, true, '.brand { color: red; }'),
        ]);

        $template = $this->template(null);
        $this->plugin->afterLoadDefault($template, $template, self::TEMPLATE_ID);

        $processed = $this->plugin->afterGetProcessedTemplate($template, '<html><head></head><body>x</body></html>');

        self::assertStringContainsString('.brand { color: red; }', $processed);
        self::assertStringContainsString('</style></head>', str_replace("\n", '', $processed));
    }

    public function testPendingCssNeverLeaksOntoAnUnrelatedTemplate(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'body', null, null, true, '.brand { color: red; }'),
        ]);

        $owner = $this->template(null);
        $this->plugin->afterLoadDefault($owner, $owner, self::TEMPLATE_ID);

        $stranger = $this->template(null);
        $markup = '<html><head></head><body>x</body></html>';

        self::assertSame(
            $markup,
            $this->plugin->afterGetProcessedTemplate($stranger, $markup),
            'The pending CSS belongs to the template object it was resolved for, not to a slot.'
        );
        self::assertStringContainsString(
            '.brand { color: red; }',
            $this->plugin->afterGetProcessedTemplate($owner, $markup)
        );
    }

    public function testPendingCssIsConsumedOnlyOnce(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'body', null, null, true, '.brand { color: red; }'),
        ]);

        $template = $this->template(null);
        $this->plugin->afterLoadDefault($template, $template, self::TEMPLATE_ID);

        $markup = '<html><head></head><body>x</body></html>';
        $this->plugin->afterGetProcessedTemplate($template, $markup);

        self::assertSame($markup, $this->plugin->afterGetProcessedTemplate($template, $markup));
    }

    public function testPendingCssIsKeyedWeaklyByTheTemplateObject(): void
    {
        // Not by spl_object_id(): PHP recycles those handles once an object is collected, so an
        // entry left behind by a template that was loaded but never processed could be handed to
        // an unrelated template later in the same process. A weak key cannot be recycled, and the
        // entry goes away with the template rather than accumulating.
        $map = (new \ReflectionProperty(EmailTemplatePlugin::class, 'pendingCssMap'))
            ->getValue($this->plugin);

        self::assertInstanceOf(\WeakMap::class, $map);
    }

    public function testResetStateDropsPendingCss(): void
    {
        $this->candidates = $this->group([
            $this->override(1, self::TEMPLATE_ID, 0, 'body', null, null, true, '.brand { color: red; }'),
        ]);

        $template = $this->template(null);
        $this->plugin->afterLoadDefault($template, $template, self::TEMPLATE_ID);
        $this->plugin->_resetState();

        $markup = '<html><head></head><body>x</body></html>';

        self::assertSame($markup, $this->plugin->afterGetProcessedTemplate($template, $markup));
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

        $applied = null;
        $template->method('setTemplateText')->willReturnCallback(
            static function (string $text) use (&$applied, $template) {
                $applied = $text;

                return $template;
            }
        );

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('no design theme under emulation'));

        $this->plugin->afterLoadDefault($template, $template, self::TEMPLATE_ID);

        self::assertSame([self::TEMPLATE_ID], $this->lookups[0][0]);
        self::assertSame('rung-8', $applied);
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
        $applied = null;

        $template = $this->template($themeCode);
        $template->method('setTemplateText')->willReturnCallback(
            static function (string $text) use (&$applied, $template) {
                $applied = $text;

                return $template;
            }
        );

        $this->plugin->afterLoadDefault($template, $template, self::TEMPLATE_ID);

        return $applied;
    }

    /**
     * Build a template mock reporting the given design theme
     *
     * @param string|null $themeCode
     * @return AbstractTemplate&MockObject
     */
    private function template(?string $themeCode): AbstractTemplate&MockObject
    {
        $template = $this->templateMock();
        $template->method('getDesignParams')
            ->willReturn($themeCode === null ? [] : ['theme' => $themeCode]);

        return $template;
    }

    /**
     * Build a bare template mock
     *
     * getDesignParams() is declared on the class, so it is stubbed with onlyMethods; the
     * template_text/template_subject writers are column writes served by __call and exist only
     * once addMethods declares them. getType() and getFilterFactory() are abstract and have to be
     * listed as well, because a restricted mock implements nothing it was not asked to.
     *
     * @return AbstractTemplate&MockObject
     */
    private function templateMock(): AbstractTemplate&MockObject
    {
        return $this->getMockBuilder(AbstractTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDesignParams', 'getType', 'getFilterFactory'])
            ->addMethods(['setTemplateText', 'setTemplateSubject'])
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

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime(self::NOW));

        return new EmailTemplatePlugin(
            $this->config,
            $this->overrideRepository,
            $this->themeRepository,
            $this->createMock(UtilityCssGeneratorInterface::class),
            $this->createMock(CssInlinerInterface::class),
            $storeManager,
            $this->logger,
            $this->pluginBypassFlag,
            $this->editorContextFlag,
            $this->cssVariableResolver,
            $this->layerFlattener,
            $this->importantPromoter,
            $timezone
        );
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
