<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\ConfigPathReadabilityInterface;
use Magento\Variable\Model\Source\Variables as ConfigVariables;

/**
 * The list of configuration paths a {{config}} directive can actually read.
 *
 * There is one line of substance here and it is worth the class, because getting it wrong is silent
 * in both directions. The list comes from the same object the email filter consults when it decides
 * whether to render a {{config}} directive at all, so this answer and the message's behaviour cannot
 * drift apart; and it is a plain list of paths compared against as a plain list of paths.
 *
 * That last part is not pedantry. The neighbouring method on the underlying configuration source
 * returns a map *keyed* by path whose values are all the string "1", so searching it for a path
 * finds nothing, ever. Written that way the check refuses everything, the inspector reports every
 * path as unreadable, and nothing anywhere fails - which is exactly the sort of mistake that
 * survives a review. One place makes this call, and every question about the list is asked here.
 */
class ConfigPathReadability implements ConfigPathReadabilityInterface
{
    /**
     * @param ConfigVariables $configVariables Source of the paths a {{config}} directive may read -
     *        the same object the email filter asks, so the two cannot answer differently
     */
    public function __construct(private readonly ConfigVariables $configVariables)
    {
    }

    /**
     * @inheritDoc
     */
    public function isReadable(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return in_array($path, $this->configVariables->getAvailableVars(), true);
    }
}
