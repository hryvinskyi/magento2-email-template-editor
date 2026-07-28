<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use InvalidArgumentException;

/**
 * An immutable edit affordance, reachable only through the four named constructors.
 *
 * The constructor is private because the kinds are not interchangeable shapes over one set of
 * fields: a link without a target and an inline editor without an input type are not affordances at
 * all, they are buttons that do nothing when an administrator presses them. Each named constructor
 * takes exactly what its kind needs and refuses anything less, so an incomplete affordance fails
 * where it is built rather than in the browser.
 *
 * Instances are created through those constructors, not through a factory - they are plain values
 * with no dependencies.
 */
class EditAffordance implements EditAffordanceInterface
{
    /**
     * The input types an inline editor may ask for
     */
    private const EDITOR_TYPES = [self::EDITOR_TEXT, self::EDITOR_TEXTAREA, self::EDITOR_SELECT];

    /**
     * @param string $kind Affordance kind, one of those published on the interface
     * @param string $label Label shown on the affordance
     * @param string|null $url Target of a link affordance
     * @param string[] $steps Ordered steps of an instruction affordance
     * @param string|null $editorType Input an inline affordance asks for
     * @param array<int, array{value: string, label: string}> $editorOptions Choices of a select editor
     */
    private function __construct(
        private readonly string $kind,
        private readonly string $label,
        private readonly ?string $url = null,
        private readonly array $steps = [],
        private readonly ?string $editorType = null,
        private readonly array $editorOptions = []
    ) {
    }

    /**
     * An affordance that sends the administrator to the admin page owning the value
     *
     * @param string $label Label shown on the link
     * @param string $url Target of the link
     * @return self
     * @throws InvalidArgumentException When the label or the target is empty
     */
    public static function link(string $label, string $url): self
    {
        self::assertLabel($label);

        if (trim($url) === '') {
            throw new InvalidArgumentException('A link edit affordance must carry a target URL.');
        }

        return new self(self::KIND_LINK, $label, $url);
    }

    /**
     * An affordance that writes the value from the inspector itself
     *
     * @param string $label Label shown on the editor
     * @param string $editorType Input to ask for, one of those published on the interface
     * @param array<int, array{value: string, label: string}> $editorOptions Choices, for a select
     * @param string|null $url Page that owns the value, for everything the inline editor cannot do
     * @return self
     * @throws InvalidArgumentException When the label is empty, the input type is not a published
     *                                 one, a select editor is offered without any choices, or a
     *                                 page link is offered without a target
     */
    public static function inline(
        string $label,
        string $editorType,
        array $editorOptions = [],
        ?string $url = null
    ): self {
        self::assertLabel($label);

        if (!in_array($editorType, self::EDITOR_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'An inline edit affordance must ask for one of the input types "%s", got "%s".',
                    implode('", "', self::EDITOR_TYPES),
                    $editorType
                )
            );
        }

        if ($editorType === self::EDITOR_SELECT && $editorOptions === []) {
            throw new InvalidArgumentException(
                'An inline edit affordance offering a choice must carry the choices to offer.'
            );
        }

        if ($url !== null && trim($url) === '') {
            throw new InvalidArgumentException(
                'An inline edit affordance offering the page that owns the value must carry its URL.'
            );
        }

        return new self(self::KIND_INLINE, $label, $url, [], $editorType, $editorOptions);
    }

    /**
     * An affordance that tells the administrator what to do, because no single page owns the value
     *
     * @param string $label Label introducing the steps
     * @param string[] $steps Ordered steps, at least one
     * @return self
     * @throws InvalidArgumentException When the label is empty or there are no steps
     */
    public static function instruction(string $label, array $steps): self
    {
        self::assertLabel($label);

        if ($steps === []) {
            throw new InvalidArgumentException('An instruction edit affordance must carry at least one step.');
        }

        return new self(self::KIND_INSTRUCTION, $label, null, array_values($steps));
    }

    /**
     * An affordance that states there is nothing to be done about the value
     *
     * It still carries a label, because "nothing" is an answer the administrator is owed in words
     * rather than an empty space where an action would have been.
     *
     * @param string $label Label saying why there is nothing to do
     * @return self
     * @throws InvalidArgumentException When the label is empty
     */
    public static function none(string $label): self
    {
        self::assertLabel($label);

        return new self(self::KIND_NONE, $label);
    }

    /**
     * @inheritDoc
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritDoc
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @inheritDoc
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @inheritDoc
     */
    public function getEditorType(): ?string
    {
        return $this->editorType;
    }

    /**
     * @inheritDoc
     */
    public function getEditorOptions(): array
    {
        return $this->editorOptions;
    }

    /**
     * Refuse an affordance with nothing written on it
     *
     * @param string $label Label to check
     * @return void
     * @throws InvalidArgumentException When the label is empty or only whitespace
     */
    private static function assertLabel(string $label): void
    {
        if (trim($label) === '') {
            throw new InvalidArgumentException('An edit affordance must carry a label.');
        }
    }
}
