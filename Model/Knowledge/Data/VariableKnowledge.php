<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;

/**
 * An immutable knowledge entry.
 *
 * A provider builds one without an affordance and the registry hands back a copy carrying the one
 * it resolved, so the entry stays immutable while the rule for choosing an affordance stays in a
 * single place. Instances are created with `new`, not through a factory - they are plain values
 * with no dependencies.
 */
class VariableKnowledge implements VariableKnowledgeInterface
{
    /**
     * @param DirectiveReferenceInterface $reference Reference this entry describes
     * @param bool $known Whether the base really has a record of that reference
     * @param string $title Short name for the value
     * @param string $summary A sentence or two on what the value is
     * @param string $outputKind What sort of output the reference produces
     * @param OriginInterface $origin Where the value comes from and how it is produced
     * @param string[] $caveats Things that are true about the reference and easy to get wrong
     * @param string|null $defaultModifier Modifier applying when there is no chain at all
     * @param bool $valueWritable Whether the value may be written back, as a hint to the browser
     * @param EditAffordanceInterface|null $affordance What the administrator can do about the value
     */
    public function __construct(
        private readonly DirectiveReferenceInterface $reference,
        private readonly bool $known,
        private readonly string $title,
        private readonly string $summary,
        private readonly string $outputKind,
        private readonly OriginInterface $origin,
        private readonly array $caveats = [],
        private readonly ?string $defaultModifier = null,
        private readonly bool $valueWritable = false,
        private readonly ?EditAffordanceInterface $affordance = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getReference(): DirectiveReferenceInterface
    {
        return $this->reference;
    }

    /**
     * @inheritDoc
     */
    public function isKnown(): bool
    {
        return $this->known;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @inheritDoc
     */
    public function getSummary(): string
    {
        return $this->summary;
    }

    /**
     * @inheritDoc
     */
    public function getOutputKind(): string
    {
        return $this->outputKind;
    }

    /**
     * @inheritDoc
     */
    public function getOrigin(): OriginInterface
    {
        return $this->origin;
    }

    /**
     * @inheritDoc
     */
    public function getCaveats(): array
    {
        return $this->caveats;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultModifier(): ?string
    {
        return $this->defaultModifier;
    }

    /**
     * @inheritDoc
     */
    public function isValueWritable(): bool
    {
        return $this->valueWritable;
    }

    /**
     * @inheritDoc
     */
    public function getAffordance(): ?EditAffordanceInterface
    {
        return $this->affordance;
    }

    /**
     * @inheritDoc
     */
    public function withAffordance(EditAffordanceInterface $affordance): VariableKnowledgeInterface
    {
        return new self(
            $this->reference,
            $this->known,
            $this->title,
            $this->summary,
            $this->outputKind,
            $this->origin,
            $this->caveats,
            $this->defaultModifier,
            $this->valueWritable,
            $affordance
        );
    }
}
