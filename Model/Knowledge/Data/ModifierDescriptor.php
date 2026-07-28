<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Data;

use Hryvinskyi\EmailTemplateEditor\Api\Data\Knowledge\ModifierDescriptorInterface;
use InvalidArgumentException;

/**
 * An immutable modifier descriptor, checked at the point it is declared.
 *
 * The checks are here because the whole set is declared in configuration and a mistake in it is
 * invisible afterwards: the template filter reports nothing about a modifier name or an argument
 * value it does not recognise, it simply passes the value through. A descriptor advertising an
 * argument value the filter does not compare against - a differently cased one, say - would have the
 * editor hand out a chain that silently does nothing, which for the escaping modifier means writing
 * an unescaped value into a message. So a descriptor that cannot be true refuses to exist.
 *
 * The argument specification is normalised to lists on the way in, because it is declared as a keyed
 * configuration array and published as a positional one.
 */
class ModifierDescriptor implements ModifierDescriptorInterface
{
    /**
     * @var array<int, array{name: string, options: string[], default: string}>
     */
    private readonly array $argumentSpec;

    /**
     * @param string $name Name as written in a modifier chain
     * @param string $label Short name for an administrator
     * @param string $description What the modifier does, and what is easy to get wrong about it
     * @param bool $implemented Whether the template filter runs anything for this name
     * @param array<array-key, mixed> $argumentSpec Positional arguments, in the order they are
     *        written. Each entry declares a name, the options the filter recognises and the default
     *        that applies when the argument is omitted. It is typed loosely because it arrives from
     *        merged configuration and is checked here rather than trusted.
     * @throws InvalidArgumentException When the name or label is empty, or the argument
     *                                 specification is malformed or advertises a default the filter
     *                                 would not recognise
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $description,
        private readonly bool $implemented = true,
        array $argumentSpec = []
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A modifier descriptor must carry the name it describes.');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException(
                sprintf('The descriptor for modifier "%s" must carry a label.', $name)
            );
        }

        $this->argumentSpec = $this->normaliseArgumentSpec($name, $argumentSpec);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @inheritDoc
     */
    public function isImplemented(): bool
    {
        return $this->implemented;
    }

    /**
     * @inheritDoc
     */
    public function getArgumentSpec(): array
    {
        return $this->argumentSpec;
    }

    /**
     * Check the argument specification and reduce it to positional lists
     *
     * @param string $name Modifier the specification belongs to, so a message points at the wiring
     * @param array<array-key, mixed> $argumentSpec Specification as declared
     * @return array<int, array{name: string, options: string[], default: string}>
     * @throws InvalidArgumentException When an entry is malformed or its default is not offered
     */
    private function normaliseArgumentSpec(string $name, array $argumentSpec): array
    {
        $normalised = [];

        foreach ($argumentSpec as $key => $argument) {
            if (!is_array($argument)
                || !array_key_exists('name', $argument)
                || !array_key_exists('options', $argument)
                || !array_key_exists('default', $argument)
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Argument "%s" of modifier "%s" must declare a name, the options the filter '
                        . 'recognises and the default that applies when it is omitted.',
                        (string)$key,
                        $name
                    )
                );
            }

            $argumentName = $argument['name'];
            $options = $argument['options'];
            $default = $argument['default'];

            if (!is_string($argumentName) || trim($argumentName) === '' || !is_string($default)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Argument "%s" of modifier "%s" must carry a name and a default written as text.',
                        (string)$key,
                        $name
                    )
                );
            }

            if (!is_array($options)) {
                throw new InvalidArgumentException(
                    sprintf('The options of argument "%s" of modifier "%s" must be a list.', $argumentName, $name)
                );
            }

            $offered = [];

            foreach ($options as $option) {
                if (!is_string($option)) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Every option of argument "%s" of modifier "%s" must be written as text, '
                            . 'exactly as the filter compares it.',
                            $argumentName,
                            $name
                        )
                    );
                }

                $offered[] = $option;
            }

            // An empty option list is how an argument says the filter constrains nothing, so there is
            // nothing to check the default against. Where the filter does compare against a fixed
            // set, a default outside it would be offered pre-selected and would do nothing at all.
            if ($offered !== [] && !in_array($default, $offered, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The default "%s" of argument "%s" of modifier "%s" is not one of the options '
                        . 'the filter recognises (%s).',
                        $default,
                        $argumentName,
                        $name,
                        implode(', ', $offered)
                    )
                );
            }

            $normalised[] = ['name' => $argumentName, 'options' => $offered, 'default' => $default];
        }

        return $normalised;
    }
}
