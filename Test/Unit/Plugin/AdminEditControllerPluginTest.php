<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Plugin;

use Hryvinskyi\EmailTemplateEditor\Api\ConfigInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Editor\Index as EditorPage;
use Hryvinskyi\EmailTemplateEditor\Plugin\AdminEditControllerPlugin;
use Magento\Email\Controller\Adminhtml\Email\Template\Edit;
use Magento\Email\Model\BackendTemplate;
use Magento\Email\Model\BackendTemplateFactory;
use Magento\Email\Model\Template\Config as EmailConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who gets taken off Magento's own transactional-email edit screen, and who is left on it.
 *
 * Hijacking a core screen is only ever an improvement for an admin who can both reach the screen it
 * leads to and find the row they were editing once they are there. Two things can fail that:
 *
 * The editor page enforces its own ACL resource, so a role that may edit email templates but was
 * never granted that resource has to stay on the core form - redirecting it would trade a working
 * screen for "Access Denied".
 *
 * The editor also lists one node per registered email template and keys those nodes by identifier,
 * so a row whose code names no registered template has no node to land on. Neither the code the
 * row was seeded from nor the label its admin typed can be trusted to name one.
 *
 * Both failures end the same way: a capability the admin already had is gone. So every exit from
 * the plugin is pinned here - the redirect is the exception, not the default.
 */
class AdminEditControllerPluginTest extends TestCase
{
    private const LEGACY_ID = 42;
    private const ORIG_CODE = 'sales_email_order_template';
    private const THEMED_CODE = self::ORIG_CODE . '/Magento/luma';
    private const OTHER_CODE = 'customer_create_account_email_template';
    private const ADMIN_LABEL = 'Our Order Confirmation';
    private const REMOVED_CODE = 'vendor_module_retired_template';

    /**
     * What the installation registers, in the shape the editor reads it in
     */
    private const REGISTERED = [
        ['value' => self::ORIG_CODE, 'label' => 'New Order', 'group' => 'Sales'],
        ['value' => self::THEMED_CODE, 'label' => 'New Order (Magento/luma)', 'group' => 'Sales'],
        ['value' => self::OTHER_CODE, 'label' => 'New Account', 'group' => 'Customer'],
    ];

    private ConfigInterface&MockObject $config;
    private RedirectFactory&MockObject $redirectFactory;
    private Redirect&MockObject $redirect;
    private BackendTemplateFactory&MockObject $templateFactory;
    private AuthorizationInterface&MockObject $authorization;
    private EmailConfig&MockObject $emailConfig;
    private AdminEditControllerPlugin $plugin;

    /**
     * Whether the role under test holds the resource the plugin asks about
     */
    private bool $granted = true;

    /**
     * How many times the core action was allowed to run
     */
    private int $proceedCalls = 0;

    /**
     * Every ACL resource the plugin asked about, in order
     *
     * @var string[]
     */
    private array $resourcesAsked = [];

    /**
     * How many times the registered template list was walked
     */
    private int $templateListReads = 0;

    /**
     * Every redirect the plugin built, as [path, params]
     *
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $redirects = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);

        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnCallback(
            function (string $path, array $params = []): Redirect {
                $this->redirects[] = [$path, $params];

                return $this->redirect;
            }
        );

        $this->redirectFactory = $this->createMock(RedirectFactory::class);
        $this->redirectFactory->method('create')->willReturn($this->redirect);

        $this->templateFactory = $this->createMock(BackendTemplateFactory::class);

        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->authorization->method('isAllowed')->willReturnCallback(
            function (?string $resource): bool {
                $this->resourcesAsked[] = (string)$resource;

                return $this->granted;
            }
        );

        $this->emailConfig = $this->createMock(EmailConfig::class);
        $this->emailConfig->method('getAvailableTemplates')->willReturnCallback(
            function (): array {
                $this->templateListReads++;

                return self::REGISTERED;
            }
        );

        $this->plugin = new AdminEditControllerPlugin(
            $this->config,
            $this->redirectFactory,
            $this->templateFactory,
            $this->authorization,
            $this->emailConfig,
            $this->storeManagerListing([])
        );
    }

    // -------------------------------------------------------------------------------------
    //  The permission gate
    // -------------------------------------------------------------------------------------

    public function testARoleThatMayUseTheEditorIsSentToIt(): void
    {
        $this->granted = true;
        $this->templateWillLoad(self::ORIG_CODE, null);

        $result = $this->runPlugin(self::LEGACY_ID);

        self::assertSame($this->redirect, $result);
        self::assertSame(0, $this->proceedCalls, 'The core screen must not render behind the redirect.');
        self::assertSame(
            [['emaileditor/editor/index', ['template' => self::ORIG_CODE, 'legacy_id' => self::LEGACY_ID]]],
            $this->redirects
        );
    }

    public function testARoleThatMayNotUseTheEditorIsLeftOnTheCoreScreen(): void
    {
        $this->granted = false;
        $this->templateWillLoad(self::ORIG_CODE, null);

        $result = $this->runPlugin(self::LEGACY_ID);

        self::assertNull($result, 'The core action renders itself and returns nothing.');
        self::assertSame(1, $this->proceedCalls);
        self::assertSame([], $this->redirects, 'A role that cannot open the editor is never sent there.');
    }

    public function testThePermissionAskedAboutIsTheOneTheDestinationEnforces(): void
    {
        // Asking about anything else would gate the redirect on a permission that has no bearing
        // on whether the editor page will actually open.
        $this->granted = true;
        $this->templateWillLoad(self::ORIG_CODE, null);

        $this->runPlugin(self::LEGACY_ID);

        self::assertSame([EditorPage::ADMIN_RESOURCE], $this->resourcesAsked);
    }

    public function testTheGatedResourceIsOneAclXmlActuallyDeclares(): void
    {
        // A resource nobody declares does not raise anything: the permission check falls back to
        // asking whether the role has blanket access, so a full administrator would keep being
        // redirected while every restricted role silently stopped being - the hardest shape of
        // this bug to notice, because it works for whoever is testing it.
        $acl = simplexml_load_file(dirname(__DIR__, 3) . '/etc/acl.xml');
        self::assertNotFalse($acl);

        $declared = [];
        foreach ($acl->xpath('//resource[@id]') ?: [] as $resource) {
            $declared[] = (string)$resource['id'];
        }

        self::assertContains(EditorPage::ADMIN_RESOURCE, $declared);
    }

    public function testNoPermissionIsAskedAboutWhileTheModuleIsDisabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);

        $plugin = new AdminEditControllerPlugin(
            $config,
            $this->redirectFactory,
            $this->templateFactory,
            $this->authorization,
            $this->emailConfig,
            $this->storeManagerListing([])
        );
        $this->templateWillLoad(self::ORIG_CODE, null);

        $result = $plugin->aroundExecute($this->subject(self::LEGACY_ID), $this->proceed());

        self::assertNull($result);
        self::assertSame(1, $this->proceedCalls);
        self::assertSame([], $this->resourcesAsked);
    }

    // -------------------------------------------------------------------------------------
    //  The code the editor is asked to open
    // -------------------------------------------------------------------------------------

    public function testACodeNamingNoRegisteredTemplateIsLeftOnTheCoreScreen(): void
    {
        // The module that declared this template has been removed since the row was saved. The
        // editor builds its tree from what is registered now, so it has no node for the row: the
        // core form is the only screen left that can still edit it.
        $this->granted = true;
        $this->templateWillLoad(self::REMOVED_CODE, null);

        $result = $this->runPlugin(self::LEGACY_ID);

        self::assertNull($result);
        self::assertSame(1, $this->proceedCalls);
        self::assertSame([], $this->redirects);
    }

    public function testTheAdminsOwnLabelIsFollowedWhenItHappensToNameARegisteredTemplate(): void
    {
        // Nothing forces the core New Template form to record what a row was seeded from, so a row
        // can carry only the label its admin typed - and that label is openable on the occasions it
        // spells a registered identifier.
        $this->granted = true;
        $this->templateWillLoad(null, self::OTHER_CODE);

        $this->runPlugin(self::LEGACY_ID);

        self::assertSame(self::OTHER_CODE, $this->redirects[0][1]['template']);
    }

    public function testAFreeTextLabelIsNotMistakenForATemplateIdentifier(): void
    {
        $this->granted = true;
        $this->templateWillLoad(null, self::ADMIN_LABEL);

        $result = $this->runPlugin(self::LEGACY_ID);

        self::assertNull($result);
        self::assertSame(1, $this->proceedCalls);
        self::assertSame([], $this->redirects, 'A label is not an identifier just because it is all the row has.');
    }

    public function testTheSeededCodeIsPreferredWhileItStillNamesARegisteredTemplate(): void
    {
        $this->granted = true;
        $this->templateWillLoad(self::ORIG_CODE, self::OTHER_CODE);

        $this->runPlugin(self::LEGACY_ID);

        self::assertSame(self::ORIG_CODE, $this->redirects[0][1]['template']);
    }

    public function testAStaleSeededCodeGivesWayToALabelThatStillResolves(): void
    {
        $this->granted = true;
        $this->templateWillLoad(self::REMOVED_CODE, self::OTHER_CODE);

        $this->runPlugin(self::LEGACY_ID);

        self::assertSame(self::OTHER_CODE, $this->redirects[0][1]['template']);
    }

    public function testAThemeSpecificCodeIsOpenedUnderTheIdentifierItIsRegisteredWith(): void
    {
        // The theme variant is a node of its own, so the code travels whole. Trimming the suffix
        // would open the base template instead - a different node, showing different content.
        $this->granted = true;
        $this->templateWillLoad(self::THEMED_CODE, null);

        $this->runPlugin(self::LEGACY_ID);

        self::assertSame(self::THEMED_CODE, $this->redirects[0][1]['template']);
    }

    public function testTheRegisteredListIsWalkedOnceAtMost(): void
    {
        $this->granted = true;
        $this->templateWillLoad(self::REMOVED_CODE, self::ADMIN_LABEL);

        $this->runPlugin(self::LEGACY_ID);

        self::assertSame(1, $this->templateListReads, 'Both codes are answered from one walk of the list.');
    }

    // -------------------------------------------------------------------------------------
    //  The remaining ways out
    // -------------------------------------------------------------------------------------

    public function testTheNewTemplateFormIsNeverRedirected(): void
    {
        $this->granted = true;

        $result = $this->runPlugin(null);

        self::assertNull($result);
        self::assertSame(1, $this->proceedCalls, 'There is no saved row for the editor to open yet.');
        self::assertSame([], $this->redirects);
    }

    public function testAnIdWithNoRowBehindItIsLeftOnTheCoreScreen(): void
    {
        // A load that found nothing leaves an empty model, not an exception. Its codes read as
        // whatever was never set rather than as the missing row's, so the row has to be confirmed
        // present before anything is read off it.
        $this->granted = true;

        $template = $this->templateMock();
        $template->method('load')->willReturnSelf();
        $template->method('getId')->willReturn(null);
        $template->method('getOrigTemplateCode')->willReturn(self::ORIG_CODE);
        $this->templateFactory->method('create')->willReturn($template);

        $result = $this->runPlugin(self::LEGACY_ID);

        self::assertNull($result);
        self::assertSame(1, $this->proceedCalls);
        self::assertSame([], $this->redirects);
    }

    public function testARowThatCarriesNoCodeAtAllIsLeftOnTheCoreScreen(): void
    {
        $this->granted = true;
        $this->templateWillLoad(null, null);

        $result = $this->runPlugin(self::LEGACY_ID);

        self::assertNull($result);
        self::assertSame(1, $this->proceedCalls);
        self::assertSame([], $this->redirects);
        self::assertSame(0, $this->templateListReads, 'There is nothing to look up.');
    }

    public function testAFailureWhileReadingTheRowLeavesTheCoreScreenAlone(): void
    {
        $this->granted = true;

        $template = $this->templateMock();
        $template->method('load')->willThrowException(new \RuntimeException('connection lost'));
        $this->templateFactory->method('create')->willReturn($template);

        $result = $this->runPlugin(self::LEGACY_ID);

        self::assertNull($result);
        self::assertSame(1, $this->proceedCalls);
    }

    // -------------------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------------------

    /**
     * Run the plugin against an edit request for the given legacy template id
     *
     * @param int|null $legacyId Row being edited, or null for the "new template" form
     * @return mixed The redirect the plugin built, or whatever the core action returned
     */
    private function runPlugin(?int $legacyId): mixed
    {
        return $this->plugin->aroundExecute($this->subject($legacyId), $this->proceed());
    }

    /**
     * Build the callable standing in for the core action, counting how often it runs
     *
     * @return callable(): null
     */
    private function proceed(): callable
    {
        return function (): null {
            $this->proceedCalls++;

            return null;
        };
    }

    /**
     * Arrange an existing row carrying the given pair of codes
     *
     * @param string|null $origCode Identifier the row was seeded from, as saved
     * @param string|null $templateCode Label the admin gave the row
     * @return void
     */
    private function templateWillLoad(?string $origCode, ?string $templateCode): void
    {
        $template = $this->templateMock();
        $template->method('load')->willReturnSelf();
        $template->method('getId')->willReturn(self::LEGACY_ID);
        $template->method('getOrigTemplateCode')->willReturn($origCode);
        $template->method('getTemplateCode')->willReturn($templateCode);

        $this->templateFactory->method('create')->willReturn($template);
    }

    /**
     * Build a legacy template row mock
     *
     * The two code accessors are column reads served by __call, so they exist only once addMethods
     * declares them; load() and getId() are real methods and have to be stubbed as ones.
     *
     * @return BackendTemplate&MockObject
     */
    private function templateMock(): BackendTemplate&MockObject
    {
        return $this->getMockBuilder(BackendTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getId'])
            ->addMethods(['getOrigTemplateCode', 'getTemplateCode'])
            ->getMock();
    }

    /**
     * Build the core edit controller carrying the given row id on its request
     *
     * @param int|null $legacyId
     * @return Edit&MockObject
     */
    private function subject(?int $legacyId): Edit&MockObject
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed
                => $key === 'id' ? $legacyId : $default
        );

        $subject = $this->createMock(Edit::class);
        $subject->method('getRequest')->willReturn($request);

        return $subject;
    }

    public function testTheEditorIsReachableWhenAStoreViewHasItOnAndTheDefaultDoesNot(): void
    {
        // The setting is per store view. A merchant who switched the editor on for one store and
        // left the default alone would otherwise find it unreachable from this screen while it is
        // busy overriding that store's messages.
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturnCallback(
            static fn (int $storeId = 0): bool => $storeId === 3
        );

        $plugin = new AdminEditControllerPlugin(
            $config,
            $this->redirectFactory,
            $this->templateFactory,
            $this->authorization,
            $this->emailConfig,
            $this->storeManagerListing([3])
        );

        $this->templateWillLoad(self::ORIG_CODE, null);

        $plugin->aroundExecute($this->subject(self::LEGACY_ID), $this->proceed());

        self::assertNotEmpty($this->redirects, 'the admin is sent to the editor');
    }

    public function testNoStoreViewHavingItOnLeavesTheCoreScreenAlone(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);

        $plugin = new AdminEditControllerPlugin(
            $config,
            $this->redirectFactory,
            $this->templateFactory,
            $this->authorization,
            $this->emailConfig,
            $this->storeManagerListing([1, 2, 3])
        );

        $this->templateWillLoad(self::ORIG_CODE, null);

        $plugin->aroundExecute($this->subject(self::LEGACY_ID), $this->proceed());

        self::assertSame([], $this->redirects, 'nothing is redirected');
    }

    /**
     * A store manager listing store views with the given ids
     *
     * @param array<int, int> $storeIds Store view ids to list
     * @return StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function storeManagerListing(array $storeIds)
    {
        $stores = [];

        foreach ($storeIds as $storeId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $stores[] = $store;
        }

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        return $storeManager;
    }
}
