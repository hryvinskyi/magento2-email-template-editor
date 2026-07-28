<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\EditAffordanceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\EditAffordanceResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeRegistryInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use InvalidArgumentException;
use RuntimeException;

/**
 * The knowledge base, assembled from whatever providers are wired into it.
 *
 * Two rules make it predictable. The first is precedence: providers are consulted in the order the
 * pool gives them and the first one that answers wins, so an entry somebody wrote by hand beats one
 * derived from what Magento happens to know. That order is carried by the `sortOrder` attribute on
 * each wired item, not by the order the items appear in the file - array arguments are sorted by
 * `sortOrder` before they reach a constructor. Relying on file order would be wrong twice over:
 * items for the same pool are added in several places and cannot see one another, and once modules
 * are merged the resulting order is module load order rather than anyone's intent, which would break
 * precedence the first time another module contributed a provider. The same rule decides the
 * affordance: a provider that already stated one keeps it, and the affordance pool answers only for
 * an entry that arrived without one.
 *
 * The second is that a reference is always answered. When no provider is the authority on one, the
 * registry returns an entry that says so, describing the directive kind in general and claiming
 * nothing about the reference itself. An administrator cannot tell a description that was invented
 * from one that was researched, so the base admits the gap instead of filling it.
 */
class VariableKnowledgeRegistry implements VariableKnowledgeRegistryInterface
{
    /**
     * The output kind assumed for a reference the base does not document
     *
     * The conservative one: an entry that reports itself unknown must not be the reason a value
     * starts being treated as markup.
     */
    private const UNKNOWN_OUTPUT_KIND = VariableKnowledgeInterface::OUTPUT_TEXT;

    /**
     * @param VariableKnowledgeProviderInterface[] $providers Sources of knowledge, in precedence order
     * @param EditAffordanceResolverInterface[] $affordanceResolvers Affordance strategies, in the
     *        order they are asked, ending in one that supports every origin
     * @param array<string, string> $defaultModifiers Directive kind to the modifier that applies when
     *        a directive of that kind carries no modifier chain at all
     * @throws InvalidArgumentException When either pool is empty or holds something of the wrong type
     */
    public function __construct(
        private readonly array $providers,
        private readonly array $affordanceResolvers,
        private readonly DirectiveReferenceParserInterface $referenceParser,
        private readonly array $defaultModifiers = []
    ) {
        $this->assertPool($providers, VariableKnowledgeProviderInterface::class, 'providers');
        $this->assertPool($affordanceResolvers, EditAffordanceResolverInterface::class, 'affordanceResolvers');
    }

    /**
     * @inheritDoc
     */
    public function describe(DirectiveReferenceInterface $reference, int $storeId): VariableKnowledgeInterface
    {
        $entry = $this->findEntry($reference, $storeId)
            ?? $this->findKindEntry($reference, $storeId)
            ?? $this->buildNotDocumentedEntry($reference);

        return $entry->withAffordance($this->affordanceFor($entry, $storeId));
    }

    /**
     * Describe the directive kind when nothing describes this particular directive
     *
     * Most directives carry an expression that no base could enumerate: every translated message is
     * its own {{trans}}, every block is its own class name. Without this step each of them lands on
     * the not-documented answer even though the base has a good entry for what that kind of
     * directive does, which is most of what an author wants to know.
     *
     * The answer keeps the reference that was asked about, so it still matches the key the caller
     * sent, and it gains a caveat saying it describes the kind rather than this one directive. It is
     * reported as known, because it is: someone wrote it, and it is true of the directive in hand.
     *
     * @param DirectiveReferenceInterface $reference Reference nothing claimed
     * @param int $storeId Store view the description is asked for
     * @return VariableKnowledgeInterface|null The kind's entry, or null when the kind has none either
     */
    private function findKindEntry(
        DirectiveReferenceInterface $reference,
        int $storeId
    ): ?VariableKnowledgeInterface {
        if ($reference->getExpression() === '') {
            return null;
        }

        $entry = $this->findEntry($this->referenceParser->create($reference->getKind(), ''), $storeId);

        if ($entry === null) {
            return null;
        }

        return new VariableKnowledge(
            $reference,
            $entry->isKnown(),
            $entry->getTitle(),
            $entry->getSummary(),
            $entry->getOutputKind(),
            $entry->getOrigin(),
            array_merge(
                $entry->getCaveats(),
                [
                    (string)__(
                        'This describes what the %1 directive does. The knowledge base has no entry '
                        . 'for this particular one.',
                        '{{' . $reference->getKind() . '}}'
                    ),
                ]
            ),
            $entry->getDefaultModifier(),
            // Writability is a statement about one named value, never about a kind of directive.
            false,
            $entry->getAffordance()
        );
    }

    /**
     * @inheritDoc
     */
    public function listAll(int $storeId): array
    {
        $entries = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->listAll($storeId) as $entry) {
                $key = $entry->getReference()->toCanonicalString();

                // A provider consulted later is a provider of lower precedence, so it fills gaps and
                // never replaces what an earlier one already said about the same reference. This is
                // the same precedence a single reference gets, applied to the whole list.
                if (!isset($entries[$key])) {
                    $entries[$key] = $entry->withAffordance($this->affordanceFor($entry, $storeId));
                }
            }
        }

        return $entries;
    }

    /**
     * Ask each provider in turn until one is the authority on the reference
     *
     * @param DirectiveReferenceInterface $reference Reference to describe
     * @param int $storeId Store view the description is asked for
     * @return VariableKnowledgeInterface|null Null when no provider knows the reference
     */
    private function findEntry(
        DirectiveReferenceInterface $reference,
        int $storeId
    ): ?VariableKnowledgeInterface {
        foreach ($this->providers as $provider) {
            $entry = $provider->describe($reference, $storeId);

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The entry for a reference nothing in the base describes
     *
     * Everything it says is either about the directive kind or about the state of the base itself.
     * The default modifier is the one exception, and it is a fact about how the template filter
     * treats a directive of that kind with no modifier chain, not about this reference: without it a
     * chain editor would present an empty chain as "no formatting" when the filter in fact applies
     * some, and an administrator would remove protection they never knew they had.
     *
     * @param DirectiveReferenceInterface $reference Reference nobody documented
     * @return VariableKnowledgeInterface
     */
    private function buildNotDocumentedEntry(DirectiveReferenceInterface $reference): VariableKnowledgeInterface
    {
        $kind = $reference->getKind();
        $caveats = [];

        if ($reference->isOverlong()) {
            // The flag is set only while the reference is still the one built from the document; a
            // canonical string handed back by a browser is already within the limit and arrives
            // without it. So this caveat appears when it can be trusted and is silently absent
            // otherwise - which is the honest way round, since claiming nothing is not a claim.
            $caveats[] = (string)__(
                'This directive is longer than a knowledge base key may be, so it was shortened '
                . 'before being looked up and a matching entry may have been missed.'
            );
        }

        return new VariableKnowledge(
            $reference,
            false,
            (string)__('Undocumented {{%1}} directive', $kind),
            (string)__(
                'The variable knowledge base has no entry for this directive. Nothing here describes '
                . 'what it renders - only that the description is missing.'
            ),
            self::UNKNOWN_OUTPUT_KIND,
            new Origin(
                OriginInterface::KIND_COMPUTED,
                '',
                (string)__(
                    'A {{%1}} directive is expanded by the email template filter while the message is '
                    . 'being rendered. Because the knowledge base has no entry for this one, nothing '
                    . 'more precise can be said about where its value comes from.',
                    $kind
                )
            ),
            $caveats,
            $this->defaultModifiers[$kind] ?? null
        );
    }

    /**
     * The affordance an entry ends up carrying
     *
     * A provider that already decided has the last word, and the pool is asked only for an entry
     * that arrived without one. This is the same precedence the provider pool itself follows, one
     * level down: what somebody stated explicitly beats what was worked out from the origin's kind.
     * Resolving over the top of a stated affordance would be silent rather than wrong-looking - the
     * entry would still show a perfectly plausible link, just not the one it was given - and nothing
     * anywhere would say so.
     *
     * Most entries state nothing, which is the point of the pool: an entry whose origin a resolver
     * understands gets that resolver's deep link without having to repeat it.
     *
     * @param VariableKnowledgeInterface $entry Entry to settle an affordance for
     * @param int $storeId Store view the affordance is asked for
     * @return EditAffordanceInterface
     * @throws RuntimeException When the entry states none and no resolver claims its origin
     */
    private function affordanceFor(
        VariableKnowledgeInterface $entry,
        int $storeId
    ): EditAffordanceInterface {
        return $entry->getAffordance() ?? $this->resolveAffordance($entry, $storeId);
    }

    /**
     * Choose the affordance for an entry that states none
     *
     * @param VariableKnowledgeInterface $entry Entry to resolve an affordance for
     * @param int $storeId Store view the affordance is asked for
     * @return EditAffordanceInterface
     * @throws RuntimeException When no resolver claims the entry's origin
     */
    private function resolveAffordance(
        VariableKnowledgeInterface $entry,
        int $storeId
    ): EditAffordanceInterface {
        foreach ($this->affordanceResolvers as $resolver) {
            if ($resolver->supports($entry->getOrigin())) {
                return $resolver->resolve($entry, $storeId);
            }
        }

        // Reachable only from a pool that lost its catch-all. Falling through to an affordance that
        // says nothing would hide the wiring gap behind an entry that merely looks unhelpful.
        throw new RuntimeException(
            sprintf(
                'No edit affordance resolver claims an origin of kind "%s". The resolver pool must '
                . 'end in one that supports every origin.',
                $entry->getOrigin()->getKind()
            )
        );
    }

    /**
     * Refuse a pool that is empty or holds something that cannot play its part
     *
     * A pool arrives from merged configuration, and a merge that dropped or mistyped an entry would
     * otherwise show up as a base that quietly knows nothing at all.
     *
     * @param array<array-key, mixed> $pool Pool as wired
     * @param string $expectedInterface Interface every member has to implement
     * @param string $argumentName Name of the constructor argument, so the message points at the wiring
     * @return void
     * @throws InvalidArgumentException When the pool is empty or a member is of the wrong type
     */
    private function assertPool(array $pool, string $expectedInterface, string $argumentName): void
    {
        if ($pool === []) {
            throw new InvalidArgumentException(
                sprintf(
                    'The "%s" pool of the variable knowledge registry is empty; it must hold at least '
                    . 'one %s.',
                    $argumentName,
                    $expectedInterface
                )
            );
        }

        foreach ($pool as $key => $member) {
            if (!$member instanceof $expectedInterface) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Entry "%s" of the "%s" pool of the variable knowledge registry must implement '
                        . '%s, got %s.',
                        (string)$key,
                        $argumentName,
                        $expectedInterface,
                        get_debug_type($member)
                    )
                );
            }
        }
    }
}
