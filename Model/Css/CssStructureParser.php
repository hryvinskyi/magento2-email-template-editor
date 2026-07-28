<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Css;

use Hryvinskyi\EmailTemplateEditor\Api\Css\CssStructureParserInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Css\CssSyntaxScannerInterface;

class CssStructureParser implements CssStructureParserInterface
{
    /**
     * @param CssSyntaxScannerInterface $syntaxScanner
     */
    public function __construct(
        private readonly CssSyntaxScannerInterface $syntaxScanner
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function splitRuleList(string $css): array
    {
        $nodes = [];
        $length = strlen($css);
        $preludeStart = 0;
        $position = 0;

        while ($position < $length) {
            $character = $css[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->syntaxScanner->skipString($css, $position);
                continue;
            }

            if ($character === '/' && ($css[$position + 1] ?? '') === '*') {
                $position = $this->syntaxScanner->skipComment($css, $position);
                continue;
            }

            if ($character !== '{') {
                $position++;
                continue;
            }

            $blockEnd = $this->syntaxScanner->findBlockEnd($css, $position);
            if ($blockEnd === -1) {
                // Unbalanced input. Stop splitting: the remainder is handed back verbatim as
                // the trailing node, so a caller that rebuilds the list reproduces it exactly.
                break;
            }

            $nodes[] = [
                'prelude' => substr($css, $preludeStart, $position - $preludeStart),
                'body' => substr($css, $position + 1, $blockEnd - $position - 1),
            ];

            $position = $blockEnd + 1;
            $preludeStart = $position;
        }

        $trailing = substr($css, $preludeStart);
        if ($trailing !== '') {
            $nodes[] = ['prelude' => $trailing, 'body' => null];
        }

        return $nodes;
    }

    /**
     * {@inheritDoc}
     */
    public function splitDeclarations(string $body): array
    {
        $segments = [];
        $length = strlen($body);
        $segmentStart = 0;
        $position = 0;
        $parenthesisDepth = 0;

        while ($position < $length) {
            $character = $body[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->syntaxScanner->skipString($body, $position);
                continue;
            }

            if ($character === '/' && ($body[$position + 1] ?? '') === '*') {
                $position = $this->syntaxScanner->skipComment($body, $position);
                continue;
            }

            if ($character === '{') {
                // A nested rule (CSS nesting). Its declarations belong to it, not to this
                // block, so the whole thing is stepped over as a single opaque token.
                $blockEnd = $this->syntaxScanner->findBlockEnd($body, $position);
                if ($blockEnd === -1) {
                    break;
                }
                $position = $blockEnd + 1;
                continue;
            }

            if ($character === '(') {
                $parenthesisDepth++;
                $position++;
                continue;
            }

            if ($character === ')') {
                $parenthesisDepth = max(0, $parenthesisDepth - 1);
                $position++;
                continue;
            }

            // A `;` inside parentheses belongs to the value (e.g. `url(data:…;base64,…)`).
            if ($character === ';' && $parenthesisDepth === 0) {
                $segments[] = substr($body, $segmentStart, $position - $segmentStart);
                $segmentStart = $position + 1;
            }

            $position++;
        }

        $segments[] = substr($body, $segmentStart);

        return $segments;
    }

    /**
     * {@inheritDoc}
     */
    public function splitPrelude(string $prelude): array
    {
        $end = $this->findLastStatementEnd($prelude);

        return [
            'statements' => substr($prelude, 0, $end),
            'selector' => substr($prelude, $end),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function resolveAtRuleName(string $prelude): ?string
    {
        $selector = $this->splitPrelude($prelude)['selector'];
        $withoutComments = preg_replace('#/\*.*?\*/#s', '', $selector);
        if ($withoutComments === null) {
            // PCRE gave up (backtrack limit, malformed UTF-8, …). The uncleaned selector is a
            // worse but still usable answer; an empty one would misreport every at-rule.
            $withoutComments = $selector;
        }

        if (preg_match('/^\s*@([-\w]+)/', $withoutComments, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Offset just past the last statement-terminating `;` in a block prelude
     *
     * Semicolons inside parentheses, strings and comments terminate nothing - an
     * `@import url("a.css?a=1;b=2");` carries one of each.
     *
     * @param string $prelude
     * @return int Zero when the prelude holds no statement at-rule
     */
    private function findLastStatementEnd(string $prelude): int
    {
        $length = strlen($prelude);
        $position = 0;
        $parenthesisDepth = 0;
        $end = 0;

        while ($position < $length) {
            $character = $prelude[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->syntaxScanner->skipString($prelude, $position);
                continue;
            }

            if ($character === '/' && ($prelude[$position + 1] ?? '') === '*') {
                $position = $this->syntaxScanner->skipComment($prelude, $position);
                continue;
            }

            if ($character === '(') {
                $parenthesisDepth++;
            } elseif ($character === ')') {
                $parenthesisDepth = max(0, $parenthesisDepth - 1);
            } elseif ($character === ';' && $parenthesisDepth === 0) {
                $end = $position + 1;
            }

            $position++;
        }

        return $end;
    }
}
