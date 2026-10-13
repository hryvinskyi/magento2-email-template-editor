<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Preview;

use Hryvinskyi\EmailTemplateEditor\Api\ConfiguredTemplateResolverInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PreviewFrameInterface;
use Hryvinskyi\EmailTemplateEditor\Api\TemplatePreviewFramerInterface;

class TemplatePreviewFramer implements TemplatePreviewFramerInterface
{
    /**
     * @param ConfiguredTemplateResolverInterface $configuredTemplates
     * @param array<string,PreviewFrameInterface> $frames Frames keyed by the configuration path a store
     *                                                    includes the framed template by
     */
    public function __construct(
        private readonly ConfiguredTemplateResolverInterface $configuredTemplates,
        private readonly array $frames = []
    ) {
    }

    /**
     * @inheritDoc
     *
     * A template is framed as a header when it is what the store includes as its header - the
     * stock one, or whichever the configuration points at - and likewise for a footer. An override
     * saved against a theme is identified as "<templateId>/<themeCode>", and it is the same
     * template as the bare id, so it gets the same frame.
     */
    public function frame(string $templateIdentifier, string $content, int $storeId): string
    {
        $baseIdentifier = explode('/', $templateIdentifier, 2)[0];

        foreach ($this->frames as $configPath => $frame) {
            if (in_array($baseIdentifier, $this->configuredTemplates->identifiersFor($configPath, $storeId), true)) {
                return $frame->wrap($content);
            }
        }

        return $content;
    }
}
