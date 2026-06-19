<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

/**
 * Standalone bootstrap for this module's unit tests.
 *
 * The module's classes under test (UtilityCssGenerator, CssVariableResolver, ThemeJsonValidator)
 * have no Magento framework dependencies, so they can be exercised by registering the module's
 * own PSR-4 namespace against a vanilla Composer autoloader without booting Magento.
 */

$autoloadCandidates = [
    __DIR__ . '/../../../../autoload.php', // vendor/<v>/<m>/Test/Unit -> vendor/autoload.php
    __DIR__ . '/../../vendor/autoload.php', // module run standalone
];

$loader = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $loader = require $candidate;
        break;
    }
}

if (!$loader instanceof \Composer\Autoload\ClassLoader) {
    fwrite(STDERR, "Unable to locate Composer autoloader for the test bootstrap.\n");
    exit(1);
}

$loader->addPsr4('Hryvinskyi\\EmailTemplateEditor\\', dirname(__DIR__, 2));
$loader->addPsr4('Hryvinskyi\\EmailTemplateEditor\\Test\\Unit\\', __DIR__);
