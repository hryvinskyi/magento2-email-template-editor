<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge;

/**
 * What an administrator can actually do about the value behind a directive.
 *
 * There are four honest answers - follow a link to the page that owns the value, edit it in place,
 * follow written instructions because no single page owns it, or nothing - and the kind says which
 * one this is. The fields that do not apply to a kind are empty rather than absent, so a consumer
 * reads the same shape whatever the kind.
 *
 * An inline affordance is only ever a hint to the browser. The decision to accept a write is taken
 * again on the server, from the reference alone, so a client that asks for a write the affordance
 * never offered is refused all the same.
 *
 * Implementations are immutable values.
 */
interface EditAffordanceInterface
{
    /**
     * The value is owned by an admin page; the affordance carries a deep link to it
     */
    public const KIND_LINK = 'link';

    /**
     * The value may be written from the inspector itself
     */
    public const KIND_INLINE = 'inline';

    /**
     * No single page owns the value; the affordance carries written steps instead
     */
    public const KIND_INSTRUCTION = 'instruction';

    /**
     * Nothing can be done about the value from the admin at all
     */
    public const KIND_NONE = 'none';

    /**
     * A single-line text input
     */
    public const EDITOR_TEXT = 'text';

    /**
     * A multi-line text input
     */
    public const EDITOR_TEXTAREA = 'textarea';

    /**
     * A choice among the affordance's own options
     */
    public const EDITOR_SELECT = 'select';

    /**
     * The kind of affordance
     *
     * One of the published kinds this interface declares.
     *
     * @return string
     */
    public function getKind(): string;

    /**
     * The label shown on the affordance
     *
     * Always present, whatever the kind: it is the only part of an affordance that is guaranteed to
     * have something to say.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * The page that owns the value, or null when the affordance offers none
     *
     * It is the whole of a link affordance. An inline one may carry it as well, for everything
     * editing a single value in place cannot reach: an inline editor writes one value in one scope,
     * while the page that owns the value shows the rest of it and the scopes it is stored in.
     *
     * @return string|null
     */
    public function getUrl(): ?string;

    /**
     * The ordered steps of an instruction affordance, empty for every other kind
     *
     * @return string[]
     */
    public function getSteps(): array;

    /**
     * The input an inline affordance asks for, or null for every other kind
     *
     * One of the published editor types this interface declares.
     *
     * @return string|null
     */
    public function getEditorType(): ?string;

    /**
     * The choices offered by an inline select editor, empty for every other editor type and kind
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getEditorOptions(): array;
}
