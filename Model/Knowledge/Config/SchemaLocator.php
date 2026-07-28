<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Config;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;

/**
 * Points the configuration reader at the schema every email_variables.xml is checked against.
 *
 * The same schema serves both the individual files and the merged result. That is deliberate: a
 * contribution is only ever wrong in one of two ways - it is malformed on its own, or it collides
 * with somebody else's entry - and checking the same rules on both sides is what tells the two
 * apart. A per-file failure names the file that has to be fixed; a failure that appears only after
 * the merge is a collision between modules.
 */
class SchemaLocator implements SchemaLocatorInterface
{
    /**
     * Name of the module this schema is shipped by
     */
    private const MODULE_NAME = 'Hryvinskyi_EmailTemplateEditor';

    /**
     * File name of the schema, relative to the module's etc directory
     */
    private const SCHEMA_FILE = '/email_variables.xsd';

    /**
     * Absolute path to the schema
     *
     * @var string
     */
    private readonly string $schema;

    /**
     * @param Reader $moduleReader Locates the module's etc directory on disk
     */
    public function __construct(Reader $moduleReader)
    {
        $this->schema = $moduleReader->getModuleDir(Dir::MODULE_ETC_DIR, self::MODULE_NAME)
            . self::SCHEMA_FILE;
    }

    /**
     * @inheritDoc
     */
    public function getSchema(): ?string
    {
        return $this->schema;
    }

    /**
     * @inheritDoc
     */
    public function getPerFileSchema(): ?string
    {
        return $this->schema;
    }
}
