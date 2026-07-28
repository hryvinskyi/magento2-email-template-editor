<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge;

use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\CustomVariableIndexInterface;
use Magento\Variable\Model\ResourceModel\Variable\CollectionFactory as CustomVariableCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Reads the custom variables once and answers from memory afterwards.
 *
 * The collection is loaded on the first question of a request and not again. A description request
 * carries up to two hundred directives and a variable chooser asks for the whole list on top of
 * that; loading per question would turn one query into hundreds for a table that cannot change
 * while the request runs.
 *
 * A load that failed is remembered as an empty index for the same reason. Retrying it inside the
 * same request would not make it succeed and would repeat the same log line for every question
 * asked afterwards. What an administrator sees in that case is the honest one: no variable is found,
 * so nothing claims to describe it.
 */
class CustomVariableIndex implements CustomVariableIndexInterface
{
    /**
     * The index, or null while it has not been read yet
     *
     * @var array<string, array{id: int, code: string, name: string}>|null
     */
    private ?array $index = null;

    /**
     * @param CustomVariableCollectionFactory $collectionFactory Builds the collection of custom variables
     * @param LoggerInterface $logger Records a collection that could not be read
     */
    public function __construct(
        private readonly CustomVariableCollectionFactory $collectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getAll(): array
    {
        if ($this->index === null) {
            $this->index = $this->read();
        }

        return $this->index;
    }

    /**
     * @inheritDoc
     */
    public function find(string $code): ?array
    {
        return $this->getAll()[$code] ?? null;
    }

    /**
     * Load the custom variables and index them by code
     *
     * @return array<string, array{id: int, code: string, name: string}>
     */
    private function read(): array
    {
        $index = [];

        try {
            foreach ($this->collectionFactory->create() as $variable) {
                $code = (string)$variable->getCode();

                if ($code === '') {
                    continue;
                }

                $index[$code] = [
                    'id' => (int)$variable->getId(),
                    'code' => $code,
                    'name' => (string)$variable->getName(),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to load custom variables: ' . $e->getMessage());
        }

        return $index;
    }
}
