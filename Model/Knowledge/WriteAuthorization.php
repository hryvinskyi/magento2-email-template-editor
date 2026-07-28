<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\WriteAuthorizationInterface;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;

/**
 * Requires, for every change, the permissions that govern the place the value is stored.
 *
 * Which permission governs which kind of origin is wired rather than written here, so a kind
 * contributed by another module brings its own answer with it. A kind nothing has answered for is
 * refused: an unmapped kind does not mean the write is harmless, it means nobody has yet decided
 * what it would take to be allowed, and the safe reading of "nobody decided" is no. Defaulting the
 * other way would make every future origin kind writable by whoever can open the editor, silently,
 * on the day it is added.
 *
 * Store configuration needs a second permission and it is the one that matters most. The
 * configuration permission is a single resource covering the whole of the configuration tree, while
 * every section of that tree declares a resource of its own, and a role is normally granted a few
 * sections rather than all of them. Asking only the broad one would let a role that cannot open a
 * section on the configuration page change one of its values through here instead - the editor
 * becoming a way round the permission rather than a place it applies.
 */
class WriteAuthorization implements WriteAuthorizationInterface
{
    /**
     * @param AuthorizationInterface $authorization What the administrator at the keyboard may do
     * @param Structure $configStructure Admin configuration structure, asked which section owns a
     *        configuration path and which permission that section declares. The concrete class is
     *        named on purpose: resolving a configuration path rather than a structure path is
     *        getElementByConfigPath(), which the structure declares and its search interface does not
     * @param array<string, string> $resourcesByOriginKind Origin kind to the permission that governs
     *        values stored there. A kind that is absent is refused rather than allowed
     * @param string[] $configPathKinds Origin kinds whose locator is a store configuration path, and
     *        which therefore additionally need the permission of the section owning that path. This
     *        is wired rather than tested for in code so that a kind storing its values in the
     *        configuration tree can be contributed without changing anything here
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly Structure $configStructure,
        private readonly array $resourcesByOriginKind = [],
        private readonly array $configPathKinds = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function assertAllowed(OriginInterface $origin): void
    {
        $kind = $origin->getKind();
        $resource = (string)($this->resourcesByOriginKind[$kind] ?? '');

        if ($resource === '') {
            throw new AuthorizationException(
                __(
                    'Nothing records which permission governs values of the "%1" kind, so changing '
                    . 'one from the email template editor is refused.',
                    $kind
                )
            );
        }

        $this->requirePermission($resource);

        $sectionResource = $this->sectionResource($origin);

        if ($sectionResource !== '') {
            $this->requirePermission($sectionResource);
        }
    }

    /**
     * Refuse unless the administrator holds a permission
     *
     * @param string $resource Permission the change requires
     * @return void
     * @throws AuthorizationException When the administrator does not hold it
     */
    private function requirePermission(string $resource): void
    {
        if ($this->authorization->isAllowed($resource)) {
            return;
        }

        throw new AuthorizationException(
            __(
                'Changing this value requires the "%1" permission, which your account does not hold.',
                $resource
            )
        );
    }

    /**
     * The permission declared by the configuration section that owns this value, if any
     *
     * A section that declares no permission of its own adds no requirement; the broad configuration
     * permission is then the whole of what the write needs, which is the same conclusion the
     * configuration page comes to for that section.
     *
     * @param OriginInterface $origin Where the value being changed is stored
     * @return string Empty when this origin is not a configuration path, or its section declares no
     *         permission
     */
    private function sectionResource(OriginInterface $origin): string
    {
        if (!in_array($origin->getKind(), $this->configPathKinds, true)) {
            return '';
        }

        $field = $this->configStructure->getElementByConfigPath($origin->getLocator());

        if (!$field instanceof Field) {
            return '';
        }

        // The section is asked for by the field's own reckoning rather than by chopping the path up
        // here, because a field may declare a configuration path that differs from where it sits in
        // the structure, and it is where it sits that decides which section owns it.
        $section = $this->configStructure->getElement($field->getSectionId());

        if (!$section instanceof Section) {
            return '';
        }

        $data = $section->getData();

        return isset($data['resource']) ? (string)$data['resource'] : '';
    }
}
