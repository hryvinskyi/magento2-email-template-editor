<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\DirectiveReferenceInterface;
use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\DirectiveReferenceParserInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data\DirectiveReference;
use InvalidArgumentException;

/**
 * The one place the canonical reference grammar is implemented.
 *
 * A canonical reference is `<kind>:<expression>`, split on the **first** colon only - expressions
 * carry colons of their own (`general/store_information/name` does not, but a block class does, and
 * so does a translated message), and splitting on the last one or on every one would take a
 * reference apart in the middle. Everything after that first colon belongs to the expression.
 *
 * The expression is then normalised per kind, so that the several ways the same directive can be
 * written all collapse onto one key. The stripping of surrounding quotes is not cosmetic: this
 * module's own variable chooser emits `{{customVar code=my_code}}` while Magento's config-variable
 * source emits `{{config path="..."}}`, and both end up in the same chooser - without unquoting, a
 * chooser row and the identical directive in the document would resolve to two different entries.
 */
class DirectiveReferenceParser implements DirectiveReferenceParserInterface
{
    /**
     * Leave whitespace inside the expression alone
     */
    private const WHITESPACE_KEEP = null;

    /**
     * Remove every whitespace character inside the expression
     */
    private const WHITESPACE_REMOVE = '';

    /**
     * Collapse each run of whitespace inside the expression to a single space
     */
    private const WHITESPACE_COLLAPSE = ' ';

    /**
     * The published set of directive kinds, each with the normalisation its expression gets.
     *
     * The keys are the published set in their canonical spelling; a kind outside it is refused.
     * Kinds carrying `identity => false` have no stable identity beyond the kind itself, so their
     * expression is forced empty rather than being carried around as noise.
     *
     * @var array<string, array{identity: bool, unquote: bool, whitespace: string|null}>
     */
    public const KINDS = [
        // A variable path. A directive may be written `{{var  store.getFormattedAddress() }}`, and
        // the renderer hands the whole interior to the variable resolver, so all whitespace goes.
        'var' => ['identity' => true, 'unquote' => false, 'whitespace' => self::WHITESPACE_REMOVE],
        // Single-token parameters: a config path and a custom-variable code. Both are written with
        // and without quotes by different Magento sources, and neither ever contains whitespace.
        'config' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_KEEP],
        'customVar' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_KEEP],
        // The message, and the directives identified by their primary parameter. These can carry
        // internal whitespace that is not significant to what they point at, so runs of it collapse.
        'trans' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_COLLAPSE],
        'block' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_COLLAPSE],
        'layout' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_COLLAPSE],
        'media' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_COLLAPSE],
        'store' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_COLLAPSE],
        'css' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_COLLAPSE],
        'template' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_COLLAPSE],
        'view' => ['identity' => true, 'unquote' => true, 'whitespace' => self::WHITESPACE_COLLAPSE],
        // Kinds with no identity of their own: what they do is decided by the document around them.
        'protocol' => ['identity' => false, 'unquote' => false, 'whitespace' => self::WHITESPACE_KEEP],
        'inlinecss' => ['identity' => false, 'unquote' => false, 'whitespace' => self::WHITESPACE_KEEP],
        'depend' => ['identity' => false, 'unquote' => false, 'whitespace' => self::WHITESPACE_KEEP],
        'if' => ['identity' => false, 'unquote' => false, 'whitespace' => self::WHITESPACE_KEEP],
        'for' => ['identity' => false, 'unquote' => false, 'whitespace' => self::WHITESPACE_KEEP],
    ];

    /**
     * The separator between the kind and the expression in a canonical reference
     */
    private const SEPARATOR = ':';

    /**
     * Syntactic shape of a kind, checked before the published set so the error message stays bounded
     */
    private const KIND_PATTERN = '/^[a-zA-Z]{1,16}$/';

    /**
     * Characters that may never appear in an expression
     *
     * Braces would let a reference be mistaken for a directive, and line breaks and NUL bytes break
     * every place a reference is carried as a single line of text. The pattern is deliberately not
     * unicode-aware: it matches bytes, so it cannot fail on input that is not valid UTF-8.
     */
    private const FORBIDDEN_EXPRESSION_PATTERN = '/[{}\r\n\x00]/';

    /**
     * Longest expression carried in a reference, in bytes
     */
    private const MAX_EXPRESSION_BYTES = 255;

    /**
     * Longest value echoed back in an exception message, in bytes
     */
    private const MESSAGE_EXCERPT_BYTES = 48;

    /**
     * @inheritDoc
     */
    public function parse(string $canonical): DirectiveReferenceInterface
    {
        $separatorPosition = strpos($canonical, self::SEPARATOR);

        if ($separatorPosition === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'A canonical directive reference must contain a colon separating the kind from '
                    . 'the expression, got "%s".',
                    $this->excerpt($canonical)
                )
            );
        }

        return $this->create(
            substr($canonical, 0, $separatorPosition),
            substr($canonical, $separatorPosition + 1)
        );
    }

    /**
     * @inheritDoc
     */
    public function create(string $kind, string $expression): DirectiveReferenceInterface
    {
        $canonicalKind = $this->resolveKind($kind);

        if (preg_match(self::FORBIDDEN_EXPRESSION_PATTERN, $expression) === 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'A directive reference expression must not contain braces, line breaks or NUL '
                    . 'bytes, got "%s".',
                    $this->excerpt($expression)
                )
            );
        }

        $normalised = $this->normalise($canonicalKind, $expression);
        $overlong = strlen($normalised) > self::MAX_EXPRESSION_BYTES;

        if ($overlong) {
            // Cut on a character boundary. A byte-boundary cut would leave a half-encoded character
            // behind, and a key that is not valid UTF-8 makes json_encode() fail for the whole
            // response, not just for that entry. Trailing whitespace goes with it, because a
            // canonical string is re-parsed with the same trimming and would otherwise not
            // round-trip.
            $normalised = rtrim(mb_strcut($normalised, 0, self::MAX_EXPRESSION_BYTES, 'UTF-8'));
        }

        return new DirectiveReference($canonicalKind, $normalised, $overlong);
    }

    /**
     * Match a kind against the published set and return its canonical spelling
     *
     * The comparison ignores case because the renderer does: the email filter reaches a directive
     * through a reflected method call, and PHP method names are case-insensitive, so `{{CustomVar}}`
     * and `{{customVar}}` render identically and must therefore describe the same thing.
     *
     * @param string $kind Kind as written
     * @return string Canonical spelling of the kind
     * @throws InvalidArgumentException When the kind is empty, malformed, or not published
     */
    private function resolveKind(string $kind): string
    {
        if ($kind === '') {
            throw new InvalidArgumentException('A directive reference kind must not be empty.');
        }

        if (preg_match(self::KIND_PATTERN, $kind) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'A directive reference kind must be one to sixteen letters, got "%s".',
                    $this->excerpt($kind)
                )
            );
        }

        if (isset(self::KINDS[$kind])) {
            return $kind;
        }

        $lowercased = strtolower($kind);

        foreach (array_keys(self::KINDS) as $published) {
            if (strtolower($published) === $lowercased) {
                return $published;
            }
        }

        throw new InvalidArgumentException(sprintf('"%s" is not a published directive kind.', $kind));
    }

    /**
     * Apply the kind's normalisation to a raw expression
     *
     * The result is always trimmed. That is what makes a canonical string round-trip: re-parsing one
     * trims again, so an expression that kept leading or trailing whitespace would come back as a
     * different reference.
     *
     * @param string $kind Canonical kind
     * @param string $expression Raw expression
     * @return string Normalised expression
     */
    private function normalise(string $kind, string $expression): string
    {
        $rules = self::KINDS[$kind];

        if ($rules['identity'] === false) {
            return '';
        }

        $value = trim($expression);

        if ($rules['unquote']) {
            $value = trim($this->stripSurroundingQuotes($value));
        }

        if ($rules['whitespace'] !== self::WHITESPACE_KEEP) {
            $value = trim((string)preg_replace('/\s+/', $rules['whitespace'], $value));
        }

        return $value;
    }

    /**
     * Remove one matched pair of surrounding single or double quotes
     *
     * Only a matched pair goes: a stray quote on one side is part of the value, because that is how
     * the renderer would read it too.
     *
     * @param string $value Trimmed expression
     * @return string
     */
    private function stripSurroundingQuotes(string $value): string
    {
        if (strlen($value) < 2) {
            return $value;
        }

        $first = $value[0];

        if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Shorten a value for an exception message
     *
     * A reference can be arbitrarily long and arrives from a browser; an unbounded echo would put
     * the whole of it into a log line.
     *
     * @param string $value Value to abbreviate
     * @return string
     */
    private function excerpt(string $value): string
    {
        if (strlen($value) <= self::MESSAGE_EXCERPT_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, self::MESSAGE_EXCERPT_BYTES, 'UTF-8') . '...';
    }
}
