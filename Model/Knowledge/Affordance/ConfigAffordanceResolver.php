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
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathWritabilityInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\EditAffordanceResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use Magento\Backend\Model\UrlInterface;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\ElementInterface;

/**
 * Sends the administrator to the configuration page that owns a value, and edits it here when that
 * is safe.
 *
 * The link is built through the URL model rather than assembled as text because an admin URL
 * carries a per-session secret key; a literal URL loses the key and lands on the dashboard, which
 * reads as a broken feature rather than as a missing key. It carries the scope the editor is
 * working in, so the page opens showing the same values the inspector was showing, and the group's
 * own anchor as a fragment, so it opens on the right group rather than at the top of a long section.
 *
 * Where the value may also be written from here, the affordance carries both: the editor is the
 * fast path and the link is the way to the full form, with its validation and its comments.
 */
class ConfigAffordanceResolver implements EditAffordanceResolverInterface
{
    /**
     * Route of the admin configuration page
     */
    private const CONFIG_ROUTE = 'adminhtml/system_config/edit';

    /**
     * How many leading path segments name the section and the group
     */
    private const SCOPE_SEGMENTS = 2;

    /**
     * The input a field's declared type asks for, when it is not a plain single-line one
     *
     * Every path that survives the writability checks today is plain text, so the other entries are
     * there for a field that has yet to become writable rather than for anything in use. A type
     * outside the map gets a single-line input, which is what the configuration form does with an
     * unrecognised type as well.
     *
     * @var array<string, string>
     */
    private const DEFAULT_EDITOR_TYPES = [
        'select' => EditAffordanceInterface::EDITOR_SELECT,
        'textarea' => EditAffordanceInterface::EDITOR_TEXTAREA,
    ];

    /**
     * @param Structure $configStructure Admin configuration structure, asked for the field and the
     *        group behind a configuration path
     * @param UrlInterface $url Admin URL model, so the deep link carries the session's secret key
     * @param ConfigPathWritabilityInterface $writability The one decision about whether a path may
     *        be written, so that an inline editor is never offered where a write would be refused
     * @param array<string, string> $editorTypes Declared field type to the input an inline editor
     *        asks for; anything not listed asks for a single-line input
     */
    public function __construct(
        private readonly Structure $configStructure,
        private readonly UrlInterface $url,
        private readonly ConfigPathWritabilityInterface $writability,
        private readonly array $editorTypes = self::DEFAULT_EDITOR_TYPES
    ) {
    }

    /**
     * @inheritDoc
     *
     * A configuration origin is claimed only when the structure really declares something at its
     * path. Everything this resolver has to offer is built out of that declaration - the section to
     * open, the group to scroll to, the field to edit - and a link to a section that does not exist
     * redirects to the dashboard, which looks like a bug rather than like a missing entry. Standing
     * aside instead leaves the reference to the resolver the pool ends in, which answers with
     * written instructions for finding the setting by hand.
     */
    public function supports(OriginInterface $origin): bool
    {
        return $origin->getKind() === OriginInterface::KIND_CONFIG
            && $this->findDeclaredElement($origin->getLocator()) !== null;
    }

    /**
     * @inheritDoc
     */
    public function resolve(VariableKnowledgeInterface $entry, int $storeId): EditAffordanceInterface
    {
        $path = $entry->getOrigin()->getLocator();
        $url = $this->buildUrl($path, $storeId);
        $field = $this->findWritableField($entry, $storeId);

        if ($field === null) {
            return EditAffordance::link($this->linkLabel($path), $url);
        }

        $editorType = $this->editorTypeFor($field);
        $options = $editorType === EditAffordanceInterface::EDITOR_SELECT ? $this->optionsFor($field) : [];

        // A choice with nothing to choose from is not an editor. When a select field turns out to
        // have no options, the link alone is the honest answer.
        if ($editorType === EditAffordanceInterface::EDITOR_SELECT && $options === []) {
            return EditAffordance::link($this->linkLabel($path), $url);
        }

        return EditAffordance::inline($this->inlineLabel($path, $field), $editorType, $options, $url);
    }

    /**
     * The field an inline editor may be offered for, or null when none may be
     *
     * Both conditions have to hold. The entry's own flag is respected because whoever wrote the
     * entry may know something about the value this resolver does not, and the shared decision is
     * consulted because an entry may equally have been written by hand and be wrong: offering an
     * editor that the write path will refuse is a control that does nothing.
     *
     * @param VariableKnowledgeInterface $entry Entry whose origin this resolver supports
     * @param int $storeId Store view the affordance is asked for
     * @return Field|null
     */
    private function findWritableField(VariableKnowledgeInterface $entry, int $storeId): ?Field
    {
        $path = $entry->getOrigin()->getLocator();

        if (!$entry->isValueWritable() || !$this->writability->evaluate($path, $storeId)->isWritable()) {
            return null;
        }

        $element = $this->findDeclaredElement($path);

        return $element instanceof Field ? $element : null;
    }

    /**
     * The structure element a configuration path names, or null when the structure declares none
     *
     * The structure answers a path it has never heard of with a placeholder element carrying only
     * the id and path it was asked about, never with null, so an unknown path arrives as an element
     * with no label on it.
     *
     * @param string $path Store configuration path
     * @return ElementInterface|null
     */
    private function findDeclaredElement(string $path): ?ElementInterface
    {
        if (count(explode('/', $path)) < self::SCOPE_SEGMENTS) {
            return null;
        }

        $element = $this->configStructure->getElementByConfigPath($path);

        return $element !== null && (string)$element->getLabel() !== '' ? $element : null;
    }

    /**
     * The deep link to the configuration page, opened on the group holding the field
     *
     * The scope is carried as the store id, which is what the configuration page reads it as, and
     * only for a store view: the editor's scope switcher offers store views and "All Store Views",
     * and the latter is the page's own default, so naming it would add a parameter that changes
     * nothing.
     *
     * The fragment is the identifier the configuration form gives the group's collapsible header,
     * which it builds by joining the group's path with underscores.
     *
     * @param string $path Store configuration path
     * @param int $storeId Store view the affordance is asked for; zero is the default scope
     * @return string
     */
    private function buildUrl(string $path, int $storeId): string
    {
        $segments = explode('/', $path);
        $params = ['section' => $segments[0]];

        if (isset($segments[1])) {
            $params['_fragment'] = $segments[0] . '_' . $segments[1] . '-link';
        }

        if ($storeId !== 0) {
            $params['store'] = $storeId;
        }

        return $this->url->getUrl(self::CONFIG_ROUTE, $params);
    }

    /**
     * The label on the link, naming the group the page opens on
     *
     * @param string $path Store configuration path
     * @return string
     */
    private function linkLabel(string $path): string
    {
        $groupPath = implode('/', array_slice(explode('/', $path), 0, self::SCOPE_SEGMENTS));
        $group = $this->findDeclaredElement($groupPath);

        if ($group === null) {
            return (string)__('Open this setting in Configuration');
        }

        return (string)__('Open %1 in Configuration', $group->getLabel());
    }

    /**
     * The label on the inline editor, naming the field it writes
     *
     * @param string $path Store configuration path
     * @param Field $field Field the editor writes
     * @return string
     */
    private function inlineLabel(string $path, Field $field): string
    {
        $label = $field->getLabel();

        return $label === '' ? $path : $label;
    }

    /**
     * The input an inline editor asks for, from the type the field declares
     *
     * @param Field $field Field the editor writes
     * @return string
     */
    private function editorTypeFor(Field $field): string
    {
        return $this->editorTypes[$field->getType()] ?? EditAffordanceInterface::EDITOR_TEXT;
    }

    /**
     * The choices a select field offers, keeping only those that are a plain value and label
     *
     * A configuration source model may return nested groups, which an inline editor has no way to
     * present; those are dropped rather than flattened, because a flattened group loses the only
     * thing that made its members distinguishable.
     *
     * @param Field $field Field the editor writes
     * @return array<int, array{value: string, label: string}>
     */
    private function optionsFor(Field $field): array
    {
        $options = [];

        foreach ($field->getOptions() as $option) {
            if (!is_array($option) || !isset($option['value'], $option['label']) || is_array($option['value'])) {
                continue;
            }

            $options[] = ['value' => (string)$option['value'], 'label' => (string)$option['label']];
        }

        return $options;
    }
}
