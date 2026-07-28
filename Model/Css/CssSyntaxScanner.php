<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Css;

use Hryvinskyi\EmailTemplateEditor\Api\Css\CssSyntaxScannerInterface;

class CssSyntaxScanner implements CssSyntaxScannerInterface
{
    /**
     * {@inheritDoc}
     */
    public function skipString(string $css, int $start): int
    {
        $quote = $css[$start] ?? '';
        $length = strlen($css);

        for ($position = $start + 1; $position < $length; $position++) {
            if ($css[$position] === '\\') {
                $position++;
                continue;
            }

            if ($css[$position] === $quote) {
                return $position + 1;
            }
        }

        return $length;
    }

    /**
     * {@inheritDoc}
     */
    public function skipComment(string $css, int $start): int
    {
        $end = strpos($css, '*/', $start + 2);

        return $end === false ? strlen($css) : $end + 2;
    }

    /**
     * {@inheritDoc}
     */
    public function findBlockEnd(string $css, int $openBrace): int
    {
        return $this->findClosingDelimiter($css, $openBrace, '{', '}');
    }

    /**
     * {@inheritDoc}
     */
    public function findParenthesisEnd(string $css, int $openParenthesis): int
    {
        return $this->findClosingDelimiter($css, $openParenthesis, '(', ')');
    }

    /**
     * Walk forward from an opening delimiter to the one that closes it
     *
     * Nesting is counted, and strings and comments are skipped wholesale so a delimiter that
     * only looks structural - a `content: "}"` value, a lone brace inside a commented-out
     * rule - cannot unbalance the count.
     *
     * @param string $css CSS source
     * @param int $start Offset of the opening delimiter
     * @param string $open Opening delimiter character
     * @param string $close Closing delimiter character
     * @return int Offset of the matching closing delimiter, or -1 when the input is unbalanced
     */
    private function findClosingDelimiter(string $css, int $start, string $open, string $close): int
    {
        $length = strlen($css);
        $depth = 0;

        for ($position = $start; $position < $length; $position++) {
            $character = $css[$position];

            if ($character === '"' || $character === "'") {
                $position = $this->skipString($css, $position) - 1;
                continue;
            }

            if ($character === '/' && ($css[$position + 1] ?? '') === '*') {
                $position = $this->skipComment($css, $position) - 1;
                continue;
            }

            if ($character === $open) {
                $depth++;
                continue;
            }

            if ($character === $close) {
                $depth--;
                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return -1;
    }
}
