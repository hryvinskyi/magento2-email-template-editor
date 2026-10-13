<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Preview;

use Hryvinskyi\EmailTemplateEditor\Api\PreviewFrameInterface;
use Hryvinskyi\EmailTemplateEditor\Api\PreviewPlaceholderInterface;

/**
 * Frames a partial template with the templates a real email puts before and after it
 *
 * The surrounding templates are pulled in by config path, exactly as a transactional email
 * includes them, so the preview shows whatever is configured and published for the store -
 * including another template's override and the CSS stored with it. Between them and the
 * edited content sits a placeholder for the body of the email.
 */
class SurroundingTemplatesFrame implements PreviewFrameInterface
{
    /**
     * @param PreviewPlaceholderInterface $placeholder
     * @param string $leadingConfigPath Config path of the template included before the content, '' for none
     * @param string $trailingConfigPath Config path of the template included after the content, '' for none
     */
    public function __construct(
        private readonly PreviewPlaceholderInterface $placeholder,
        private readonly string $leadingConfigPath = '',
        private readonly string $trailingConfigPath = ''
    ) {
    }

    /**
     * @inheritDoc
     */
    public function wrap(string $content): string
    {
        $parts = [];

        if ($this->leadingConfigPath !== '') {
            $parts[] = $this->includeDirective($this->leadingConfigPath);
            $parts[] = $this->bodyPlaceholder();
        }

        $parts[] = $content;

        if ($this->trailingConfigPath !== '') {
            $parts[] = $this->bodyPlaceholder();
            $parts[] = $this->includeDirective($this->trailingConfigPath);
        }

        return implode("\n", $parts);
    }

    /**
     * Directive that includes the template configured at a path
     *
     * @param string $configPath
     * @return string
     */
    private function includeDirective(string $configPath): string
    {
        return '{{template config_path="' . $configPath . '"}}';
    }

    /**
     * Placeholder standing in for the body of the email
     *
     * @return string
     */
    private function bodyPlaceholder(): string
    {
        return $this->placeholder->render((string)__('The email content appears here'));
    }
}
