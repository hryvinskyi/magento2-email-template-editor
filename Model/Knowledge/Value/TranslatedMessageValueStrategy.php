<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Value;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueStrategyInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use Magento\Framework\App\Area;
use Magento\Framework\Translate\Inline\StateInterface as InlineTranslationState;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads what a translated message renders as, in the locale of the store view being edited.
 *
 * A translated message is one of the few directives whose value is knowable exactly, and saying "no
 * value" about it would be wrong rather than merely unhelpful: the message is right there in the
 * directive, and whether the store's language pack changes it is precisely what an author wants to
 * see before sending.
 *
 * The answer is only worth giving in the store's own locale. Rendering the phrase as the request
 * stands would translate it against the administrator's language and the admin area's dictionaries,
 * which is a different answer that looks exactly like the right one - so the read happens inside a
 * frontend environment emulation for the store, the way the editor already loads a template. Store
 * view 0 is not a store and has no locale of its own; there the phrase is reported untranslated,
 * which is what the default configuration will do with it.
 */
class TranslatedMessageValueStrategy implements ReferenceValueStrategyInterface
{
    /**
     * Directive kind whose expression is a message to be translated
     */
    private const TRANSLATED_KIND = 'trans';

    /**
     * @param Emulation $appEmulation Environment emulation, so the store's locale decides the answer
     * @param StoreManagerInterface $storeManager Source of the scope label
     * @param InlineTranslationState $inlineTranslation Inline translation state, switched off around the read
     * @param LoggerInterface $logger Where a failed read is recorded
     */
    public function __construct(
        private readonly Emulation $appEmulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly InlineTranslationState $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     *
     * Claims the origin every directive kind entry carries. Which of those it can really read is
     * decided per reference in resolve(), because the origin says how a value is produced and not
     * which directive asked - and no other reader claims this origin, so nothing is shadowed.
     */
    public function supports(OriginInterface $origin): bool
    {
        return $origin->getKind() === OriginInterface::KIND_DIRECTIVE;
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $templateId
    ): ResolvedValueInterface {
        $message = $entry->getReference()->getExpression();

        if ($entry->getReference()->getKind() !== self::TRANSLATED_KIND || $message === '') {
            // Every other directive kind, and a message the scanner could not read: nothing is
            // claimed rather than something invented.
            return new ResolvedValue();
        }

        try {
            return new ResolvedValue(
                true,
                true,
                $this->render($message, $storeId),
                false,
                $this->scope($storeId),
                $storeId,
                $this->scopeLabel($storeId)
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to read the translation of "' . $message . '": ' . $e->getMessage()
            );

            return new ResolvedValue();
        }
    }

    /**
     * Render the message the way a sent email would
     *
     * @param string $message Message as written in the directive
     * @param int $storeId Store view the message would be sent for
     * @return string The translated message
     */
    private function render(string $message, int $storeId): string
    {
        $emulated = false;

        try {
            if ($storeId > 0) {
                $this->appEmulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
                $emulated = true;
            }

            // Inline translation wraps its output in markup meant for the storefront's editor
            // overlay, which would be shown to the administrator as part of the value.
            $this->inlineTranslation->disable();

            try {
                // Parameters are supplied by the sending code, not by the directive's identity, so
                // the placeholders are left standing. Substituting invented ones would show a
                // message no recipient receives.
                return (string)__($message)->render();
            } finally {
                $this->inlineTranslation->enable();
            }
        } finally {
            if ($emulated) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * Configuration scope the answer was read at
     *
     * @param int $storeId Store view the message would be sent for
     * @return string The scope name
     */
    private function scope(int $storeId): string
    {
        return $storeId > 0 ? ResolvedValueInterface::SCOPE_STORE : ResolvedValueInterface::SCOPE_DEFAULT;
    }

    /**
     * Human name of the scope the answer was read at
     *
     * @param int $storeId Store view the message would be sent for
     * @return string The scope label
     */
    private function scopeLabel(int $storeId): string
    {
        if ($storeId === 0) {
            return (string)__('Default Config');
        }

        try {
            return (string)$this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable $e) {
            return (string)__('Default Config');
        }
    }
}
