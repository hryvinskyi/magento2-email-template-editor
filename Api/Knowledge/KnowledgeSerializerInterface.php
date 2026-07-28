<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ModifierDescriptorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;

/**
 * Turns the knowledge base's value objects into the plain arrays the browser is answered with.
 *
 * It exists so that the shape of an answer is written down once. Two endpoints hand back a value
 * block and the browser reads the same field names in both; were each endpoint to assemble its own
 * arrays, the two would drift apart one field at a time and the second one to drift would look, from
 * the browser, like a value that stopped arriving rather than like a name that changed.
 *
 * It is a mapper and nothing else: it reads the objects it is given and writes field names. It looks
 * nothing up, asks nobody anything and decides nothing about what an administrator may do - which is
 * what keeps the endpoints that use it free of knowledge of their own.
 */
interface KnowledgeSerializerInterface
{
    /**
     * One knowledge entry together with the value behind it
     *
     * The reference reported is the base's own spelling of the key rather than the spelling the
     * browser asked with. The two differ whenever a directive was written in a form that normalises -
     * a quoted parameter, an unusual capitalisation - and the reported one is the spelling that
     * identifies the entry, so it is the one to ask with next time.
     *
     * @param VariableKnowledgeInterface $entry Entry to describe
     * @param ResolvedValueInterface $value Value behind the entry, as it stands now
     * @return array{
     *     reference: string,
     *     known: bool,
     *     title: string,
     *     summary: string,
     *     outputKind: string,
     *     defaultModifier: string|null,
     *     origin: array{kind: string, locator: string, explanation: string},
     *     caveats: string[],
     *     affordance: array{
     *         kind: string,
     *         label: string,
     *         url: string|null,
     *         steps: string[],
     *         editorType: string|null,
     *         editorOptions: array<int, array{value: string, label: string}>
     *     },
     *     value: array{
     *         available: bool,
     *         exact: bool,
     *         truncated: bool,
     *         scope: string,
     *         scopeId: int,
     *         scopeLabel: string,
     *         preview: string
     *     }
     * }
     */
    public function serializeEntry(
        VariableKnowledgeInterface $entry,
        ResolvedValueInterface $value
    ): array;

    /**
     * The same shape for a key that could not be read as a reference at all
     *
     * A directive whose key the grammar refuses cannot be looked up, so there is no entry and no
     * value to report. It still has to be answered, in the shape every other key is answered in: a
     * browser that asked about a hundred directives and got back ninety-nine has no way to tell which
     * one was refused, or that anything was refused at all.
     *
     * @param string $reference Key exactly as the browser sent it, echoed back so it can be matched
     * @return array{
     *     reference: string,
     *     known: bool,
     *     title: string,
     *     summary: string,
     *     outputKind: string,
     *     defaultModifier: string|null,
     *     origin: array{kind: string, locator: string, explanation: string},
     *     caveats: string[],
     *     affordance: array{
     *         kind: string,
     *         label: string,
     *         url: string|null,
     *         steps: string[],
     *         editorType: string|null,
     *         editorOptions: array<int, array{value: string, label: string}>
     *     },
     *     value: array{
     *         available: bool,
     *         exact: bool,
     *         truncated: bool,
     *         scope: string,
     *         scopeId: int,
     *         scopeLabel: string,
     *         preview: string
     *     }
     * }
     */
    public function serializeUnreadableReference(string $reference): array;

    /**
     * One resolved value on its own
     *
     * The block a change is answered with, and the same block that sits inside an entry. The scope it
     * carries is the scope the value was read from, which is what lets an administrator check where a
     * change they just made actually landed.
     *
     * @param ResolvedValueInterface $value Value to describe
     * @return array{
     *     available: bool,
     *     exact: bool,
     *     truncated: bool,
     *     scope: string,
     *     scopeId: int,
     *     scopeLabel: string,
     *     preview: string
     * }
     */
    public function serializeValue(ResolvedValueInterface $value): array;

    /**
     * The published modifier vocabulary, in the order it is offered
     *
     * @param ModifierDescriptorInterface[] $descriptors Descriptors as the registry publishes them
     * @return array<int, array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     implemented: bool,
     *     arguments: array<int, array{name: string, options: string[], default: string}>
     * }>
     */
    public function serializeModifiers(array $descriptors): array;
}
