<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\WritabilityVerdictInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathReadabilityInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathWritabilityInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\WritabilityVerdict;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Throwable;

/**
 * Decides whether a store configuration path may be written from the email template editor.
 *
 * Editing configuration from an email editor is the sharpest thing this feature does, so the
 * decision is made in one place, by a series of checks each of which has a reason of its own, and
 * every refusal names that reason rather than leaving a control that quietly does nothing.
 *
 * The checks that need no more than the path itself run first; the one that has to build the
 * field's backend model runs last, so it only ever runs for a path everything else has already
 * admitted.
 *
 * What survives all of it, on a stock installation, is the store name, phone number and hours of
 * operation together with the sender name and address of each of the five transactional email
 * identities - and, at the default scope only, the rest of the store's postal address. That is the
 * honest extent of editing configuration from here.
 */
class ConfigPathWritability implements ConfigPathWritabilityInterface
{
    /**
     * Configuration path prefixes that are never written from here, whatever else is true of them
     *
     * This is a blast-radius rule rather than a technical one. The base URL fields validate what
     * they are given perfectly well, but a mistyped base URL takes the storefront off the air, and
     * an email template editor is not where that risk belongs. The deep link to the configuration
     * page covers the case for anybody who really means it.
     */
    private const DEFAULT_BLOCKED_PREFIXES = ['web/'];

    /**
     * Paths whose rendered value is not the value that is stored, and what the difference is
     *
     * The email filter rewrites two of the store information paths before rendering them: the
     * country is replaced by the country's name, always, and the region by the region's name
     * whenever the stored value resolves to a region record. An inline editor pre-filled from what
     * the message shows would therefore store a name where a code or an id belongs, quietly
     * breaking every address the store renders. Neither is offered at any scope.
     *
     * @var array<string, string>
     */
    private const DEFAULT_RENDERED_DIFFERS_FROM_STORED = [
        'general/store_information/country_id' =>
            'This directive does not render the value that is stored: it renders the name of the '
            . 'country the stored country code names.',
        'general/store_information/region_id' =>
            'This directive does not reliably render the value that is stored: it renders the name '
            . 'of the region when the stored value matches a region record, and the stored value '
            . 'itself when it does not.',
    ];

    /**
     * @param Structure $configStructure Admin configuration structure, asked for the field behind a
     *        configuration path. The concrete class is named on purpose: resolving a configuration
     *        path rather than a structure path is getElementByConfigPath(), which the structure
     *        declares and its search interface does not
     * @param ConfigPathReadabilityInterface $readability Whether a {{config}} directive naming the
     *        path renders anything at all. Writability is the stricter question and asks the looser
     *        one first; it does not own the answer, because the value panel asks the looser question
     *        on its own account and two copies of it would drift
     * @param array<string, string> $renderedDiffersFromStored Path to a statement of how what the
     *        directive renders differs from what is stored
     * @param string[] $blockedPathPrefixes Path prefixes that are never written from here
     */
    public function __construct(
        private readonly Structure $configStructure,
        private readonly ConfigPathReadabilityInterface $readability,
        private readonly array $renderedDiffersFromStored = self::DEFAULT_RENDERED_DIFFERS_FROM_STORED,
        private readonly array $blockedPathPrefixes = self::DEFAULT_BLOCKED_PREFIXES
    ) {
    }

    /**
     * @inheritDoc
     */
    public function evaluate(string $path, int $storeId): WritabilityVerdictInterface
    {
        if ($path === '') {
            return WritabilityVerdict::refused(
                (string)__('This directive names no configuration path, so there is no value to change.')
            );
        }

        // A {{config}} directive reads a fixed list of paths and renders anything else as an empty
        // string without a word of complaint. Offering to edit a value the message can never read
        // would be a trap: the administrator would change a setting, see no change in the email,
        // and have nothing to go on. The list is read through the same object the filter reads it
        // through, so the two cannot disagree.
        if (!$this->readability->isReadable($path)) {
            return WritabilityVerdict::refused(
                (string)__(
                    'A {{config}} directive reads only a fixed list of configuration paths, and "%1" '
                    . 'is not on that list, so the directive renders as an empty string.',
                    $path
                )
            );
        }

        foreach ($this->blockedPathPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return WritabilityVerdict::refused(
                    (string)__(
                        'The setting at "%1" is deliberately not editable from the email template '
                        . 'editor, because getting it wrong affects far more than a message.',
                        $path
                    )
                );
            }
        }

        if (isset($this->renderedDiffersFromStored[$path])) {
            return WritabilityVerdict::refused((string)__($this->renderedDiffersFromStored[$path]));
        }

        $field = $this->findField($path);

        // The structure answers a path it has never heard of with a placeholder element carrying
        // only the id and path it was asked about, never with null, so "there is no such field"
        // arrives as a field with nothing on it rather than as an absence.
        if ($field === null) {
            return WritabilityVerdict::refused(
                (string)__(
                    'No field is declared at "%1" in the configuration structure, so no admin page '
                    . 'owns this value.',
                    $path
                )
            );
        }

        if (!$this->isOfferedAtScope($field, $storeId)) {
            return WritabilityVerdict::refused($this->scopeRefusal($path, $storeId));
        }

        return $this->judgeBackendModel($path, $field);
    }

    /**
     * The configuration field behind a path, or null when the structure declares none
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

        // A placeholder carries neither a label nor any of the scope flags; a field the structure
        // really declares carries at least one of them.
        $declared = $element->getLabel() !== ''
            || $element->showInDefault()
            || $element->showInWebsite()
            || $element->showInStore();

        return $declared ? $element : null;
    }

    /**
     * Whether the admin configuration form offers this field at the scope the write is meant for
     *
     * The editor's store switcher offers the default scope and store views, so those are the two
     * scopes a write can name. The check matters because writing a store-scoped row for a field the
     * form only offers at default and website scope is honoured by the scope configuration at
     * render time but never shown by the configuration form at store scope: the administrator ends
     * up with a permanent per-store override they can neither see nor clear.
     *
     * @param Field $field Field behind the path
     * @param int $storeId Store view the write is meant for; zero is the default scope
     * @return bool
     */
    private function isOfferedAtScope(Field $field, int $storeId): bool
    {
        return $storeId === 0 ? $field->showInDefault() : $field->showInStore();
    }

    /**
     * The refusal for a field the form does not offer at the scope the write is meant for
     *
     * @param string $path Store configuration path
     * @param int $storeId Store view the write is meant for; zero is the default scope
     * @return string
     */
    private function scopeRefusal(string $path, int $storeId): string
    {
        if ($storeId === 0) {
            return (string)__(
                'The admin configuration form does not offer "%1" at the default scope, so a value '
                . 'written there would not be shown anywhere it could be corrected.',
                $path
            );
        }

        return (string)__(
            'The admin configuration form does not offer "%1" for a single store view, so a value '
            . 'written for this store view would take effect in messages while staying invisible on '
            . 'the configuration page.',
            $path
        );
    }

    /**
     * Judge the field by the backend model it declares, if it declares one
     *
     * An ordinary backend model is no obstacle. The write goes through the prepared-value path the
     * configuration form itself uses, which runs the backend model, so a value the form would
     * reject is rejected here too. That is the whole reason for taking that route: writing straight
     * to storage would bypass backend models entirely, and this check would then have to exclude
     * every field that declares one - which is most of the ones worth editing.
     *
     * An encrypted field is different in kind. Its stored value is a cipher that must not be echoed
     * into an inspector preview, and an inline text box is not where a credential gets rotated.
     *
     * The model is built rather than compared by name because the declaration may be a virtual
     * type, whose name says nothing about what it extends. If it cannot be built at all the field
     * is refused: not knowing what a backend model does is not a reason to write through it.
     *
     * @param string $path Store configuration path
     * @param Field $field Field behind the path
     * @return WritabilityVerdictInterface
     */
    private function judgeBackendModel(string $path, Field $field): WritabilityVerdictInterface
    {
        if (!$field->hasBackendModel()) {
            return WritabilityVerdict::allowed();
        }

        try {
            $backendModel = $field->getBackendModel();
        } catch (Throwable) {
            return WritabilityVerdict::refused(
                (string)__(
                    'The backend model declared for "%1" cannot be loaded, so there is no way to '
                    . 'tell what writing this value would do.',
                    $path
                )
            );
        }

        if ($backendModel instanceof Encrypted) {
            return WritabilityVerdict::refused(
                (string)__(
                    'The value at "%1" is stored encrypted, so it is neither shown nor changed from '
                    . 'here.',
                    $path
                )
            );
        }

        return WritabilityVerdict::allowed();
    }
}
