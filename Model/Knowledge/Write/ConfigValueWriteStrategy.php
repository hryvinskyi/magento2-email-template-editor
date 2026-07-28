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
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Validator\ValidateException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Throwable;

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
 * The whole of that lifecycle runs, not only the half of it that decides whether a value is
 * acceptable. What a field does *after* a successful save is its own business - resetting a counter,
 * clearing a queue, telling something else that a setting it depends on has moved - and it happens
 * to be nothing at all for every path currently on offer here. Leaving it out on that basis would be
 * a bet on the list never growing, and the loss on that bet would be silent: the value lands, the
 * field's own follow-up never happens, and nothing anywhere says so.
 *
 * What is kept from the caution that argued against running it is the part that mattered. Once the
 * row has landed the change is a fact, so a failure after that point is a failure of something that
 * follows a change, never a refusal of one, and it is never reported as one. It is reported as what
 * it is: the value was saved, and a warning the administrator meets on the next admin page they open
 * says what was left unfinished and how to finish it. The log carries the same failure with the
 * path, the scope and the class of the model whose step it was.
 *
 * The configuration cache is invalidated here rather than left to that step, even though a field's
 * own after-save invalidates it too. That one is conditional on the model judging the value to have
 * changed, and it is lost outright when the model throws before reaching it; this one is neither.
 * Marking a cache type invalid twice is the same as marking it invalid once, so the overlap costs
 * nothing, and without the guarantee the configuration page and every message keep serving the old
 * value while the administrator has been told, correctly, that the new one is stored.
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
     * @param MessageManagerInterface $messageManager How the administrator is told that a change was
     *        made with something about it left unfinished, since the change itself succeeded and
     *        cannot be reported by refusing it
     */
    public function __construct(
        private readonly ConfigPathWritabilityInterface $writability,
        private readonly PreparedValueFactory $preparedValueFactory,
        private readonly ConfigResource $configResource,
        private readonly TypeListInterface $cacheTypeList,
        private readonly AuthSession $authSession,
        private readonly LoggerInterface $logger,
        private readonly MessageManagerInterface $messageManager
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

        $backendModel = $this->preparedModel($path, $value, $scope, $scopeCode);

        // The model may answer with a different path from the one asked about, because a field is
        // allowed to declare that it stores its value somewhere other than where it appears, and
        // with a different value from the one typed, because normalising the input is part of what
        // a backend model is for. Both of its answers are what gets stored, not the originals.
        $writtenPath = $backendModel === null ? $path : (string)$backendModel->getPath();
        $writtenValue = $backendModel === null ? $value : (string)$backendModel->getValue();

        $this->configResource->saveConfig($writtenPath, $writtenValue, $scope, $scopeId);

        // The row has landed and the change is now a fact. Nothing below is part of making it, so
        // nothing below may leave this method as a refusal of it.
        try {
            $this->cacheTypeList->invalidate(ConfigCacheType::TYPE_IDENTIFIER);
            $this->record($writtenPath, $scope, $scopeId);
            $backendModel?->afterSave();
        } catch (Throwable $exception) {
            $this->reportUnfinishedSave($backendModel, $writtenPath, $scope, $scopeId, $exception);
        }
    }

    /**
     * The field's own model, carrying the path and value it settled on, ready to be persisted
     *
     * Only the half of the lifecycle that decides whether a value is acceptable has run by the time
     * this returns. The other half belongs after the row exists and is run by the caller, which is
     * the only place that knows the change has actually happened.
     *
     * @param string $path Store configuration path being changed
     * @param string $value Value as the administrator typed it
     * @param string $scope Scope the change is being made in
     * @param int|null $scopeCode Identifier of that scope, null for the default scope
     * @return Value|null Null when the field declares nothing that takes part in the save lifecycle,
     *         in which case the path and value to store are the ones that were asked for
     * @throws LocalizedException When the field will not accept this value, or when the field's own
     *         model cannot be built at all
     */
    private function preparedModel(string $path, string $value, string $scope, ?int $scopeCode): ?Value
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
            return null;
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

        return $backendModel;
    }

    /**
     * Record a change to the store configuration made from the editor
     *
     * A way into the configuration table from a screen nobody would look for one on needs a trail:
     * which value, in which scope, and who.
     *
     * @param string $path Store configuration path that was written
     * @param string $scope Scope it was written in
     * @param int $scopeId Identifier of that scope
     * @return void
     */
    private function record(string $path, string $scope, int $scopeId): void
    {
        $adminUser = $this->authSession->getUser();
        $adminUserId = $adminUser === null ? null : $adminUser->getId();

        $this->logger->info(
            sprintf(
                'Store configuration "%s" was changed from the email template editor in scope %s (%d) '
                . 'by admin user %s.',
                $path,
                $scope,
                $scopeId,
                // A session with nobody in it and a user whose identifier is not something that can
                // be named leave the same gap in the trail, and are recorded as the same gap.
                is_scalar($adminUserId) ? (string)$adminUserId : 'unknown'
            )
        );
    }

    /**
     * Report a change that was made with something that follows it left unfinished
     *
     * This is deliberately not an exception. The administrator asked for a value to be changed and
     * it was changed; answering that with a refusal would leave them believing the old value is
     * still in place while every message renders the new one, and correcting that belief is harder
     * than never creating it. So the change is reported as having happened, and what did not happen
     * is reported alongside it - in the log with everything needed to identify the step, and to the
     * administrator in terms of what to do about it.
     *
     * Telling the administrator is allowed to fail without changing that. The warning is queued
     * through their session, and a session that cannot take it must not become the reason they are
     * told their change was rejected. The log is the one channel taken on trust here, because a
     * logger that throws leaves nothing to report the failure of reporting with.
     *
     * @param Value|null $backendModel Model whose after-save step this was, null when the field
     *        declares none and the failure was in the work around it
     * @param string $path Store configuration path that was written
     * @param string $scope Scope it was written in
     * @param int $scopeId Identifier of that scope
     * @param Throwable $exception What went wrong after the row had landed
     * @return void
     */
    private function reportUnfinishedSave(
        ?Value $backendModel,
        string $path,
        string $scope,
        int $scopeId,
        Throwable $exception
    ): void {
        $this->logger->error(
            sprintf(
                'Store configuration "%s" was changed from the email template editor in scope %s (%d), '
                . 'but the work that follows the save did not finish. Backend model: %s. Reason: %s',
                $path,
                $scope,
                $scopeId,
                $backendModel === null ? 'none declared' : get_debug_type($backendModel),
                $exception->getMessage()
            ),
            ['exception' => $exception]
        );

        try {
            $this->messageManager->addWarningMessage(
                (string)__(
                    'The new value for "%1" was saved, but the work this field does after a save did '
                    . 'not finish. The value itself is stored; anything that step would have brought '
                    . 'up to date with it may not be. Saving the field once on its own configuration '
                    . 'page runs that step again.',
                    $path
                )
            );
        } catch (Throwable $unreportable) {
            $this->logger->error(
                sprintf(
                    'The administrator could not be warned that the change to "%s" left work '
                    . 'unfinished: %s',
                    $path,
                    $unreportable->getMessage()
                ),
                ['exception' => $unreportable]
            );
        }
    }
}
