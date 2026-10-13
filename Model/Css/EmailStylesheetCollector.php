<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Css;

use Hryvinskyi\EmailTemplateEditor\Api\Css\EmailStylesheetCollectorInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

class EmailStylesheetCollector implements EmailStylesheetCollectorInterface, ResetAfterRequestInterface
{
    private const TIERS = ['theme', 'utility', 'custom'];

    /**
     * Each template's own stylesheet, keyed weakly by the template object
     *
     * @var \WeakMap<object,array{theme:string,utility:string,custom:string}>
     */
    private \WeakMap $assigned;

    /**
     * Open render scopes, innermost last; each holds the CSS pieces collected per tier
     *
     * @var list<array{theme:list<string>,utility:list<string>,custom:list<string>}>
     */
    private array $scopes = [];

    /**
     * Start with no sheets assigned and no render open
     */
    public function __construct()
    {
        $this->assigned = new \WeakMap();
    }

    /**
     * @inheritDoc
     */
    public function assign(object $template, string $themeCss, string $utilityCss, string $customCss): void
    {
        $this->assigned[$template] = ['theme' => $themeCss, 'utility' => $utilityCss, 'custom' => $customCss];
    }

    /**
     * @inheritDoc
     */
    public function begin(): void
    {
        $this->scopes[] = ['theme' => [], 'utility' => [], 'custom' => []];
    }

    /**
     * @inheritDoc
     */
    public function end(object $template): ?array
    {
        $scope = array_pop($this->scopes);

        if ($scope === null) {
            throw new \LogicException('No email render is open to close.');
        }

        $own = $this->assigned[$template] ?? null;

        foreach (self::TIERS as $tier) {
            if ($own !== null && trim($own[$tier]) !== '') {
                $scope[$tier][] = $own[$tier];
            }
        }

        $enclosing = array_key_last($this->scopes);

        if ($enclosing !== null) {
            foreach (self::TIERS as $tier) {
                $this->scopes[$enclosing][$tier] = [...$this->scopes[$enclosing][$tier], ...$scope[$tier]];
            }

            return null;
        }

        return [
            'theme' => implode("\n", array_unique($scope['theme'])),
            'utility' => implode("\n", array_unique($scope['utility'])),
            'custom' => implode("\n", array_unique($scope['custom'])),
        ];
    }

    /**
     * @inheritDoc
     *
     * A render that died without closing its scope must not leave the next email nested inside it.
     */
    public function _resetState(): void
    {
        $this->assigned = new \WeakMap();
        $this->scopes = [];
    }
}
