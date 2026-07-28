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
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ReferenceValueWriteStrategyInterface;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validation\ValidationException;
use Magento\Framework\Validator\ValidateException;
use Magento\Variable\Model\Variable;
use Magento\Variable\Model\VariableFactory;
use Psr\Log\LoggerInterface;

/**
 * Changes the HTML value of a merchant-authored custom variable, for the scope the editor is in.
 *
 * A custom variable holds two values, one for HTML messages and one for plain-text ones, and this
 * editor edits HTML messages, so the HTML value is the one it changes. The plain value is left as it
 * is, with one exception: when the two were identical, they are kept identical. A variable whose two
 * forms were the same was almost certainly written once and stored twice, and letting them drift
 * apart on an edit made here would change what HTML readers see while plain-text readers carry on
 * receiving the old text, with nothing anywhere reporting that the two now differ.
 *
 * A variable with no HTML value at all is refused rather than filled in. That case is not "the HTML
 * value is empty so writing one is harmless": with no HTML value the variable renders its *plain*
 * value into HTML messages, escaped and with its line breaks converted, so the plain value is what
 * HTML readers are seeing today. Writing an HTML value would silently move authority from one field
 * to the other - the change would appear to work, and the same variable would then say two different
 * things in two kinds of message, from a single edit that mentioned only one of them. The variable's
 * own form is where both values can be seen and set together, and the panel links to it.
 *
 * The change is saved through the variable model rather than through its storage, because the model
 * is where the check on user-authored HTML runs. Skipping it would let an administrator who may edit
 * templates and variables store markup that Magento's own variable form would have rejected, into a
 * value that renders into every message that names it.
 */
class CustomVariableValueWriteStrategy implements ReferenceValueWriteStrategyInterface
{
    /**
     * @param VariableFactory $variableFactory Builds the variable model the email filter also uses
     * @param AuthSession $authSession Who is making the change, for the record of it
     * @param LoggerInterface $logger Where changes made from here are recorded
     */
    public function __construct(
        private readonly VariableFactory $variableFactory,
        private readonly AuthSession $authSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(OriginInterface $origin): bool
    {
        return $origin->getKind() === OriginInterface::KIND_CUSTOM_VARIABLE;
    }

    /**
     * @inheritDoc
     */
    public function write(VariableKnowledgeInterface $entry, int $storeId, string $value): void
    {
        $code = $entry->getOrigin()->getLocator();

        if ($code === '') {
            throw new LocalizedException(
                __('This directive names no custom variable, so there is no value to change.')
            );
        }

        // Loaded for the scope being written, which is also how the values arrive: a store view with
        // no value of its own is answered with the value saved for all store views, and saving then
        // gives that store view a value of its own carrying whatever is not being changed.
        $variable = $this->variableFactory->create()->setStoreId($storeId)->loadByCode($code);

        if (!$variable->getId()) {
            throw new LocalizedException(
                __(
                    'No custom variable has the code "%1", so there is nothing to change. A directive '
                    . 'naming a code that no variable carries renders as nothing at all.',
                    $code
                )
            );
        }

        $storedHtml = (string)$variable->getData('html_value');
        $storedPlain = (string)$variable->getData('plain_value');

        // The same test the variable itself applies when it decides which of its two values an HTML
        // message gets, so that this refusal covers exactly the case where the plain value is what
        // HTML readers are seeing.
        if (strlen($storedHtml) === 0) {
            throw new LocalizedException(
                __(
                    'The custom variable "%1" has no HTML value, so HTML messages currently render its '
                    . 'plain value. Setting an HTML value here would change what HTML messages show '
                    . 'while leaving plain-text messages on the old text, so both are set on the '
                    . 'variable\'s own form instead.',
                    $code
                )
            );
        }

        $variable->setData('html_value', $value);

        if ($storedPlain === $storedHtml) {
            $variable->setData('plain_value', $value);
        }

        $this->save($variable, $code);

        $adminUser = $this->authSession->getUser();

        $this->logger->info(
            sprintf(
                'Custom variable "%s" had its HTML value changed from the email template editor for '
                . 'store view %d by admin user %s.',
                $code,
                $storeId,
                $adminUser === null ? 'unknown' : (string)$adminUser->getId()
            )
        );
    }

    /**
     * Store the variable, reporting a rejected value as a refusal rather than as a failure
     *
     * @param Variable $variable Variable carrying the new value
     * @param string $code Code of that variable, for the message
     * @return void
     * @throws LocalizedException When the content is not something the variable will accept
     */
    private function save(Variable $variable, string $code): void
    {
        try {
            $variable->save();
        } catch (ValidationException | ValidateException $exception) {
            throw new LocalizedException(
                __(
                    'The content given for the custom variable "%1" was rejected, so nothing was '
                    . 'changed: %2',
                    $code,
                    $exception->getMessage()
                ),
                $exception
            );
        }
    }
}
