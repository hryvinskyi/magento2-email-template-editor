<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathWritabilityInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Variable\Model\Source\Variables as ConfigVariables;

/**
 * What the knowledge base can work out about a {{config}} directive from Magento itself.
 *
 * Its first job is the honest refusal. A {{config}} directive may name any path an author cares to
 * type, but the email filter reads only a fixed list of them and renders everything else as an
 * empty string, silently - so the most valuable thing to say about most paths is that they will
 * never render. Its second job, for the paths that do render, is to fetch the label and comment the
 * administrator already knows the field by from the admin configuration structure, so that the
 * inspector and the configuration page call the same setting the same thing.
 *
 * Nothing here is invented. Every entry is assembled from the configuration structure and the
 * filter's own list of readable paths, which is why even the entry for a path nothing declares
 * reports itself as known: it is a checked finding, not a gap in the base.
 */
class ConfigPathKnowledgeProvider implements VariableKnowledgeProviderInterface
{
    /**
     * The directive kind this provider is the authority on
     */
    private const KIND = 'config';

    /**
     * @param Structure $configStructure Admin configuration structure, asked for the field, group
     *        and section behind a configuration path. The concrete class is named on purpose:
     *        resolving a configuration path rather than a structure path is
     *        getElementByConfigPath(), which the structure declares and its search interface does
     *        not, and the two are not interchangeable - only the former follows a field that
     *        declares a configuration path of its own
     * @param ConfigVariables $configVariables Source of the paths a {{config}} directive may read
     * @param ConfigPathWritabilityInterface $writability The one decision about whether a path may
     *        be written, shared with the side that accepts writes so the two cannot disagree
     * @param DirectiveReferenceParserInterface $referenceParser Builds the reference for each path
     *        this provider lists, through the same rules an in-document directive goes through
     */
    public function __construct(
        private readonly Structure $configStructure,
        private readonly ConfigVariables $configVariables,
        private readonly ConfigPathWritabilityInterface $writability,
        private readonly DirectiveReferenceParserInterface $referenceParser
    ) {
    }

    /**
     * @inheritDoc
     */
    public function describe(DirectiveReferenceInterface $reference, int $storeId): ?VariableKnowledgeInterface
    {
        if ($reference->getKind() !== self::KIND || $reference->getExpression() === '') {
            return null;
        }

        $path = $reference->getExpression();
        $field = $this->findField($path);
        $verdict = $this->writability->evaluate($path, $storeId);

        return new VariableKnowledge(
            $reference,
            true,
            $field === null ? $path : $field->getLabel(),
            $this->summarise($path, $field),
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_CONFIG, $path, $this->explain($path, $field)),
            // The refusal is the caveat. Restating it in wording of this provider's own would give
            // the administrator one sentence before the attempt and a different one after it, and
            // the two would drift apart the first time either was edited.
            $verdict->isWritable() ? [] : [$verdict->getReason()],
            null,
            $verdict->isWritable()
        );
    }

    /**
     * @inheritDoc
     *
     * Only the paths the email filter can actually read are listed. Listing every field the admin
     * configuration declares would be some thousands of entries, all but a couple of dozen of which
     * render as an empty string, and a catalogue that is almost entirely things that do not work is
     * worse than no catalogue.
     */
    public function listAll(int $storeId): array
    {
        $entries = [];

        foreach ($this->configVariables->getAvailableVars() as $path) {
            $entry = $this->describe($this->referenceParser->create(self::KIND, (string)$path), $storeId);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The configuration field behind a path, or null when the structure declares none
     *
     * The structure answers a path it has never heard of with a placeholder element carrying only
     * the id and path it was asked about, never with null, so an unknown path arrives as an element
     * with nothing on it. A field the structure really declares carries at least a label or one of
     * the scope flags.
     *
     * @param string $path Store configuration path
     * @return Field|null
     */
    private function findField(string $path): ?Field
    {
        $element = $this->configStructure->getElementByConfigPath($path);

        if (!$element instanceof Field) {
            return null;
        }

        $declared = $element->getLabel() !== ''
            || $element->showInDefault()
            || $element->showInWebsite()
            || $element->showInStore();

        return $declared ? $element : null;
    }

    /**
     * A sentence or two on what the value is
     *
     * The field's own comment is used when it has one, because it is the wording the administrator
     * has already read on the configuration page and repeating it keeps the two consistent.
     *
     * @param string $path Store configuration path
     * @param Field|null $field Field behind the path, null when the structure declares none
     * @return string
     */
    private function summarise(string $path, ?Field $field): string
    {
        if ($field === null) {
            return (string)__(
                'The configuration structure declares no field at "%1", so nothing in the admin '
                . 'owns this value and nothing here can say what it would contain.',
                $path
            );
        }

        $comment = trim(strip_tags($field->getComment()));

        if ($comment !== '') {
            return $comment;
        }

        return (string)__(
            'The value of the "%1" configuration field, read for the store view the message is sent '
            . 'in.',
            $field->getLabel()
        );
    }

    /**
     * Prose describing how the value is produced
     *
     * @param string $path Store configuration path
     * @param Field|null $field Field behind the path, null when the structure declares none
     * @return string
     */
    private function explain(string $path, ?Field $field): string
    {
        if ($field === null) {
            return (string)__(
                'The directive reads the store configuration at "%1" while the message is being '
                . 'rendered. No field is declared at that path, so the value can only have been put '
                . 'there by something outside the admin.',
                $path
            );
        }

        return (string)__(
            'The directive reads the store configuration at "%1" - %2 - while the message is being '
            . 'rendered, for the store view it is sent in, so a store view value overrides the '
            . 'website value, which overrides the default.',
            $path,
            $this->breadcrumb($path, $field)
        );
    }

    /**
     * The section, group and field labels an administrator would follow to reach the field
     *
     * @param string $path Store configuration path
     * @param Field $field Field behind the path
     * @return string
     */
    private function breadcrumb(string $path, Field $field): string
    {
        $labels = [];
        $walked = [];

        // The section and the group are resolved the same way the field was, one path segment at a
        // time, because a label is the only thing either of them is needed for here.
        foreach (array_slice(explode('/', $path), 0, 2) as $segment) {
            $walked[] = $segment;
            $element = $this->configStructure->getElementByConfigPath(implode('/', $walked));
            $label = $element === null ? '' : (string)$element->getLabel();

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $labels[] = $field->getLabel();

        return implode(' > ', $labels);
    }
}
