<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\WritabilityVerdictInterface;
use InvalidArgumentException;

/**
 * An immutable writability verdict, reachable only through its two named constructors.
 *
 * The constructor is private because the two answers do not share a shape: a refusal without a
 * reason is the one thing this value must never be able to express. It is turned into a caveat an
 * administrator reads and into the message explaining a rejected write, and an empty reason would
 * surface in both places as a value that cannot be changed with no word about why. Refusing it here
 * fails the mistake where it is made instead of in front of the administrator.
 *
 * Instances are created through those constructors, not through a factory - they are plain values
 * with no dependencies.
 */
class WritabilityVerdict implements WritabilityVerdictInterface
{
    /**
     * @param bool $writable Whether the value may be written
     * @param string $reason Why it may not, empty when it may
     */
    private function __construct(
        private readonly bool $writable,
        private readonly string $reason
    ) {
    }

    /**
     * The value may be written
     *
     * @return self
     */
    public static function allowed(): self
    {
        return new self(true, '');
    }

    /**
     * The value may not be written, for the given reason
     *
     * @param string $reason Statement of fact about the value, phrased so that it reads correctly
     *                       both as a caveat and as the message on a rejected write
     * @return self
     * @throws InvalidArgumentException When the reason is empty or only whitespace
     */
    public static function refused(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refused writability verdict must carry its reason.');
        }

        return new self(false, $reason);
    }

    /**
     * @inheritDoc
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * @inheritDoc
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
