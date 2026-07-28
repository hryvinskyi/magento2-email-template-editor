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
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Affordance\InstructionAffordanceResolver;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use PHPUnit\Framework\TestCase;

class InstructionAffordanceResolverTest extends TestCase
{
    private InstructionAffordanceResolver $resolver;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->resolver = new InstructionAffordanceResolver();
    }

    /**
     * It is the member the pool ends in, and a last member that could decline would leave entries
     * with no affordance at all.
     *
     * @dataProvider originKindProvider
     *
     * @param string $originKind Kind of origin to offer the resolver
     * @return void
     */
    public function testItSupportsEveryOrigin(string $originKind): void
    {
        self::assertTrue($this->resolver->supports(new Origin($originKind)));
    }

    /**
     * @dataProvider originKindProvider
     *
     * @param string $originKind Kind of origin to resolve an affordance for
     * @return void
     */
    public function testEveryOriginGetsInstructionsWithAtLeastOneStep(string $originKind): void
    {
        $affordance = $this->resolver->resolve($this->entryWithOrigin($originKind), 1);

        self::assertSame(EditAffordanceInterface::KIND_INSTRUCTION, $affordance->getKind());
        self::assertNotSame('', $affordance->getLabel());
        self::assertNotEmpty($affordance->getSteps());
        self::assertNull($affordance->getUrl());
        self::assertNull($affordance->getEditorType());
    }

    /**
     * Origin kinds arrive from other modules too, and one this class has never heard of has to come
     * back with steps rather than with an error or an empty panel.
     *
     * @return void
     */
    public function testAnUnknownOriginKindStillGetsUsableSteps(): void
    {
        $affordance = $this->resolver->resolve($this->entryWithOrigin('third_party_thing'), 1);

        self::assertSame(EditAffordanceInterface::KIND_INSTRUCTION, $affordance->getKind());
        self::assertNotEmpty($affordance->getSteps());
    }

    public function testTheStepsDifferByOriginKind(): void
    {
        $config = $this->resolver->resolve($this->entryWithOrigin(OriginInterface::KIND_CONFIG), 1);
        $customVariable = $this->resolver->resolve(
            $this->entryWithOrigin(OriginInterface::KIND_CUSTOM_VARIABLE),
            1
        );

        self::assertNotSame($config->getSteps(), $customVariable->getSteps());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function originKindProvider(): array
    {
        return [
            'config' => [OriginInterface::KIND_CONFIG],
            'custom variable' => [OriginInterface::KIND_CUSTOM_VARIABLE],
            'template var' => [OriginInterface::KIND_TEMPLATE_VAR],
            'design config' => [OriginInterface::KIND_DESIGN_CONFIG],
            'computed' => [OriginInterface::KIND_COMPUTED],
            'directive' => [OriginInterface::KIND_DIRECTIVE],
        ];
    }

    /**
     * @param string $originKind Kind of origin the entry carries
     * @return VariableKnowledgeInterface
     */
    private function entryWithOrigin(string $originKind): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('var', 'store.getFormattedAddress()'),
            true,
            'Store address block',
            'The store postal address.',
            VariableKnowledgeInterface::OUTPUT_HTML,
            new Origin($originKind, 'general/store_information')
        );
    }
}
