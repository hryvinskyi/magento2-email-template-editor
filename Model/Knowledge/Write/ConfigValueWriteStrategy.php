<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Write;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\OriginInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\VariableKnowledgeInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathWritabilityInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueWriteStrategyInterface;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Config\Model\PreparedValueFactory;
use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ConfigResource\ConfigInterface as ConfigResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Validator\ValidateException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Changes a store configuration value from the editor, at the scope the editor is working in.
 *
 * Whether a path may be changed at all is decided by the same collaborator that decided it when the
 * inspector offered the control, and it is asked again here. That is not distrust of the earlier
 * answer but of everything in between: the request travelled through a browser that may have been
 * left open across a permission change, and this is the last point at which the question can be
 * asked before a row lands in the configuration table.
 *
 * The value is prepared the way the configuration page prepares it, through the field's own backend
 * model, and only then persisted. Writing straight to the configuration storage would be shorter and
 * would bypass the backend models entirely - no validation, no normalisation, nothing the field
 * itself has to say about what it will accept. A sender address would then be stored unvalidated by
 * a screen whose author never intended to be in the business of validating one. Going through the
 * prepared value means a value the configuration form would reject is rejected here too, in the
 * field's own words.
 *
 * The part of that lifecycle that runs is the part that decides whether a value is acceptable. What
 * a field does *after* a successful save is not run, because a failure at that point would be
 * reported as a refusal of a change that had in fact already landed - the one report worse than no
 * report at all. For every field that survives the gates above, that step invalidates the
 * configuration cache, which is done here explicitly and unconditionally: without it the
 * configuration page and the message keep serving the old value and the change reads as having done
 * nothing.
 */
class ConfigValueWriteStrategy implements ReferenceValueWriteStrategyInterface
{
    /**
     * @param ConfigPathWritabilityInterface $writability The one decision about whether a path may
     *        be written, shared with the side that offered the control so the two cannot disagree
     * @param PreparedValueFactory $preparedValueFactory Builds the field's own backend model around
     *        the new value, which is what makes the field's validation run
     * @param ConfigResource $configResource Persists the prepared value
     * @param TypeListInterface $cacheTypeList Marks the configuration cache as needing a refresh
     * @param AuthSession $authSession Who is making the change, for the record of it
     * @param LoggerInterface $logger Where changes to the configuration made from here are recorded
     */
    public function __construct(
        private readonly ConfigPathWritabilityInterface $writability,
        private readonly PreparedValueFactory $preparedValueFactory,
        private readonly ConfigResource $configResource,
        private readonly TypeListInterface $cacheTypeList,
        private readonly AuthSession $authSession,
        private readonly LoggerInterface $logger
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
    public function write(VariableKnowledgeInterface $entry, int $storeId, string $value): void
    {
        $path = $entry->getOrigin()->getLocator();
        $verdict = $this->writability->evaluate($path, $storeId);

        if (!$verdict->isWritable()) {
            // The refusal is passed through as it stands. It was written to read both as a warning
            // before an attempt and as an explanation after one, and rewording it here would give
            // the administrator two different accounts of the same rule.
            throw new LocalizedException(__('%1', $verdict->getReason()));
        }

        // Store view zero is what the editor's switcher calls "All Store Views", which is the
        // default configuration and belongs to no store view; anything else is a change to one
        // store view and nothing wider.
        $isDefaultScope = $storeId === 0;
        $scope = $isDefaultScope ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_STORES;
        $scopeId = $isDefaultScope ? 0 : $storeId;
        $scopeCode = $isDefaultScope ? null : $storeId;

        $prepared = $this->prepared($path, $value, $scope, $scopeCode);

        $this->configResource->saveConfig($prepared['path'], $prepared['value'], $scope, $scopeId);
        $this->cacheTypeList->invalidate(ConfigCacheType::TYPE_IDENTIFIER);

        $adminUser = $this->authSession->getUser();

        // A way into the configuration table from a screen nobody would look for one on needs a
        // trail: which value, in which scope, and who.
        $this->logger->info(
            sprintf(
                'Store configuration "%s" was changed from the email template editor in scope %s (%d) '
                . 'by admin user %s.',
                $prepared['path'],
                $scope,
                $scopeId,
                $adminUser === null ? 'unknown' : (string)$adminUser->getId()
            )
        );
    }

    /**
     * The path and value to persist, having run everything the field itself has to say about them
     *
     * The prepared model may answer with a different path from the one asked about, because a field
     * is allowed to declare that it stores its value somewhere other than where it appears, and it
     * may answer with a different value from the one typed, because normalising the input is part of
     * what a backend model is for. Both of its answers are what gets stored, not the originals.
     *
     * @param string $path Store configuration path being changed
     * @param string $value Value as the administrator typed it
     * @param string $scope Scope the change is being made in
     * @param int|null $scopeCode Identifier of that scope, null for the default scope
     * @return array{path: string, value: string}
     * @throws LocalizedException When the field will not accept this value, or when the field's own
     *         model cannot be built at all
     */
    private function prepared(string $path, string $value, string $scope, ?int $scopeCode): array
    {
        try {
            $backendModel = $this->preparedValueFactory->create($path, $value, $scope, $scopeCode);
        } catch (RuntimeException $exception) {
            throw new LocalizedException(
                __(
                    'The value at "%1" could not be prepared for saving, so nothing was changed: %2',
                    $path,
                    $exception->getMessage()
                ),
                $exception
            );
        }

        if (!$backendModel instanceof Value) {
            return ['path' => $path, 'value' => $value];
        }

        try {
            // Where a field's own validation lives, and the whole reason for coming this way round.
            // A message thrown here is the field's own and is left to travel as it is.
            $backendModel->beforeSave();
        } catch (ValidateException $exception) {
            // Thrown when a validator cannot be built rather than when a value fails one, so it
            // means the value was never actually checked. Storing an unchecked value is exactly what
            // this route exists to prevent.
            throw new LocalizedException(
                __(
                    'The value for "%1" could not be checked, so it was not saved: %2',
                    $path,
                    $exception->getMessage()
                ),
                $exception
            );
        }

        return [
            'path' => (string)$backendModel->getPath(),
            'value' => (string)$backendModel->getValue(),
        ];
    }
}
