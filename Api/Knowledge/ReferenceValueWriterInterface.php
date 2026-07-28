<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;

/**
 * The single way in for changing the value a directive reads, at the place it is read from.
 *
 * This is the one thing in the whole context that leaves something behind. Everything else answers
 * questions; this changes a store's configuration or a merchant's variable from a screen whose
 * ostensible job is editing the text of an email. So nothing that arrives from the browser is taken
 * on trust here: not the claim that a value is editable, not the store view the change is meant for,
 * and not the idea that the administrator who may open this editor may also change what it points
 * at. Each of those is decided again, on the server, from the reference alone.
 *
 * The answer is the value as it stands after the change, together with the scope it was written in.
 * Saying which scope a change will land in before making it is a promise; saying which scope it
 * landed in afterwards is a fact the administrator can check against the configuration page, and the
 * difference between the two is the whole reason the scope comes back.
 */
interface ReferenceValueWriterInterface
{
    /**
     * Change the value behind a reference and report what it now is, and where
     *
     * @param VariableKnowledgeInterface $entry Entry for the reference, resolved on the server from
     *        the reference itself rather than accepted from the browser
     * @param int $storeId Store view the change is meant for; zero is the default scope
     * @param string $value Value as the administrator typed it
     * @return ResolvedValueInterface The value as it stands after the change, naming the scope the
     *         change was written in
     * @throws AuthorizationException When a permission the change requires is missing
     * @throws LocalizedException When the value may not be changed from here, when the store view
     *         does not exist, or when the new value is not one its origin will accept
     */
    public function write(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $value
    ): ResolvedValueInterface;
}
