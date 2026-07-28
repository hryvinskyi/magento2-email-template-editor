<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api;

interface ScheduleConflictDetectorInterface
{
    /**
     * Detect overlapping availability windows for the given template and store
     *
     * Each bound of a window is optional and a bound that is not set is an open end: a window with
     * only a start runs from that moment onward, one with only an end runs until then, and one
     * with neither is not a window at all. Two windows overlap when each begins before the other
     * ends, so windows that merely touch — one ending at the instant the next begins — are not
     * reported against each other.
     *
     * A candidate carrying neither bound conflicts with nothing, and existing rows carrying
     * neither bound are passed over: such a row does not claim a period, it is the standing
     * override that a window temporarily displaces.
     *
     * Published and scheduled rows are examined whether or not they are switched off, since a row
     * that was switched off keeps its period and can be switched back on.
     *
     * @param string $templateIdentifier
     * @param int $storeId
     * @param string|null $activeFrom
     * @param string|null $activeTo
     * @param int|null $excludeEntityId
     * @return array<int, array{entity_id: int, draft_name: string|null, active_from: string|null, active_to: string|null}>
     */
    public function detect(
        string $templateIdentifier,
        int $storeId,
        ?string $activeFrom,
        ?string $activeTo,
        ?int $excludeEntityId = null
    ): array;
}
