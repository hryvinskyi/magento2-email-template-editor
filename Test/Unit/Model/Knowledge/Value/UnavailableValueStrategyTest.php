<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Model\Knowledge\Value;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Value\UnavailableValueStrategy;
use PHPUnit\Framework\TestCase;

class UnavailableValueStrategyTest extends TestCase
{
    private const STORE_ID = 3;
    private const TEMPLATE_ID = 'sales_email_order_template';

    /**
     * The pool ends in this one, so it has to claim everything - including origin kinds another
     * module invented and has no reader for yet.
     *
     * @dataProvider originKindProvider
     * @param string $kind Origin kind to offer it
     * @return void
     */
    public function testItClaimsEveryOrigin(string $kind): void
    {
        self::assertTrue((new UnavailableValueStrategy())->supports(new Origin($kind, 'anything', '')));
    }

    /**
     * Every published origin kind, plus one nothing here has ever heard of
     *
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
            'contributed elsewhere' => ['something_another_module_invented'],
        ];
    }

    /**
     * It reports "no value", never an empty value: the two are different answers.
     *
     * @return void
     */
    public function testItReportsThatNothingCouldBeReadAndClaimsNothingElse(): void
    {
        $value = (new UnavailableValueStrategy())->resolve($this->entry(), self::STORE_ID, self::TEMPLATE_ID);

        self::assertFalse($value->isAvailable());
        self::assertFalse($value->isExact());
        self::assertSame('', $value->getPreview());
        self::assertFalse($value->isTruncated());
        self::assertSame('', $value->getScope());
        self::assertSame(0, $value->getScopeId());
        self::assertSame('', $value->getScopeLabel());
    }

    /**
     * An entry whose origin nothing more specific reads
     *
     * @return VariableKnowledgeInterface
     */
    private function entry(): VariableKnowledgeInterface
    {
        return new VariableKnowledge(
            new DirectiveReference('block', 'Magento\\Framework\\View\\Element\\Template'),
            false,
            'Undocumented {{block}} directive',
            'The knowledge base has no entry for this directive.',
            VariableKnowledgeInterface::OUTPUT_TEXT,
            new Origin(OriginInterface::KIND_COMPUTED, '', 'Expanded while the message renders.')
        );
    }
}
