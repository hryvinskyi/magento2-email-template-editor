<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Css;

/**
 * Byte-level cursor movements over CSS source.
 *
 * Every regular expression that tries to find the end of a CSS construct is wrong for the
 * same reason: `{`, `}`, `(`, `)`, `;` and `,` all occur inside strings, comments and `url()`
 * tokens, where they carry no structural meaning. A `content: "}"` declaration or a
 * `url(data:image/svg+xml;base64,…)` value is enough to make a brace-counting or
 * semicolon-splitting pattern cut the stylesheet in the wrong place, and the damage is
 * silent - the output is still a string, just no longer the stylesheet the author wrote.
 *
 * The methods here are the smallest primitives that make those constructs skippable. They
 * work on byte offsets and never allocate, so callers can walk a stylesheet once.
 */
interface CssSyntaxScannerInterface
{
    /**
     * Return the offset just past the closing quote of the string starting at $start
     *
     * Backslash escapes are honoured, so `"a\"b"` is skipped as one token. An unterminated
     * string is treated as running to the end of the input.
     *
     * @param string $css CSS source
     * @param int $start Offset of the opening quote
     * @return int Offset of the first byte after the string
     */
    public function skipString(string $css, int $start): int;

    /**
     * Return the offset just past the closing delimiter of the comment starting at $start
     *
     * An unterminated comment is treated as running to the end of the input.
     *
     * @param string $css CSS source
     * @param int $start Offset of the opening slash
     * @return int Offset of the first byte after the comment
     */
    public function skipComment(string $css, int $start): int;

    /**
     * Locate the `}` matching the `{` at $openBrace, ignoring braces inside strings and comments
     *
     * @param string $css CSS source
     * @param int $openBrace Offset of the opening brace
     * @return int Offset of the matching closing brace, or -1 when the input is unbalanced
     */
    public function findBlockEnd(string $css, int $openBrace): int;

    /**
     * Locate the `)` matching the `(` at $openParenthesis, ignoring parentheses in strings and comments
     *
     * @param string $css CSS source
     * @param int $openParenthesis Offset of the opening parenthesis
     * @return int Offset of the matching closing parenthesis, or -1 when the input is unbalanced
     */
    public function findParenthesisEnd(string $css, int $openParenthesis): int;
}
