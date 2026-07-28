<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Affordance;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\CustomVariableIndexInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\EditAffordanceResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use Magento\Backend\Model\UrlInterface;

/**
 * What an administrator can do about a custom variable: edit it here, or open the form that owns it.
 *
 * The value is one field a merchant typed, so it can be written from the inspector without leaving
 * the template. The link to the variable's own form goes along with it all the same, because the
 * form holds things the inspector cannot: the second value, the name and code, and the choice
 * between a store view's own value and the one shared by all of them. The link carries the store
 * view being worked in, so the form opens on the same scope the inspector is showing.
 *
 * Offering the editor is a statement about the shape of the value, not a decision that the write
 * will be accepted. That decision is taken again on the server from the reference alone.
 */
class CustomVariableAffordanceResolver implements EditAffordanceResolverInterface
{
    /**
     * Admin route of the form owning one custom variable
     */
    private const EDIT_ROUTE = 'adminhtml/system_variable/edit';

    /**
     * Admin route listing the custom variables
     */
    private const LIST_ROUTE = 'adminhtml/system_variable/index';

    /**
     * @param CustomVariableIndexInterface $customVariableIndex Translates a code into the variable's identifier
     * @param UrlInterface $urlBuilder Builds the admin links
     */
    public function __construct(
        private readonly CustomVariableIndexInterface $customVariableIndex,
        private readonly UrlInterface $urlBuilder
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
    public function resolve(VariableKnowledgeInterface $entry, int $storeId): EditAffordanceInterface
    {
        $variable = $this->customVariableIndex->find($entry->getOrigin()->getLocator());

        if ($variable === null) {
            // An entry naming a custom variable that no longer exists, or one written by hand
            // ahead of the variable being created. There is no form to open for it, so the
            // administrator is sent to the list, where it can be created.
            return EditAffordance::link(
                (string)__('Open Custom Variables'),
                $this->urlBuilder->getUrl(self::LIST_ROUTE)
            );
        }

        return EditAffordance::inline(
            (string)__('Value of this custom variable'),
            EditAffordanceInterface::EDITOR_TEXTAREA,
            [],
            $this->urlBuilder->getUrl(
                self::EDIT_ROUTE,
                ['variable_id' => $variable['id'], 'store' => $storeId]
            )
        );
    }
}
