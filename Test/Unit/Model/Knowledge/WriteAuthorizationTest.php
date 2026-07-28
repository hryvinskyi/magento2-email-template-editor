<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\WriteAuthorization;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The permissions a change has to clear before anything is written.
 *
 * Every refusal has a test of its own, because the point of the arrangement is that an administrator
 * is told which permission is missing rather than that the attempt "failed".
 */
class WriteAuthorizationTest extends TestCase
{
    private const CONFIG_RESOURCE = 'Magento_Config::config';
    private const VARIABLE_RESOURCE = 'Magento_Variable::variable';
    private const SECTION_RESOURCE = 'Magento_Config::config_general';
    private const STORE_NAME_PATH = 'general/store_information/name';

    private AuthorizationInterface&MockObject $authorization;

    private Structure&MockObject $configStructure;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->configStructure = $this->createMock(Structure::class);
    }

    /**
     * @return void
     */
    public function testAConfigurationChangeIsAllowedWhenBothPermissionsAreHeld(): void
    {
        $this->structureDeclares(self::STORE_NAME_PATH, 'general', self::SECTION_RESOURCE);
        $this->allows([self::CONFIG_RESOURCE, self::SECTION_RESOURCE]);

        $this->authorizationService()->assertAllowed($this->configOrigin());

        self::assertTrue(true, 'Returning without throwing is the whole of the answer.');
    }

    /**
     * Being allowed to change configuration in general is not being allowed to change this section:
     * roles are normally granted a few sections, and the editor must not be a way round that.
     *
     * @return void
     */
    public function testHoldingTheBroadPermissionWithoutTheSectionsIsRefused(): void
    {
        $this->structureDeclares(self::STORE_NAME_PATH, 'general', self::SECTION_RESOURCE);
        $this->allows([self::CONFIG_RESOURCE]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::SECTION_RESOURCE);

        $this->authorizationService()->assertAllowed($this->configOrigin());
    }

    /**
     * @return void
     */
    public function testLackingTheBroadPermissionIsRefusedWithoutConsultingTheStructure(): void
    {
        $this->allows([self::SECTION_RESOURCE]);
        $this->configStructure->expects(self::never())->method('getElementByConfigPath');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::CONFIG_RESOURCE);

        $this->authorizationService()->assertAllowed($this->configOrigin());
    }

    /**
     * A section declaring no permission of its own adds no requirement, which is the same conclusion
     * the configuration page comes to for such a section.
     *
     * @return void
     */
    public function testASectionDeclaringNoPermissionAddsNoRequirement(): void
    {
        $this->structureDeclares(self::STORE_NAME_PATH, 'general', null);
        $this->allows([self::CONFIG_RESOURCE]);

        $this->authorizationService()->assertAllowed($this->configOrigin());

        self::assertTrue(true, 'Returning without throwing is the whole of the answer.');
    }

    /**
     * A custom variable is governed by its own permission and by nothing in the configuration tree,
     * so the structure is never asked about it.
     *
     * @return void
     */
    public function testACustomVariableNeedsOnlyItsOwnPermission(): void
    {
        $this->allows([self::VARIABLE_RESOURCE]);
        $this->configStructure->expects(self::never())->method('getElementByConfigPath');

        $this->authorizationService()->assertAllowed(
            new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, 'my_code', '')
        );

        self::assertTrue(true, 'Returning without throwing is the whole of the answer.');
    }

    /**
     * @return void
     */
    public function testACustomVariableChangeIsRefusedWithoutTheVariablePermission(): void
    {
        $this->allows([self::CONFIG_RESOURCE]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(self::VARIABLE_RESOURCE);

        $this->authorizationService()->assertAllowed(
            new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, 'my_code', '')
        );
    }

    /**
     * An unmapped kind means nobody has decided what changing such a value would take, and the safe
     * reading of "nobody decided" is no. It is refused before any permission is even consulted.
     *
     * @return void
     */
    public function testAKindNothingHasMappedIsRefusedRatherThanAllowed(): void
    {
        $this->authorization->expects(self::never())->method('isAllowed');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(OriginInterface::KIND_COMPUTED);

        $this->authorizationService()->assertAllowed(
            new Origin(OriginInterface::KIND_COMPUTED, 'Magento\\Store\\Model\\Store::getFrontendName', '')
        );
    }

    /**
     * A permission granted to everything - which is what an administrator with full access holds -
     * is still asked for by name, so the refusals above are refusals of a real answer.
     *
     * @return void
     */
    public function testAPathTheStructureDoesNotDeclareNeedsOnlyTheBroadPermission(): void
    {
        $this->configStructure->method('getElementByConfigPath')->willReturn(null);
        $this->allows([self::CONFIG_RESOURCE]);

        $this->authorizationService()->assertAllowed($this->configOrigin());

        self::assertTrue(true, 'Returning without throwing is the whole of the answer.');
    }

    /**
     * The service under test, wired as the module wires it
     *
     * @return WriteAuthorization
     */
    private function authorizationService(): WriteAuthorization
    {
        return new WriteAuthorization(
            $this->authorization,
            $this->configStructure,
            [
                OriginInterface::KIND_CONFIG => self::CONFIG_RESOURCE,
                OriginInterface::KIND_CUSTOM_VARIABLE => self::VARIABLE_RESOURCE,
            ],
            [OriginInterface::KIND_CONFIG]
        );
    }

    /**
     * A configuration origin pointing at the store name
     *
     * @return OriginInterface
     */
    private function configOrigin(): OriginInterface
    {
        return new Origin(OriginInterface::KIND_CONFIG, self::STORE_NAME_PATH, '');
    }

    /**
     * Teach the authorization mock which permissions the administrator holds
     *
     * @param string[] $resources Permissions held
     * @return void
     */
    private function allows(array $resources): void
    {
        $this->authorization
            ->method('isAllowed')
            ->willReturnCallback(static fn (string $resource): bool => in_array($resource, $resources, true));
    }

    /**
     * Teach the structure mock which field sits at a path and which permission its section declares
     *
     * @param string $path Configuration path
     * @param string $sectionId Section owning that path
     * @param string|null $sectionResource Permission the section declares, null when it declares none
     * @return void
     */
    private function structureDeclares(string $path, string $sectionId, ?string $sectionResource): void
    {
        $field = $this->createMock(Field::class);
        $field->method('getSectionId')->willReturn($sectionId);

        $section = $this->createMock(Section::class);
        $section->method('getData')->willReturn(
            $sectionResource === null ? ['id' => $sectionId] : ['id' => $sectionId, 'resource' => $sectionResource]
        );

        $this->configStructure
            ->method('getElementByConfigPath')
            ->with($path)
            ->willReturn($field);
        $this->configStructure
            ->method('getElement')
            ->with($sectionId)
            ->willReturn($section);
    }
}
