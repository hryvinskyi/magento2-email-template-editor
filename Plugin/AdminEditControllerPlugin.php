<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Plugin;

use Hryvinskyi\EmailTemplateEditor\Api\ConfigInterface;
use Hryvinskyi\EmailTemplateEditor\Controller\Adminhtml\Editor\Index as EditorPage;
use Magento\Email\Controller\Adminhtml\Email\Template\Edit;
use Magento\Email\Model\BackendTemplate;
use Magento\Email\Model\BackendTemplateFactory;
use Magento\Email\Model\Template\Config as EmailConfig;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;

class AdminEditControllerPlugin
{
    /**
     * @param ConfigInterface $config
     * @param RedirectFactory $redirectFactory
     * @param BackendTemplateFactory $templateFactory
     * @param AuthorizationInterface $authorization
     * @param EmailConfig $emailConfig
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly RedirectFactory $redirectFactory,
        private readonly BackendTemplateFactory $templateFactory,
        private readonly AuthorizationInterface $authorization,
        private readonly EmailConfig $emailConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether the editor is switched on for any store view at all
     *
     * The setting is per store view and decides whether overrides are applied when a message is
     * rendered there. This screen has no store view: it edits one template row, and the editor it
     * leads to manages overrides for every store. So the question worth asking here is whether the
     * editor is in use anywhere, not whether it happens to be switched on at the default scope -
     * a merchant who enabled it for one store view and left the default alone would otherwise find
     * the editor unreachable from this screen while it is busy overriding their messages.
     *
     * @return bool True when at least one scope has it switched on
     */
    private function isEnabledAnywhere(): bool
    {
        if ($this->config->isEnabled()) {
            return true;
        }

        foreach ($this->storeManager->getStores() as $store) {
            if ($this->config->isEnabled((int)$store->getId())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redirect template edit to our editor when enabled
     *
     * The core action renders its layout itself and returns nothing, so the redirect has to
     * replace the call rather than follow it - there is no result left to swap out afterwards.
     *
     * @param Edit $subject
     * @param callable $proceed
     * @return ResultInterface|null A redirect to the editor, or whatever the core action returns
     */
    public function aroundExecute(Edit $subject, callable $proceed)
    {
        if (!$this->isEnabledAnywhere()) {
            return $proceed();
        }

        // The destination gates itself on its own ACL resource, so a role that holds
        // Magento_Email::template without that resource would be redirected straight into
        // "Access Denied" and lose the ability to edit email templates at all. Sending only the
        // roles that may actually use the editor keeps everyone else on the core screen, exactly
        // as before this module was installed.
        if (!$this->authorization->isAllowed(EditorPage::ADMIN_RESOURCE)) {
            return $proceed();
        }

        $templateId = (int)$subject->getRequest()->getParam('id');
        if (!$templateId) {
            return $proceed();
        }

        try {
            /** @var BackendTemplate $template */
            $template = $this->templateFactory->create();
            $template->load($templateId);

            // An id with no row behind it belongs to the core screen, which reports it as a new
            // template. Nothing below may assume the load found anything.
            if (!$template->getId()) {
                return $proceed();
            }

            $templateCode = $this->resolveEditableCode($template);
            if ($templateCode === null) {
                return $proceed();
            }

            $redirect = $this->redirectFactory->create();
            $redirect->setPath(
                'emaileditor/editor/index',
                [
                    'template' => $templateCode,
                    'legacy_id' => $templateId,
                ]
            );

            return $redirect;
        } catch (\Exception $e) {
            return $proceed();
        }
    }

    /**
     * Resolve the code to open a legacy row under, if the editor can open it at all
     *
     * The editor lists one node per registered email template and keys those nodes by the exact
     * identifier the template is registered under, so a code outside that set has nothing to land
     * on: the admin would arrive at an editor that cannot show the row they asked to edit, having
     * lost the core form that could.
     *
     * Both codes the row carries are checked, because both can name a template that is not there.
     * orig_template_code records what the row was seeded from and outlives the module that
     * declared it; template_code is the free-text label the admin typed, which is openable only on
     * the occasions it happens to spell a registered identifier. The seeded code is preferred, and
     * the label is consulted whenever the seeded one is missing or no longer resolves.
     *
     * Codes are compared whole rather than by their parts: a theme-specific template is registered
     * under its "<id>/<vendor>/<theme>" identifier and listed as its own node, so trimming the
     * suffix would answer a question about a different node than the one being opened.
     *
     * @param BackendTemplate $template
     * @return string|null The code to open, or null when no node exists for this row
     */
    private function resolveEditableCode(BackendTemplate $template): ?string
    {
        $candidates = [];

        foreach ([$template->getOrigTemplateCode(), $template->getTemplateCode()] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $registered = $this->getRegisteredTemplateIds();

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $registered, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * List the identifiers the editor builds its template tree from
     *
     * @return string[]
     */
    private function getRegisteredTemplateIds(): array
    {
        $identifiers = [];

        foreach ($this->emailConfig->getAvailableTemplates() as $template) {
            $identifiers[] = (string)($template['value'] ?? '');
        }

        return $identifiers;
    }
}
