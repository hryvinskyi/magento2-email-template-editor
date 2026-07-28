<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Magento\Framework\Exception\AuthorizationException;

/**
 * Decides whether the administrator at the keyboard may change a value at its origin.
 *
 * Being allowed to open the email template editor is not the same as being allowed to change the
 * store's configuration or its custom variables, and it must never become the same thing. The
 * permission that governs a write is the one that governs the place the value is stored: the value
 * is being changed there, in a form that outlives the message, and the fact that the request arrived
 * from a template editor changes nothing about who is entitled to make it.
 *
 * A refusal names the permission that is missing so an administrator can ask for it by name. It does
 * not suggest a way around it, because there isn't one that this screen could offer.
 */
interface WriteAuthorizationInterface
{
    /**
     * Refuse unless the administrator holds every permission that governs this value
     *
     * @param OriginInterface $origin Where the value being changed is stored
     * @return void
     * @throws AuthorizationException When a permission the write requires is missing, or when
     *         nothing has recorded which permission governs this kind of value at all
     */
    public function assertAllowed(OriginInterface $origin): void;
}
