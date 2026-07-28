<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Value;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ResolvedValueInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueStrategyInterface;
use Hryvinskyi\EmailTemplateEditor\Api\MockVariableBuilderPoolInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplateSampleDataMapperInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use Magento\Framework\Filter\Template;
use Magento\Framework\Filter\VariableResolverInterface;

/**
 * Shows what a value handed to the template by the sending code would look like.
 *
 * There is no order, customer or shipment in hand while a template is being edited, so the answer
 * comes from the sample records this module already builds for its preview. It is therefore never
 * exact, and says so: the shape of the output is representative, the content is not, and an
 * administrator who mistook one for the other would be tuning a template against a number no message
 * will ever carry. For the same reason the answer names no scope - it was not read from one, and
 * naming the default scope would be indistinguishable from a value that really does fall back to it.
 *
 * Walking the variable path over the sample records is left to the resolver the template filter
 * itself uses, so that a path resolving here and a path resolving in a message cannot come to mean
 * different things. That resolver reports nothing at all for a path it cannot follow, which is what
 * separates the two answers that must never be confused: a path that led nowhere in the sample data
 * is unavailable, while a path that led to an empty value is available and empty.
 *
 * Building the sample records is not free and a single panel can ask about hundreds of references at
 * once, so the records for a template and store view are built at most once for as long as this
 * instance lives.
 */
class MockValueStrategy implements ReferenceValueStrategyInterface
{
    /**
     * Origin kinds whose values only ever exist while a real message is being sent
     */
    private const SUPPORTED_ORIGIN_KINDS = [
        OriginInterface::KIND_TEMPLATE_VAR,
        OriginInterface::KIND_COMPUTED,
    ];

    /**
     * Sample records already built, by template and store view
     *
     * @var array<string, array<string, mixed>>
     */
    private array $sampleVariables = [];

    /**
     * @param TemplateSampleDataMapperInterface $templateMapper Decides which family of sample
     *        records stands in for a template's real one
     * @param MockVariableBuilderPoolInterface $builderPool Builds that family of sample records
     * @param VariableResolverInterface $variableResolver The resolver the template filter walks
     *        variable paths with, so a path means the same here as it does in a message
     * @param Template $filter The filter the resolver is given as context; it is only consulted for
     *        paths that reach back into a real template, which sample records never are
     */
    public function __construct(
        private readonly TemplateSampleDataMapperInterface $templateMapper,
        private readonly MockVariableBuilderPoolInterface $builderPool,
        private readonly VariableResolverInterface $variableResolver,
        private readonly Template $filter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(OriginInterface $origin): bool
    {
        return in_array($origin->getKind(), self::SUPPORTED_ORIGIN_KINDS, true);
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $templateId
    ): ResolvedValueInterface {
        $path = $entry->getReference()->getExpression();

        if ($path === '') {
            return new ResolvedValue();
        }

        $resolved = $this->variableResolver->resolve(
            $path,
            $this->filter,
            $this->sampleVariablesFor($templateId, $storeId)
        );

        if (!is_scalar($resolved)) {
            // Either the path led nowhere in the sample records, or it led to something that is not
            // a single value. Neither is a claim about what a message would carry, and reporting
            // them as an empty preview would read as one.
            return new ResolvedValue();
        }

        // Available and sample: something was found, and it stands in for a record rather than being
        // one. No scope is named, because nothing here was read from a scope.
        return new ResolvedValue(true, false, (string)$resolved);
    }

    /**
     * The sample records standing in for a template's real one, built at most once
     *
     * @param string $templateId Template the reference was found in
     * @param int $storeId Store view the sample records are built for
     * @return array<string, mixed>
     */
    private function sampleVariablesFor(string $templateId, int $storeId): array
    {
        $key = $templateId . '|' . $storeId;

        if (!isset($this->sampleVariables[$key])) {
            $this->sampleVariables[$key] = $this->builderPool
                ->getBuilder($this->templateMapper->getCategory($templateId))
                ->build($templateId, $storeId);
        }

        return $this->sampleVariables[$key];
    }
}
