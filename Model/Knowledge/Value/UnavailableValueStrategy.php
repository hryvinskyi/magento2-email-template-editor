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

/**
 * The reader of last resort: it reports that nothing could be read.
 *
 * It supports every origin and is wired to be asked last, which is what lets the facade promise that
 * an answer always comes back. A reader that can really produce a value is asked first and answers
 * instead; this one takes what is left, including origin kinds contributed by other modules that
 * have no reader of their own yet.
 *
 * What it reports is "no value", never an empty value. The two are different answers and an
 * administrator has to be able to tell them apart: one says the message will carry nothing, the
 * other says nobody here knows what the message will carry. Reporting the second as the first would
 * have a template debugged against a claim nothing ever checked.
 */
class UnavailableValueStrategy implements ReferenceValueStrategyInterface
{
    /**
     * @inheritDoc
     *
     * Always true. This is the reader the pool ends in, and a pool whose last member could decline
     * would leave references with no answer at all.
     */
    public function supports(OriginInterface $origin): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $templateId
    ): ResolvedValueInterface {
        // Unavailable, not exact, no preview and no scope: nothing was read, so nothing is claimed.
        return new ResolvedValue();
    }
}
