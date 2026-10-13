<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Stores what the editor holds as a draft of a template
 *
 * Two rules are decided here rather than trusted to the browser. A field the request does not carry
 * keeps the value already stored, and only a field sent empty clears one - a save that simply left
 * the theme or the compiled CSS out must not quietly reset either. And an edit never reaches the
 * published override customers are being sent unless it is the first step of publishing that very
 * override: any other save aimed at it is written to the template's working draft instead, so the
 * live email changes only through a publish, which is what records a version of it.
 *
 * @api
 */
interface TemplateDraftSaverInterface
{
    /**
     * Save the editor's content as a draft
     *
     * @param string $templateIdentifier Template the content belongs to
     * @param int $storeId Store scope of the draft
     * @param string $templateContent Template body; never empty
     * @param array<string,string> $fields Other fields the request carried, keyed by the column
     *        names TemplateOverrideInterface declares; an absent field keeps its value, an empty
     *        one clears it
     * @param int|null $entityId Override the editor has open; null saves into the working draft
     * @param bool $forceNew Whether to start a new draft rather than reuse the working one
     * @param bool $publishIntent Whether the save is the first step of publishing the override it names
     * @param array{id:int,name:string}|null $author Administrator making the change
     * @return array{entity_id:int,forked:bool} The draft written, and whether the edit was moved
     *         off the published override it was aimed at
     * @throws LocalizedException When the override named does not exist or cannot be saved
     */
    public function save(
        string $templateIdentifier,
        int $storeId,
        string $templateContent,
        array $fields,
        ?int $entityId,
        bool $forceNew,
        bool $publishIntent,
        ?array $author
    ): array;
}
