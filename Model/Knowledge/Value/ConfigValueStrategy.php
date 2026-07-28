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
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathReadabilityInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueStrategyInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\ResolvedValue;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Information;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;

/**
 * Reads a store configuration value the way the message will read it.
 *
 * The value is exact, and it is read in the scope the editor is working in, so the answer states
 * which scope it came from and an administrator can check that a change landed where it was meant
 * to. Store view zero is the scope the admin calls "All Store Views", so it is reported as the
 * default configuration rather than under a store's name.
 *
 * Two configuration paths are rewritten by the email filter on their way into the message, and the
 * two rewrites are not the same shape. The country of the store information group is replaced
 * unconditionally by the country's *name*, so the stored two-letter code never reaches a message and
 * an unset country renders as nothing at all. The region is replaced by the region's name only when
 * a region record resolves; when none does, the stored numeric identifier is what renders. Reading
 * the configuration and showing what came back would therefore be wrong twice over, in two different
 * ways, and an administrator would be tuning a template against values no message contains. Both
 * rewrites are reproduced here.
 *
 * Working out the region and country names costs two record loads, and a single panel can ask about
 * hundreds of references at once. The answer is therefore worked out at most once per store for as
 * long as this instance lives, and only for the two paths that need it - the other paths in the
 * group are read straight from the configuration and are unaffected by it.
 *
 * One directive is answered with no value at all rather than with what is stored: a {{config}}
 * naming a path outside the fixed list the email filter reads. Such a directive renders an empty
 * string whatever the configuration holds, so showing the stored value beside it would be the very
 * failure this panel exists to prevent - an administrator tuning a template against a value no
 * message will ever carry, with the panel itself as the source of the mistake. The list is a
 * property of that directive and of nothing else, so a value that lives in the configuration but
 * reaches a message some other way is read normally.
 */
class ConfigValueStrategy implements ReferenceValueStrategyInterface
{
    /**
     * The directive whose readable paths are a fixed list, spelled as the reference grammar spells it
     */
    private const CONFIG_DIRECTIVE_KIND = 'config';

    /**
     * Store information already worked out, by store view
     *
     * @var array<int, DataObject>
     */
    private array $storeInformationByStore = [];

    /**
     * @param ScopeConfigInterface $scopeConfig Reads configuration in the scope the message reads it
     * @param StoreManagerInterface $storeManager Resolves the scope the value is being read for
     * @param Information $storeInformation The same collaborator the email filter uses to turn the
     *        stored country and region identifiers into the names a message carries, so that the
     *        two cannot answer differently
     * @param ConfigPathReadabilityInterface $readability Whether a {{config}} directive naming the
     *        path renders the stored value or renders nothing
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Information $storeInformation,
        private readonly ConfigPathReadabilityInterface $readability
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(OriginInterface $origin): bool
    {
        return $origin->getKind() === OriginInterface::KIND_CONFIG;
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        VariableKnowledgeInterface $entry,
        int $storeId,
        string $templateId
    ): ResolvedValueInterface {
        $path = $entry->getOrigin()->getLocator();

        if ($path === '') {
            return new ResolvedValue();
        }

        // The directive's own list, applied to the directive that has one. The knowledge entry
        // beside this value already carries the sentence explaining why nothing renders, so
        // reporting that no value could be read leaves the panel saying one consistent thing
        // instead of a caveat and a value that contradict each other.
        if ($entry->getReference()->getKind() === self::CONFIG_DIRECTIVE_KIND
            && !$this->readability->isReadable($path)
        ) {
            return new ResolvedValue();
        }

        $store = $this->storeManager->getStore($storeId);
        $stored = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        if (is_array($stored)) {
            // The locator names a whole group of fields rather than one of them, so there is no
            // single value to show. Saying so beats showing the first field of the group as though
            // it were the answer.
            return new ResolvedValue();
        }

        // Store view zero is what the editor's switcher calls "All Store Views": the configuration
        // is still read through it, but naming it after a store would tell the administrator the
        // change they are about to make lands somewhere narrower than it does.
        $isDefaultScope = $storeId === 0;

        return new ResolvedValue(
            true,
            true,
            (string)$this->rendered($path, $stored, $store),
            false,
            $isDefaultScope ? ResolvedValueInterface::SCOPE_DEFAULT : ResolvedValueInterface::SCOPE_STORE,
            $isDefaultScope ? 0 : $storeId,
            $isDefaultScope ? (string)__('Default Config') : (string)$store->getName()
        );
    }

    /**
     * What the stored value becomes on its way into a message
     *
     * @param string $path Configuration path being read
     * @param mixed $stored Value as the configuration holds it
     * @param StoreInterface $store Store view the value is being read for
     * @return mixed What a message would carry
     */
    private function rendered(string $path, mixed $stored, StoreInterface $store): mixed
    {
        if ($path === Information::XML_PATH_STORE_INFO_COUNTRY_CODE) {
            // Unconditional: the stored country code never reaches a message, and a store with no
            // country set renders as nothing rather than as a code.
            return $this->storeInformationFor($store)->getData('country');
        }

        if ($path === Information::XML_PATH_STORE_INFO_REGION_CODE) {
            $regionName = $this->storeInformationFor($store)->getData('region');

            // Conditional, and deliberately the same loose test the filter applies: when no region
            // record resolves there is no name to show and the stored identifier is what renders.
            return $regionName ? $regionName : $stored;
        }

        return $stored;
    }

    /**
     * The store information for a store view, worked out at most once
     *
     * @param StoreInterface $store Store view to describe
     * @return DataObject
     * @throws RuntimeException When the store manager hands back something that cannot be described
     */
    private function storeInformationFor(StoreInterface $store): DataObject
    {
        $storeId = (int)$store->getId();

        if (!isset($this->storeInformationByStore[$storeId])) {
            if (!$store instanceof Store) {
                // The service the email filter uses to name a country and a region takes the store
                // model itself, so anything else cannot be asked the question at all. Refusing means
                // the caller reports that no value could be read, rather than falling back to the
                // stored identifier and presenting it as what a message would carry.
                throw new RuntimeException(
                    sprintf(
                        'Store view %d cannot be described for store information, because the store '
                        . 'manager returned %s.',
                        $storeId,
                        get_debug_type($store)
                    )
                );
            }

            $this->storeInformationByStore[$storeId] = $this->storeInformation
                ->getStoreInformationObject($store);
        }

        return $this->storeInformationByStore[$storeId];
    }
}
