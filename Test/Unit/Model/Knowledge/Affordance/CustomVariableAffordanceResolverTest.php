<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Affordance;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\CustomVariableIndexInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Affordance\CustomVariableAffordanceResolver;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Magento\Backend\Model\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomVariableAffordanceResolverTest extends TestCase
{
    private const STORE_ID = 3;

    private CustomVariableIndexInterface&MockObject $customVariableIndex;
    private UrlInterface&MockObject $urlBuilder;
    private CustomVariableAffordanceResolver $resolver;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->customVariableIndex = $this->createMock(CustomVariableIndexInterface::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);

        $this->resolver = new CustomVariableAffordanceResolver($this->customVariableIndex, $this->urlBuilder);
    }

    public function testItAnswersForCustomVariableOrigins(): void
    {
        self::assertTrue($this->resolver->supports(new Origin(OriginInterface::KIND_CUSTOM_VARIABLE)));
    }

    /**
     * @dataProvider otherOriginKindProvider
     *
     * @param string $originKind Kind of origin this resolver is not the one for
     * @return void
     */
    public function testItLeavesEveryOtherOriginToItsOwnResolver(string $originKind): void
    {
        self::assertFalse($this->resolver->supports(new Origin($originKind)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function otherOriginKindProvider(): array
    {
        return [
            'configuration' => [OriginInterface::KIND_CONFIG],
            'a template variable' => [OriginInterface::KIND_TEMPLATE_VAR],
            'design configuration' => [OriginInterface::KIND_DESIGN_CONFIG],
            'something worked out in php' => [OriginInterface::KIND_COMPUTED],
            'a directive shaping the template' => [OriginInterface::KIND_DIRECTIVE],
            'a kind another module invented' => ['third_party_thing'],
        ];
    }

    public function testTheValueIsOfferedAsAnEditorInTheInspector(): void
    {
        $this->customVariableIndex->method('find')
            ->with('support_hours')
            ->willReturn(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours']);
        $this->urlBuilder->method('getUrl')->willReturn('https://example.test/admin/system_variable/edit/');

        $affordance = $this->resolver->resolve($this->entryFor('support_hours'), self::STORE_ID);

        self::assertSame(EditAffordanceInterface::KIND_INLINE, $affordance->getKind());
        self::assertSame(EditAffordanceInterface::EDITOR_TEXTAREA, $affordance->getEditorType());
        self::assertNotSame('', $affordance->getLabel());
    }

    /**
     * The form holds what the inspector cannot: the second value, the name and code, and the choice
     * between a store view's own value and the one shared by all of them. It opens on the scope the
     * inspector is showing, or the administrator would be editing a different value than the one on
     * screen.
     *
     * @return void
     */
    public function testTheFormOwningTheVariableIsLinkedForTheStoreViewBeingWorkedIn(): void
    {
        $this->customVariableIndex->method('find')
            ->willReturn(['id' => 7, 'code' => 'support_hours', 'name' => 'Support hours']);

        $this->urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('adminhtml/system_variable/edit', ['variable_id' => 7, 'store' => self::STORE_ID])
            ->willReturn('https://example.test/admin/system_variable/edit/variable_id/7/store/3/');

        $affordance = $this->resolver->resolve($this->entryFor('support_hours'), self::STORE_ID);

        self::assertSame('https://example.test/admin/system_variable/edit/variable_id/7/store/3/', $affordance->getUrl());
    }

    /**
     * An entry may name a variable that was deleted, or one written by hand before the variable was
     * created. There is no form to open for it, so the administrator is sent where it can be made.
     *
     * @return void
     */
    public function testACodeNoVariableCarriesLeadsToTheListInstead(): void
    {
        $this->customVariableIndex->method('find')->willReturn(null);
        $this->urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('adminhtml/system_variable/index')
            ->willReturn('https://example.test/admin/system_variable/');

        $affordance = $this->resolver->resolve($this->entryFor('no_such_code'), self::STORE_ID);

        self::assertSame(EditAffordanceInterface::KIND_LINK, $affordance->getKind());
        self::assertSame('https://example.test/admin/system_variable/', $affordance->getUrl());
        self::assertNull($affordance->getEditorType());
    }

    /**
     * An entry whose origin names the given custom-variable code
     *
     * @param string $code Custom-variable code the origin points at
     * @return VariableKnowledgeInterface
     */
    private function entryFor(string $code): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('customVar', $code),
            true,
            'A custom variable',
            'Something a merchant wrote.',
            VariableKnowledgeInterface::OUTPUT_HTML,
            new Origin(OriginInterface::KIND_CUSTOM_VARIABLE, $code),
            [],
            null,
            true
        );
    }
}
