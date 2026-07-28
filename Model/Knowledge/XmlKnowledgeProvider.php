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
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\EditAffordance;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\Origin;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\VariableKnowledge;
use Magento\Framework\Config\DataInterface;
use Magento\Framework\UrlInterface;

/**
 * The knowledge somebody wrote down, read from the merged email_variables.xml.
 *
 * It is the source of highest precedence, which is the whole point of it: everything else in the
 * base is derived from what Magento happens to expose, and derivation cannot know why a value
 * matters or what an administrator will get wrong about it. Any module may add entries by shipping
 * an email_variables.xml of its own, and nothing here has to change for that to work.
 *
 * A link is stored as a route and its parameters, never as a finished URL. An admin URL carries a
 * key that belongs to the session it was built in, so a URL written into a configuration file is
 * dead before anybody follows it, and every one of them would have to be rewritten if a route
 * changed. Building it here also lets the link carry the store view the editor is working in, so
 * that a page which shows a different value per scope opens on the scope the message is sent in.
 */
class XmlKnowledgeProvider implements VariableKnowledgeProviderInterface
{
    /**
     * The store id standing for "all store views", which admin pages open on without being told to
     */
    private const DEFAULT_STORE_ID = 0;

    /**
     * Route parameter naming the store view a scope-aware admin page should open on
     *
     * The editor's scope switcher lists store views, so a store view is the only scope a link out of
     * it can name. A website-scoped link is not offered: it would need a website id, and inventing
     * one from a store id here would be a second opinion about scope in a feature that already has
     * one.
     */
    private const SCOPE_PARAM = 'store';

    /**
     * Route parameter the URL builder turns into the fragment of the generated URL
     */
    private const FRAGMENT_PARAM = '_fragment';

    /**
     * @param DataInterface $knowledgeConfig The merged email_variables.xml, keyed by canonical reference
     * @param UrlInterface $url Builds the admin URL behind a link affordance
     * @param array<string, string> $defaultModifiers Directive kind to the modifier that applies when
     *        a directive of that kind carries no modifier chain at all. An entry has to carry it
     *        whichever part of the base built the entry, because a chain editor that presented an
     *        empty chain as "no formatting" would have an administrator remove protection the
     *        template filter was applying without being asked.
     */
    public function __construct(
        private readonly DataInterface $knowledgeConfig,
        private readonly UrlInterface $url,
        private readonly array $defaultModifiers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function describe(DirectiveReferenceInterface $reference, int $storeId): ?VariableKnowledgeInterface
    {
        $definition = $this->definitions()[$reference->toCanonicalString()] ?? null;

        if ($definition === null) {
            return null;
        }

        // Built around the reference that was asked about rather than around a fresh one, so that
        // what the entry reports about the lookup - a truncated key, in particular - stays true.
        return $this->buildEntry($reference, $definition, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function listAll(int $storeId): array
    {
        $entries = [];

        foreach ($this->definitions() as $definition) {
            $entries[] = $this->buildEntry(
                new DirectiveReference($definition['kind'], $definition['expression']),
                $definition,
                $storeId
            );
        }

        return $entries;
    }

    /**
     * Every entry that was written down, keyed by canonical reference
     *
     * The whole array is fetched at once because the framework splits a lookup path on slashes, and
     * these keys carry slashes of their own whenever they name a configuration path.
     *
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        $definitions = $this->knowledgeConfig->get(null, []);

        return is_array($definitions) ? $definitions : [];
    }

    /**
     * Build the entry for one written definition
     *
     * @param DirectiveReferenceInterface $reference Reference the entry describes
     * @param array<string, mixed> $definition Definition as the converter produced it
     * @param int $storeId Store view the entry is asked for
     * @return VariableKnowledgeInterface
     */
    private function buildEntry(
        DirectiveReferenceInterface $reference,
        array $definition,
        int $storeId
    ): VariableKnowledgeInterface {
        $affordance = $this->buildAffordance($definition['affordance'], $storeId);

        // The prose is translated here because there is nowhere later that it could be: what the
        // serializer hands the browser is a string, and the browser binds it as text. A phrase is
        // looked up by its value, so passing a variable works at run time - it is only the phrase
        // collector that cannot see through it, which is why this module's dictionary is swept out
        // of the source rather than generated.
        return new VariableKnowledge(
            $reference,
            true,
            (string)__($definition['title']),
            (string)__($definition['summary']),
            $definition['outputKind'],
            new Origin(
                $definition['origin']['kind'],
                $definition['origin']['locator'],
                (string)__($definition['origin']['explanation'])
            ),
            array_map(static fn (string $caveat): string => (string)__($caveat), $definition['caveats']),
            $this->defaultModifiers[$reference->getKind()] ?? null,
            // Writability is claimed only where an editor was offered, and even then it is a hint the
            // browser may act on: the server decides every write again from the reference alone, so a
            // client that asks for one this never offered is refused rather than obeyed.
            $affordance !== null && $affordance->getKind() === EditAffordanceInterface::KIND_INLINE,
            $affordance
        );
    }

    /**
     * Build the affordance a definition declares
     *
     * Which parts an affordance is built from follows from its kind, and the way to build each kind
     * is looked up rather than decided by a conditional over the kind, so a kind added later is a new
     * row here and nothing else.
     *
     * @param array<string, mixed>|null $affordance Affordance as the converter produced it
     * @param int $storeId Store view the affordance is asked for
     * @return EditAffordanceInterface|null Null when the definition declares none
     */
    private function buildAffordance(?array $affordance, int $storeId): ?EditAffordanceInterface
    {
        if ($affordance === null) {
            return null;
        }

        $builders = [
            EditAffordanceInterface::KIND_LINK => fn (array $declared): EditAffordanceInterface =>
                EditAffordance::link((string)__($declared['label']), $this->buildUrl($declared, $storeId)),
            // An editor may also name the page that owns the value, for everything editing one field
            // from a popover cannot do - the other fields beside it, the history of the change, the
            // scopes it is not being written at.
            EditAffordanceInterface::KIND_INLINE => fn (array $declared): EditAffordanceInterface =>
                EditAffordance::inline(
                    (string)__($declared['label']),
                    $declared['editorType'],
                    $declared['options'],
                    $declared['route'] === '' ? null : $this->buildUrl($declared, $storeId)
                ),
            EditAffordanceInterface::KIND_INSTRUCTION => static fn (array $declared): EditAffordanceInterface =>
                EditAffordance::instruction(
                    (string)__($declared['label']),
                    array_map(static fn (string $step): string => (string)__($step), $declared['steps'])
                ),
            EditAffordanceInterface::KIND_NONE => static fn (array $declared): EditAffordanceInterface =>
                EditAffordance::none((string)__($declared['label'])),
        ];

        $builder = $builders[$affordance['kind']] ?? null;

        return $builder === null ? null : $builder($affordance);
    }

    /**
     * Build the admin URL a link affordance points at
     *
     * @param array<string, mixed> $affordance Link affordance as the converter produced it
     * @param int $storeId Store view the editor is working in
     * @return string
     */
    private function buildUrl(array $affordance, int $storeId): string
    {
        $params = $affordance['params'];

        if ($affordance['scopeAware'] === true && $storeId !== self::DEFAULT_STORE_ID) {
            $params[self::SCOPE_PARAM] = (string)$storeId;
        }

        if ($affordance['fragment'] !== '') {
            $params[self::FRAGMENT_PARAM] = $affordance['fragment'];
        }

        return $this->url->getUrl($affordance['route'], $params);
    }
}
