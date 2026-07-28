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

/**
 * Stand in for the factories Magento generates at run time.
 *
 * A factory has no source file: the framework writes it into generated/code the first time something
 * asks for it. That directory is rebuilt by the running application, so whether any given factory is
 * on disk depends on what the site happened to need last - which made this suite fail and pass on
 * alternate runs with no change to the module at all, the worst possible behaviour in the only
 * automated gate this module has.
 *
 * A generated factory is a trivial wrapper, and a test that mocks one needs nothing from it but the
 * type. So one is declared here whenever it is genuinely absent. The handler is registered *after*
 * Composer's, so a real generated class always answers first and nothing is shadowed, and it only
 * accepts a name whose subject class or interface really exists - which is exactly the rule the
 * framework itself generates by, so a misspelled class still fails as loudly as before.
 */
spl_autoload_register(
    static function (string $class): void {
        if (!str_ends_with($class, 'Factory')) {
            return;
        }

        $subject = substr($class, 0, -strlen('Factory'));

        if (!class_exists($subject) && !interface_exists($subject)) {
            return;
        }

        $separator = strrpos($class, '\\');

        // phpcs:ignore Squiz.PHP.Eval.Discouraged
        eval(
            sprintf(
                'namespace %s; class %s { public function create(array $data = []) { return null; } }',
                substr($class, 0, (int)$separator),
                substr($class, (int)$separator + 1)
            )
        );
    },
    true,
    false
);
