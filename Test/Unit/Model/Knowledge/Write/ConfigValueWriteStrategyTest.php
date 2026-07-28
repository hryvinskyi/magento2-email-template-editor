<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Write;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathReadability;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\ConfigPathWritability;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Write\ConfigValueWriteStrategy;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\PreparedValueFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ConfigResource\ConfigInterface as ConfigResource;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ValueInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Phrase;
use Magento\Variable\Model\Source\Variables as ConfigVariables;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException as PhpRuntimeException;

/**
 * Changing a store configuration value from the editor.
 *
 * The gates are exercised through the real decision the inspector uses, not through a stub of it,
 * because the whole reason that decision is a collaborator is that both ends must be unable to
 * disagree - and a stub here would let them. Its own source of readable paths answers in the shape
 * the real one answers in: a list of paths. Asked the other way round, against the neighbouring map
 * that is keyed by path, the check would match nothing and refuse everything, so these tests are
 * also what stops that shape from being got wrong.
 */
class ConfigValueWriteStrategyTest extends TestCase
{
    private const STORE_ID = 3;
    private const NAME_PATH = 'general/store_information/name';
    private const CITY_PATH = 'general/store_information/city';
    private const COUNTRY_PATH = 'general/store_information/country_id';
    private const SENDER_EMAIL_PATH = 'trans_email/ident_general/email';
    private const BASE_URL_PATH = 'web/unsecure/base_url';
    private const SECRET_PATH = 'trans_email/ident_general/name';

    /**
     * The paths a {{config}} directive may read, in the shape the filter's own source answers in
     */
    private const AVAILABLE_VARS = [
        self::NAME_PATH,
        self::CITY_PATH,
        self::COUNTRY_PATH,
        self::SENDER_EMAIL_PATH,
        self::SECRET_PATH,
        self::BASE_URL_PATH,
    ];

    private Structure&MockObject $configStructure;

    private ConfigVariables&MockObject $configVariables;

    private PreparedValueFactory&MockObject $preparedValueFactory;

    private ConfigResource&MockObject $configResource;

    private TypeListInterface&MockObject $cacheTypeList;

    private AuthSession&MockObject $authSession;

    private LoggerInterface&MockObject $logger;

    private MessageManagerInterface&MockObject $messageManager;

    /**
     * Failures the strategy logged during the test under way, in the order it logged them
     *
     * @var string[]
     */
    private array $loggedFailures = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->configStructure = $this->createMock(Structure::class);
        $this->configVariables = $this->createMock(ConfigVariables::class);
        $this->configVariables->method('getAvailableVars')->willReturn(self::AVAILABLE_VARS);
        $this->preparedValueFactory = $this->createMock(PreparedValueFactory::class);
        $this->configResource = $this->createMock(ConfigResource::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);

        $this->authSession = $this->getMockBuilder(AuthSession::class)
            ->disableOriginalConstructor()
            ->addMethods(['getUser'])
            ->getMock();
        $this->authSession->method('getUser')->willReturn(null);
    }

    public function testItClaimsConfigurationOriginsOnly(): void
    {
        $strategy = $this->strategy();

        self::assertTrue($strategy->supports(new Origin(OriginInterface::KIND_CONFIG, self::NAME_PATH, '')));
        self::assertFalse(
            $strategy->supports(new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, 'my_code', ''))
        );
    }

    /**
     * A store view write names the store scope and the store view; the default scope names neither.
     * The arguments are asserted rather than the fact of the call, because a row written in the
     * wrong scope is honoured at render time and invisible where it would be corrected.
     *
     * @return void
     */
    public function testAStoreViewWriteIsScopedToThatStoreView(): void
    {
        $this->structureAnswers([self::NAME_PATH => $this->field(['label' => 'Store Name', 'store' => true])]);
        $this->preparedValueFactory
            ->expects(self::once())
            ->method('create')
            ->with(self::NAME_PATH, 'Acme Ltd', 'stores', self::STORE_ID)
            ->willReturn($this->preparedValue(self::NAME_PATH, 'Acme Ltd'));

        $this->configResource
            ->expects(self::once())
            ->method('saveConfig')
            ->with(self::NAME_PATH, 'Acme Ltd', 'stores', self::STORE_ID);

        $this->strategy()->write($this->entry(self::NAME_PATH), self::STORE_ID, 'Acme Ltd');
    }

    /**
     * @return void
     */
    public function testADefaultScopeWriteIsScopedToTheDefaultConfiguration(): void
    {
        $this->structureAnswers([self::NAME_PATH => $this->field(['label' => 'Store Name', 'store' => true])]);
        $this->preparedValueFactory
            ->expects(self::once())
            ->method('create')
            ->with(self::NAME_PATH, 'Acme Ltd', 'default', null)
            ->willReturn($this->preparedValue(self::NAME_PATH, 'Acme Ltd'));

        $this->configResource
            ->expects(self::once())
            ->method('saveConfig')
            ->with(self::NAME_PATH, 'Acme Ltd', 'default', 0);

        $this->strategy()->write($this->entry(self::NAME_PATH), 0, 'Acme Ltd');
    }

    /**
     * Without this the configuration page and every message keep serving the old value, and the
     * change reads as having done nothing.
     *
     * @return void
     */
    public function testTheConfigurationCacheIsMarkedForRefreshAfterAWrite(): void
    {
        $this->structureAnswers([self::NAME_PATH => $this->field(['label' => 'Store Name', 'store' => true])]);
        $this->preparedValueFactory->method('create')
            ->willReturn($this->preparedValue(self::NAME_PATH, 'Acme Ltd'));

        $this->cacheTypeList->expects(self::once())->method('invalidate')->with('config');

        $this->strategy()->write($this->entry(self::NAME_PATH), self::STORE_ID, 'Acme Ltd');
    }

    /**
     * A way into the configuration table from a screen nobody would look for one on needs a trail.
     *
     * @return void
     */
    public function testASuccessfulWriteIsRecorded(): void
    {
        $this->structureAnswers([self::NAME_PATH => $this->field(['label' => 'Store Name', 'store' => true])]);
        $this->preparedValueFactory->method('create')
            ->willReturn($this->preparedValue(self::NAME_PATH, 'Acme Ltd'));

        $this->logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains(self::NAME_PATH));

        $this->strategy()->write($this->entry(self::NAME_PATH), self::STORE_ID, 'Acme Ltd');
    }

    /**
     * The field's own model is what decides whether a value is acceptable, and its refusal is passed
     * on in its own words. Nothing is persisted, which is the assertion that matters.
     *
     * @return void
     */
    public function testAValueTheFieldsOwnModelRejectsIsRefusedWithNothingPersisted(): void
    {
        $this->structureAnswers([
            self::SENDER_EMAIL_PATH => $this->field([
                'label' => 'Sender Email',
                'store' => true,
                'backendModel' => $this->createMock(Value::class),
            ]),
        ]);

        $prepared = $this->preparedValue(self::SENDER_EMAIL_PATH, 'not-an-address');
        $prepared->method('beforeSave')->willThrowException(
            new LocalizedException(new Phrase('The "not-an-address" email address is incorrect.'))
        );
        $this->preparedValueFactory->method('create')->willReturn($prepared);

        $this->configResource->expects(self::never())->method('saveConfig');
        $this->cacheTypeList->expects(self::never())->method('invalidate');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('email address is incorrect');

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, 'not-an-address');
    }

    /**
     * An ordinary backend model is the reason for taking the long way round, so it is no reason to
     * refuse - and the value the model settles on is what gets stored, not the one that was typed.
     *
     * @return void
     */
    public function testAFieldWithAnOrdinaryBackendModelIsWrittenThroughIt(): void
    {
        $this->structureAnswers([
            self::SENDER_EMAIL_PATH => $this->field([
                'label' => 'Sender Email',
                'store' => true,
                'backendModel' => $this->createMock(Value::class),
            ]),
        ]);

        $prepared = $this->preparedValue(self::SENDER_EMAIL_PATH, ' orders@example.com ');
        $prepared->method('beforeSave')->willReturnCallback(
            static function () use ($prepared): void {
                // Normalising the input is part of what a backend model is for, and what it settles
                // on is what belongs in the table.
                $prepared->setValue('orders@example.com');
            }
        );
        $this->preparedValueFactory->method('create')->willReturn($prepared);

        $this->configResource
            ->expects(self::once())
            ->method('saveConfig')
            ->with(self::SENDER_EMAIL_PATH, 'orders@example.com', 'stores', self::STORE_ID);

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, ' orders@example.com ');
    }

    /**
     * A field's own work after a save is run, and run in the only order in which it can be honest
     * about what has happened: after the row exists. Skipping it because no path currently on offer
     * needs it would fail silently the moment one did.
     *
     * @return void
     */
    public function testWhatTheFieldDoesAfterASaveIsRunOnceTheRowHasLanded(): void
    {
        $prepared = $this->fieldWhoseModelTakesPartInTheSave();

        $order = [];
        $this->configResource->method('saveConfig')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'row written';
            }
        );
        $prepared->expects(self::once())->method('afterSave')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'field notified';
            }
        );

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, 'orders@example.com');

        self::assertSame(['row written', 'field notified'], $order);
    }

    /**
     * The change happened. Reporting it as a refusal would leave the administrator believing the old
     * value is still in place while every message renders the new one, which is the one account of
     * events worse than none.
     *
     * @return void
     */
    public function testAFailureOnceTheRowHasLandedIsNotReportedAsARefusal(): void
    {
        $this->fieldWhoseModelFailsAfterTheSave();

        $this->configResource->expects(self::once())->method('saveConfig');

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, 'orders@example.com');
    }

    /**
     * What the administrator gets instead: the change stated as having happened, and what did not
     * happen stated beside it in terms they can do something about.
     *
     * @return void
     */
    public function testAFailureOnceTheRowHasLandedIsReportedAsACompletedWriteWithAWarning(): void
    {
        $this->fieldWhoseModelFailsAfterTheSave();

        $warnings = [];
        $this->messageManager->method('addWarningMessage')->willReturnCallback(
            static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            }
        );

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, 'orders@example.com');

        self::assertCount(1, $warnings);
        self::assertStringContainsString(self::SENDER_EMAIL_PATH, $warnings[0]);
        self::assertStringContainsString('was saved', $warnings[0]);
        self::assertStringContainsString('configuration page', $warnings[0]);
    }

    /**
     * Whoever has to work out what was left undone needs the three things that identify it: which
     * value, in which scope, and whose step it was.
     *
     * @return void
     */
    public function testAFailureOnceTheRowHasLandedIsLoggedWithThePathTheScopeAndTheModel(): void
    {
        $prepared = $this->fieldWhoseModelFailsAfterTheSave();

        $this->captureLoggedFailures();

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, 'orders@example.com');

        self::assertCount(1, $this->loggedFailures);
        self::assertStringContainsString(self::SENDER_EMAIL_PATH, $this->loggedFailures[0]);
        self::assertStringContainsString('stores', $this->loggedFailures[0]);
        self::assertStringContainsString((string)self::STORE_ID, $this->loggedFailures[0]);
        self::assertStringContainsString(get_debug_type($prepared), $this->loggedFailures[0]);
        self::assertStringContainsString('The identity cache could not be rebuilt.', $this->loggedFailures[0]);
    }

    /**
     * The invalidation is done here rather than left to the field's own step precisely so that it
     * cannot be lost with that step. A model that throws halfway through its own after-save never
     * reaches the invalidation inside it, and a stale cache makes a change that did happen read as
     * one that did nothing.
     *
     * @return void
     */
    public function testTheConfigurationCacheIsMarkedForRefreshEvenWhenTheFieldsOwnStepFails(): void
    {
        $this->fieldWhoseModelFailsAfterTheSave();

        $this->cacheTypeList->expects(self::once())->method('invalidate')->with('config');

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, 'orders@example.com');
    }

    /**
     * The trail records the change, which happened, whatever went wrong after it.
     *
     * @return void
     */
    public function testTheChangeIsStillRecordedWhenTheFieldsOwnStepFails(): void
    {
        $this->fieldWhoseModelFailsAfterTheSave();

        $this->logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains(self::SENDER_EMAIL_PATH));

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, 'orders@example.com');
    }

    /**
     * Telling the administrator may itself fail, and a session that cannot take a warning must not
     * become the reason they are told their change was rejected. The log keeps both facts.
     *
     * @return void
     */
    public function testAWarningThatCannotBeQueuedStillLeavesTheWriteReportedAsDone(): void
    {
        $this->fieldWhoseModelFailsAfterTheSave();
        $this->messageManager->method('addWarningMessage')->willThrowException(
            new PhpRuntimeException('There is no session to put a message in.')
        );

        $this->captureLoggedFailures();

        $this->strategy()->write($this->entry(self::SENDER_EMAIL_PATH), self::STORE_ID, 'orders@example.com');

        self::assertCount(2, $this->loggedFailures);
        self::assertStringContainsString('did not finish', $this->loggedFailures[0]);
        self::assertStringContainsString('could not be warned', $this->loggedFailures[1]);
        self::assertStringContainsString(
            'There is no session to put a message in.',
            $this->loggedFailures[1]
        );
    }

    /**
     * A field the structure gives no lifecycle model for is written as asked and has nothing run
     * after it, which is not a failure and is not warned about.
     *
     * @return void
     */
    public function testAPreparedValueOutsideTheSaveLifecycleIsWrittenAsAskedWithNothingRunAfterIt(): void
    {
        $this->structureAnswers([self::NAME_PATH => $this->field(['label' => 'Store Name', 'store' => true])]);
        $this->preparedValueFactory->method('create')->willReturn($this->createMock(ValueInterface::class));

        $this->configResource->expects(self::once())
            ->method('saveConfig')
            ->with(self::NAME_PATH, 'Acme Ltd', 'stores', self::STORE_ID);
        $this->cacheTypeList->expects(self::once())->method('invalidate')->with('config');
        $this->messageManager->expects(self::never())->method('addWarningMessage');
        $this->logger->expects(self::never())->method('error');

        $this->strategy()->write($this->entry(self::NAME_PATH), self::STORE_ID, 'Acme Ltd');
    }

    /**
     * A model that cannot be built at all means the value was never checked, and storing an
     * unchecked value is what coming this way round exists to prevent.
     *
     * @return void
     */
    public function testAPreparedValueThatCannotBeBuiltIsRefused(): void
    {
        $this->structureAnswers([self::NAME_PATH => $this->field(['label' => 'Store Name', 'store' => true])]);
        $this->preparedValueFactory->method('create')
            ->willThrowException(new RuntimeException(new Phrase('No such backend model.')));

        $this->configResource->expects(self::never())->method('saveConfig');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not be prepared');

        $this->strategy()->write($this->entry(self::NAME_PATH), self::STORE_ID, 'Acme Ltd');
    }

    /**
     * The entry said the path was writable; it is checked again anyway, because the entry was built
     * from the same inputs a moment earlier and this is the last point before a row is written.
     *
     * @return void
     */
    public function testAPathTheDirectiveCannotReadIsRefusedEvenWhenTheEntryClaimsOtherwise(): void
    {
        $this->structureAnswers([
            'general/locale/timezone' => $this->field(['label' => 'Timezone', 'store' => true]),
        ]);

        $this->preparedValueFactory->expects(self::never())->method('create');
        $this->configResource->expects(self::never())->method('saveConfig');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('renders as an empty string');

        $this->strategy()->write($this->entry('general/locale/timezone'), self::STORE_ID, 'UTC');
    }

    /**
     * A cipher must not be echoed into a panel, and an inline text box is not where a credential
     * gets rotated. The refusal says so without repeating what was typed.
     *
     * @return void
     */
    public function testAnEncryptedFieldIsRefusedAndItsValueIsNotEchoedBack(): void
    {
        $this->structureAnswers([
            self::SECRET_PATH => $this->field([
                'label' => 'Sender Name',
                'store' => true,
                'backendModel' => $this->createMock(Encrypted::class),
            ]),
        ]);

        $this->configResource->expects(self::never())->method('saveConfig');

        try {
            $this->strategy()->write($this->entry(self::SECRET_PATH), self::STORE_ID, 'super-secret-value');
            self::fail('An encrypted field must not be writable from the editor.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('stored encrypted', $exception->getMessage());
            self::assertStringNotContainsString('super-secret-value', $exception->getMessage());
        }
    }

    /**
     * A blast-radius rule rather than a technical one: the base URL fields would validate the value
     * perfectly well, and changing one from an email editor is still not on offer.
     *
     * @return void
     */
    public function testABaseUrlIsRefusedEvenThoughItsModelWouldHaveValidatedIt(): void
    {
        $this->structureAnswers([self::BASE_URL_PATH => $this->field(['label' => 'Base URL', 'store' => true])]);

        $this->configResource->expects(self::never())->method('saveConfig');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not editable from the email template editor');

        $this->strategy()->write($this->entry(self::BASE_URL_PATH), self::STORE_ID, 'https://example.com/');
    }

    /**
     * A field the configuration form does not offer for a single store view can still be written for
     * one, and the result is an override the administrator can neither see nor clear. At the default
     * scope the same field is perfectly ordinary.
     *
     * @return void
     */
    public function testAFieldHiddenAtStoreScopeIsRefusedThereAndWrittenAtTheDefaultScope(): void
    {
        $this->structureAnswers([
            self::CITY_PATH => $this->field([
                'label' => 'City',
                'default' => true,
                'website' => true,
                'store' => false,
            ]),
        ]);
        $this->preparedValueFactory->method('create')
            ->willReturn($this->preparedValue(self::CITY_PATH, 'Springfield'));

        $this->configResource->expects(self::once())
            ->method('saveConfig')
            ->with(self::CITY_PATH, 'Springfield', 'default', 0);

        $strategy = $this->strategy();

        try {
            $strategy->write($this->entry(self::CITY_PATH), self::STORE_ID, 'Springfield');
            self::fail('A field the form hides at store scope must not be written at store scope.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('single store view', $exception->getMessage());
        }

        $strategy->write($this->entry(self::CITY_PATH), 0, 'Springfield');
    }

    /**
     * The country renders as the country's name rather than as the stored code, so a value taken
     * from what the panel shows would store a name where a code belongs - at any scope.
     *
     * @dataProvider scopeProvider
     *
     * @param int $storeId Scope to try
     * @return void
     */
    public function testAPathWhoseRenderedValueIsNotTheStoredValueIsRefusedAtEveryScope(int $storeId): void
    {
        $this->structureAnswers([
            self::COUNTRY_PATH => $this->field(['label' => 'Country', 'store' => true]),
        ]);

        $this->configResource->expects(self::never())->method('saveConfig');

        $this->expectException(LocalizedException::class);

        $this->strategy()->write($this->entry(self::COUNTRY_PATH), $storeId, 'United States');
    }

    /**
     * @return array<string, array{int}>
     */
    public function scopeProvider(): array
    {
        return [
            'default scope' => [0],
            'store scope' => [self::STORE_ID],
        ];
    }

    /**
     * The strategy under test, wired to the real decision about what may be written
     *
     * @return ConfigValueWriteStrategy
     */
    private function strategy(): ConfigValueWriteStrategy
    {
        return new ConfigValueWriteStrategy(
            new ConfigPathWritability(
                $this->configStructure,
                new ConfigPathReadability($this->configVariables)
            ),
            $this->preparedValueFactory,
            $this->configResource,
            $this->cacheTypeList,
            $this->authSession,
            $this->logger,
            $this->messageManager
        );
    }

    /**
     * A writable field whose own model takes part in the save lifecycle, and that model
     *
     * @return Value&MockObject
     */
    private function fieldWhoseModelTakesPartInTheSave(): Value
    {
        $this->structureAnswers([
            self::SENDER_EMAIL_PATH => $this->field([
                'label' => 'Sender Email',
                'store' => true,
                'backendModel' => $this->createMock(Value::class),
            ]),
        ]);

        $prepared = $this->preparedValue(self::SENDER_EMAIL_PATH, 'orders@example.com');
        $this->preparedValueFactory->method('create')->willReturn($prepared);

        return $prepared;
    }

    /**
     * The same field, with its model throwing at the one point where the row has already landed
     *
     * The failure is a plain runtime one rather than a localised one on purpose: what a backend
     * model throws after a save is not addressed to an administrator, and it must not become the
     * message an administrator is shown in place of the change they made.
     *
     * @return Value&MockObject
     */
    private function fieldWhoseModelFailsAfterTheSave(): Value
    {
        $prepared = $this->fieldWhoseModelTakesPartInTheSave();
        $prepared->method('afterSave')->willThrowException(
            new PhpRuntimeException('The identity cache could not be rebuilt.')
        );

        return $prepared;
    }

    /**
     * Start collecting the messages the strategy logs as failures, in the order it logs them
     *
     * @return void
     */
    private function captureLoggedFailures(): void
    {
        $this->logger->method('error')->willReturnCallback(
            function (string $message): void {
                $this->loggedFailures[] = $message;
            }
        );
    }

    /**
     * A prepared value carrying the path and value a backend model settled on
     *
     * The model is built without its constructor and only its lifecycle hooks are replaced, so that
     * reading a path and a value off it goes through the same accessors the real one uses.
     *
     * @param string $path Path the model reports
     * @param string $value Value the model reports
     * @return Value&MockObject
     */
    private function preparedValue(string $path, string $value): Value
    {
        $prepared = $this->getMockBuilder(Value::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['beforeSave', 'afterSave'])
            ->getMock();
        $prepared->setPath($path);
        $prepared->setValue($value);

        return $prepared;
    }

    /**
     * An entry for a configuration value, reporting itself writable whatever the gates say
     *
     * @param string $path Configuration path the origin points at
     * @return VariableKnowledgeInterface
     */
    private function entry(string $path): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('config', $path),
            true,
            'Configuration value',
            'Read from the store configuration.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, $path, ''),
            [],
            null,
            true
        );
    }

    /**
     * Teach the structure mock what it holds, answering anything else with the placeholder element
     * the real structure would return.
     *
     * @param array<string, Field&MockObject> $elements Configuration path to the field behind it
     * @return void
     */
    private function structureAnswers(array $elements): void
    {
        $this->configStructure
            ->method('getElementByConfigPath')
            ->willReturnCallback(fn (string $path): Field => $elements[$path] ?? $this->field([]));
    }

    /**
     * A configuration field mock
     *
     * @param array{label?: string, default?: bool, website?: bool, store?: bool,
     *              backendModel?: object} $spec What the field declares; anything absent is declared
     *              as absent
     * @return Field&MockObject
     */
    private function field(array $spec): Field
    {
        $field = $this->createMock(Field::class);
        $field->method('getLabel')->willReturn($spec['label'] ?? '');
        $field->method('getComment')->willReturn('');
        // An empty specification is the placeholder the structure hands back for a path it has never
        // heard of: no label and none of the scope flags.
        $declared = $spec !== [];
        $field->method('showInDefault')->willReturn($spec['default'] ?? $declared);
        $field->method('showInWebsite')->willReturn($spec['website'] ?? $declared);
        $field->method('showInStore')->willReturn($spec['store'] ?? false);

        if (isset($spec['backendModel'])) {
            $field->method('hasBackendModel')->willReturn(true);
            $field->method('getBackendModel')->willReturn($spec['backendModel']);
        }

        return $field;
    }
}
