<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ModifierDescriptorInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\KnowledgeSerializerInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;

/**
 * The one place the answers this context gives are given their shape.
 *
 * Every field here is read straight off a value object; nothing is worked out, looked up or decided.
 * That is deliberate: the moment this class starts choosing what to say, the endpoints stop being
 * humble and the rule about where knowledge lives has been broken somewhere nobody looks.
 *
 * Two things it does own, and both are about honesty rather than about shape. A key that could not be
 * read as a reference is still answered, in the same shape as every other key, saying plainly that it
 * could not be read - so that a browser is never left to work out for itself which of the keys it
 * asked about went missing. And an entry that somehow arrives without an editing route is described
 * as offering none, in words, rather than as a gap in the answer.
 */
class KnowledgeSerializer implements KnowledgeSerializerInterface
{
    /**
     * @inheritDoc
     */
    public function serializeEntry(
        VariableKnowledgeInterface $entry,
        ResolvedValueInterface $value
    ): array {
        $origin = $entry->getOrigin();

        return [
            'reference' => $entry->getReference()->toCanonicalString(),
            'known' => $entry->isKnown(),
            'title' => $entry->getTitle(),
            'summary' => $entry->getSummary(),
            'outputKind' => $entry->getOutputKind(),
            'defaultModifier' => $entry->getDefaultModifier(),
            'origin' => [
                'kind' => $origin->getKind(),
                'locator' => $origin->getLocator(),
                'explanation' => $origin->getExplanation(),
            ],
            'caveats' => array_values($entry->getCaveats()),
            'affordance' => $this->serializeAffordance($entry->getAffordance()),
            'value' => $this->serializeValue($value),
        ];
    }

    /**
     * @inheritDoc
     */
    public function serializeUnreadableReference(string $reference): array
    {
        return [
            // Echoed exactly as it arrived. It is the only handle the browser has on the directive it
            // asked about, so normalising or shortening it here would answer a question nobody asked.
            'reference' => $reference,
            'known' => false,
            'title' => (string)__('Unrecognised directive'),
            'summary' => (string)__(
                'This directive could not be read as a knowledge base key, so nothing was looked up. '
                . 'Either its kind is not one this editor publishes, or it contains characters a key '
                . 'may not carry.'
            ),
            'outputKind' => VariableKnowledgeInterface::OUTPUT_TEXT,
            'defaultModifier' => null,
            'origin' => [
                'kind' => OriginInterface::KIND_COMPUTED,
                'locator' => '',
                'explanation' => (string)__(
                    'Because the directive could not be read, nothing can be said about where its '
                    . 'value comes from.'
                ),
            ],
            'caveats' => [],
            'affordance' => [
                'kind' => EditAffordanceInterface::KIND_INSTRUCTION,
                'label' => (string)__('What to do about this'),
                'url' => null,
                'steps' => [
                    (string)__(
                        'Check the word immediately after the opening braces: it is the directive '
                        . 'kind, and only the kinds this editor publishes can be looked up.'
                    ),
                    (string)__(
                        'Whether a directive this panel cannot read still renders is decided by the '
                        . 'email template filter, not here, so check it against a preview before '
                        . 'changing it.'
                    ),
                ],
                'editorType' => null,
                'editorOptions' => [],
            ],
            'value' => $this->serializeValue(new ResolvedValue()),
        ];
    }

    /**
     * @inheritDoc
     */
    public function serializeValue(ResolvedValueInterface $value): array
    {
        return [
            'available' => $value->isAvailable(),
            'exact' => $value->isExact(),
            'truncated' => $value->isTruncated(),
            'scope' => $value->getScope(),
            'scopeId' => $value->getScopeId(),
            'scopeLabel' => $value->getScopeLabel(),
            'preview' => $value->getPreview(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function serializeModifiers(array $descriptors): array
    {
        $serialized = [];

        foreach ($descriptors as $descriptor) {
            $serialized[] = $this->serializeModifier($descriptor);
        }

        return $serialized;
    }

    /**
     * One modifier descriptor
     *
     * @param ModifierDescriptorInterface $descriptor Descriptor to describe
     * @return array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     implemented: bool,
     *     arguments: array<int, array{name: string, options: string[], default: string}>
     * }
     */
    private function serializeModifier(ModifierDescriptorInterface $descriptor): array
    {
        return [
            'name' => $descriptor->getName(),
            'label' => $descriptor->getLabel(),
            'description' => $descriptor->getDescription(),
            // Carried across rather than dropped: a name the filter does not run is still worth
            // offering, because templates are written with it, but a panel that presented it as
            // something the filter carries out would be describing an effect nobody implements.
            'implemented' => $descriptor->isImplemented(),
            'arguments' => array_values($descriptor->getArgumentSpec()),
        ];
    }

    /**
     * What the administrator can do about a value, or the plain statement that nothing was worked out
     *
     * @param EditAffordanceInterface|null $affordance Affordance the entry carries, if it carries one
     * @return array{
     *     kind: string,
     *     label: string,
     *     url: string|null,
     *     steps: string[],
     *     editorType: string|null,
     *     editorOptions: array<int, array{value: string, label: string}>
     * }
     */
    private function serializeAffordance(?EditAffordanceInterface $affordance): array
    {
        if ($affordance === null) {
            return [
                'kind' => EditAffordanceInterface::KIND_NONE,
                'label' => (string)__('No route for changing this value was worked out.'),
                'url' => null,
                'steps' => [],
                'editorType' => null,
                'editorOptions' => [],
            ];
        }

        return [
            'kind' => $affordance->getKind(),
            'label' => $affordance->getLabel(),
            'url' => $affordance->getUrl(),
            'steps' => array_values($affordance->getSteps()),
            'editorType' => $affordance->getEditorType(),
            'editorOptions' => array_values($affordance->getEditorOptions()),
        ];
    }
}
