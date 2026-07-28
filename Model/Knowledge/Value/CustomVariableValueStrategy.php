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
use Magento\Store\Model\StoreManagerInterface;
use Magento\Variable\Model\Variable;
use Magento\Variable\Model\VariableFactory;

/**
 * Reads a merchant-authored custom variable the way an HTML message will read it.
 *
 * The variable is loaded by its code in the scope the editor is working in, exactly as the email
 * filter loads it, and the HTML form of the value is what comes back, because that is the form an
 * HTML message carries and this editor edits HTML messages.
 *
 * The choice of form is left to the variable itself rather than worked out here. A variable whose
 * HTML value is empty does not fall back to its plain value untouched: the plain value is escaped
 * and its newlines become line breaks first, so a plain value containing an ampersand or a second
 * line reaches the message looking different from how it is stored. Deciding the fallback here
 * instead would show an administrator something no message produces - the failure this whole panel
 * exists to prevent - so the model is asked and its answer is passed on unchanged.
 *
 * A code no variable carries renders as nothing at all, and that is what the answer then says. It is
 * the knowledge entry beside the value, not the value itself, that reports whether a variable with
 * this code exists.
 */
class CustomVariableValueStrategy implements ReferenceValueStrategyInterface
{
    /**
     * @param VariableFactory $variableFactory Builds the variable model the email filter also uses
     * @param StoreManagerInterface $storeManager Names the scope the value was read in
     */
    public function __construct(
        private readonly VariableFactory $variableFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(OriginInterface $origin): bool
    {
        return $origin->getKind() === OriginInterface::KIND_CUSTOM_VARIABLE;
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $templateId
    ): ResolvedValueInterface {
        $code = $entry->getOrigin()->getLocator();

        if ($code === '') {
            return new ResolvedValue();
        }

        $variable = $this->variableFactory->create()->setStoreId($storeId)->loadByCode($code);

        // Store view zero is what the editor's switcher calls "All Store Views", so the value read
        // through it is reported as the default configuration rather than under a store's name.
        $isDefaultScope = $storeId === 0;

        return new ResolvedValue(
            true,
            true,
            (string)$variable->getValue(Variable::TYPE_HTML),
            false,
            $isDefaultScope ? ResolvedValueInterface::SCOPE_DEFAULT : ResolvedValueInterface::SCOPE_STORE,
            $isDefaultScope ? 0 : $storeId,
            $isDefaultScope
                ? (string)__('Default Config')
                : (string)$this->storeManager->getStore($storeId)->getName()
        );
    }
}
