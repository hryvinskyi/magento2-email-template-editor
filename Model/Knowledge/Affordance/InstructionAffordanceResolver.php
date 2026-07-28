<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Affordance;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\EditAffordanceResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;

/**
 * The resolver of last resort: written instructions for an origin nothing more specific claimed.
 *
 * It supports every origin and is wired to be asked last, which is what lets the registry promise
 * that an entry always comes back with an affordance. A resolver that can build a deep link or an
 * inline editor is asked first and answers instead; this one takes what is left, including origin
 * kinds contributed by other modules that have no resolver of their own yet.
 *
 * What it does never varies - it always returns instructions. Only the wording is looked up, from a
 * table keyed by origin kind with a fallback, so an origin kind this class has never heard of still
 * gets steps an administrator can act on rather than an error or an empty panel.
 */
class InstructionAffordanceResolver implements EditAffordanceResolverInterface
{
    /**
     * @inheritDoc
     *
     * Always true. This is the resolver the pool ends in, and a pool whose last member could decline
     * would leave entries with no affordance at all.
     */
    public function supports(OriginInterface $origin): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function resolve(VariableKnowledgeInterface $entry, int $storeId): EditAffordanceInterface
    {
        return EditAffordance::instruction(
            (string)__('How to change this'),
            $this->stepsFor($entry->getOrigin()->getKind())
        );
    }

    /**
     * The steps for an origin kind, or generic ones when the kind is not in the table
     *
     * @param string $originKind Kind of the entry's origin
     * @return string[]
     */
    private function stepsFor(string $originKind): array
    {
        $steps = [
            OriginInterface::KIND_CONFIG => [
                (string)__('Open Stores > Settings > Configuration and find the field at this configuration path.'),
                (string)__(
                    'Switch to the scope the message is sent in before editing: a store view value '
                    . 'overrides the website value, which overrides the default.'
                ),
                (string)__('Save the configuration, then flush the cache so the change reaches the next message.'),
            ],
            OriginInterface::KIND_CUSTOM_VARIABLE => [
                (string)__('Open System > Other Settings > Custom Variables.'),
                (string)__('Open the variable carrying this code, or create it if no variable carries it yet.'),
                (string)__(
                    'Fill in both values: an HTML message uses the HTML value and falls back to the '
                    . 'plain one only while the HTML value is empty.'
                ),
            ],
            OriginInterface::KIND_TEMPLATE_VAR => [
                (string)__('This value is handed to the template by the code that sends the message, not by a setting.'),
                (string)__(
                    'It only has a value in the templates that code sends; in any other template it '
                    . 'renders as nothing at all.'
                ),
                (string)__('To change what it contains, change the record the message is about.'),
            ],
            OriginInterface::KIND_DESIGN_CONFIG => [
                (string)__('Open Content > Design > Configuration and edit the scope the message is sent in.'),
                (string)__('Change the value in the Transactional Emails section and save.'),
            ],
            OriginInterface::KIND_COMPUTED => [
                (string)__('This value is worked out in PHP while the message renders, so no single setting holds it.'),
                (string)__('Change the data it is worked out from, or edit the template to render something else.'),
            ],
            OriginInterface::KIND_DIRECTIVE => [
                (string)__('This directive shapes the template rather than producing a value of its own.'),
                (string)__('Edit the template content to change what it wraps or when it is shown.'),
            ],
        ];

        return $steps[$originKind] ?? [
            (string)__('The knowledge base records no route for changing this value.'),
            (string)__('Find out where the value comes from before changing anything the message depends on.'),
        ];
    }
}
