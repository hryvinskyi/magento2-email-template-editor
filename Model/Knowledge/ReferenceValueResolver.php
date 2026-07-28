<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueStrategyInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single way in for "what does this render as right now".
 *
 * It owns three things the strategies deliberately do not.
 *
 * Precedence: strategies are asked in the order the pool gives them and the first one that supports
 * the entry's origin answers. That order is carried by the `sortOrder` attribute on each wired item
 * and never by where the item sits in a file, because items for one pool are written in several
 * places that cannot see one another and, once modules are merged, file order is module load order
 * rather than anyone's intent.
 *
 * One length for every answer: a preview is cut here, so two references never come back shortened
 * differently because two strategies each picked their own limit, and the truncation flag means the
 * same thing wherever it appears.
 *
 * Nothing escapes: a strategy that throws is recorded and the answer becomes "nothing could be
 * read". The value block sits inside a panel describing a directive, and a sample builder falling
 * over on one reference must not cost the administrator the description of every other one.
 */
class ReferenceValueResolver implements ReferenceValueResolverInterface
{
    /**
     * How many characters of a value are worth showing in a panel beside a description
     */
    private const DEFAULT_PREVIEW_LIMIT = 500;

    /**
     * @param ReferenceValueStrategyInterface[] $strategies Readers, in the order they are asked,
     *        ending in one that supports every origin
     * @param LoggerInterface $logger Where a strategy that fails is recorded
     * @param int $previewLimit How many characters of a value survive into the preview
     * @throws InvalidArgumentException When the pool is empty, holds something of the wrong type, or
     *         the preview limit leaves no room for a value
     */
    public function __construct(
        private readonly array $strategies,
        private readonly LoggerInterface $logger,
        private readonly int $previewLimit = self::DEFAULT_PREVIEW_LIMIT
    ) {
        $this->assertPool($strategies);

        if ($previewLimit < 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'The preview limit of the reference value resolver must be at least one '
                    . 'character, got %d.',
                    $previewLimit
                )
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $templateId
    ): ResolvedValueInterface {
        foreach ($this->strategies as $strategy) {
            if (!$strategy->supports($entry->getOrigin())) {
                continue;
            }

            try {
                $value = $strategy->resolve($entry, $storeId, $templateId);
            } catch (Throwable $exception) {
                $this->logger->error(
                    sprintf(
                        'Reading the current value of "%s" through %s failed; the inspector will '
                        . 'report that no value could be read.',
                        $entry->getReference()->toCanonicalString(),
                        get_debug_type($strategy)
                    ),
                    ['exception' => $exception]
                );

                return new ResolvedValue();
            }

            return $this->shortened($value);
        }

        // Reachable only from a pool that lost the member supporting every origin. Reporting it and
        // answering "nothing could be read" keeps the wiring gap in the log where it can be found,
        // without a panel full of descriptions failing over one missing reader.
        $this->logger->error(
            sprintf(
                'No reference value strategy claims an origin of kind "%s". The strategy pool must '
                . 'end in one that supports every origin.',
                $entry->getOrigin()->getKind()
            )
        );

        return new ResolvedValue();
    }

    /**
     * The same value with its preview cut to the one length every answer is cut to
     *
     * @param ResolvedValueInterface $value Value as a strategy produced it, with the whole preview
     * @return ResolvedValueInterface The value itself when it already fits
     */
    private function shortened(ResolvedValueInterface $value): ResolvedValueInterface
    {
        $preview = $value->getPreview();

        if (mb_strlen($preview, 'UTF-8') <= $this->previewLimit) {
            return $value;
        }

        // Cut by characters rather than bytes: half of a multi-byte character is not a character,
        // and the browser would render the remainder as a replacement glyph in the middle of what
        // is supposed to be a faithful preview.
        return new ResolvedValue(
            $value->isAvailable(),
            $value->isExact(),
            mb_substr($preview, 0, $this->previewLimit, 'UTF-8'),
            true,
            $value->getScope(),
            $value->getScopeId(),
            $value->getScopeLabel()
        );
    }

    /**
     * Refuse a pool that is empty or holds something that cannot read a value
     *
     * A pool arrives from merged configuration, and a merge that dropped or mistyped an entry would
     * otherwise show up as an inspector that never has a value for anything.
     *
     * @param array<array-key, mixed> $pool Pool as wired
     * @return void
     * @throws InvalidArgumentException When the pool is empty or a member is of the wrong type
     */
    private function assertPool(array $pool): void
    {
        if ($pool === []) {
            throw new InvalidArgumentException(
                sprintf(
                    'The strategy pool of the reference value resolver is empty; it must hold at '
                    . 'least one %s.',
                    ReferenceValueStrategyInterface::class
                )
            );
        }

        foreach ($pool as $key => $member) {
            if (!$member instanceof ReferenceValueStrategyInterface) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Entry "%s" of the strategy pool of the reference value resolver must '
                        . 'implement %s, got %s.',
                        (string)$key,
                        ReferenceValueStrategyInterface::class,
                        get_debug_type($member)
                    )
                );
            }
        }
    }
}
