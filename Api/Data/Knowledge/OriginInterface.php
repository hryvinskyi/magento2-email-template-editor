<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge;

/**
 * Where the value behind a directive actually comes from, and how it is produced.
 *
 * The origin is what every downstream decision keys off: which editing route the admin is offered,
 * how the current value is read, and whether it may be written back. Those decisions are taken by
 * pooled strategies that each answer `supports()` for the origins they understand, never by a
 * conditional over the kind - so a new origin kind is a new strategy plus one wiring entry, and it
 * can be contributed from another module without touching anything here.
 *
 * Implementations are immutable values.
 */
interface OriginInterface
{
    /**
     * A store configuration path, read through the scope config at render time
     */
    public const KIND_CONFIG = 'config';

    /**
     * A merchant-authored custom variable, looked up by its code
     */
    public const KIND_CUSTOM_VARIABLE = 'custom_variable';

    /**
     * A value the sending code assigns to the template before it is rendered
     */
    public const KIND_TEMPLATE_VAR = 'template_var';

    /**
     * A value held by the design configuration rather than by a plain config field
     */
    public const KIND_DESIGN_CONFIG = 'design_config';

    /**
     * A value produced in PHP while the email renders, with no stored field behind it
     */
    public const KIND_COMPUTED = 'computed';

    /**
     * A directive that shapes the template rather than producing a value of its own
     */
    public const KIND_DIRECTIVE = 'directive';

    /**
     * The kind of origin
     *
     * One of the published kinds this interface declares. It selects which strategy handles the
     * reference, so a value outside the published set is simply one no strategy claims.
     *
     * @return string
     */
    public function getKind(): string;

    /**
     * What identifies the value inside its origin
     *
     * A configuration path, a custom-variable code, a class-and-method pair - whatever the kind's
     * strategies need in order to find the value again. It is empty when the kind carries no such
     * handle.
     *
     * @return string
     */
    public function getLocator(): string;

    /**
     * Prose describing how the value is produced
     *
     * Written for an administrator reading the inspector, not for a developer reading a stack
     * trace, and never speculative: an origin that is not actually known says so.
     *
     * @return string
     */
    public function getExplanation(): string;
}
