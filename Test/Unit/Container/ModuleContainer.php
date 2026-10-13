<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Container;

use Magento\Framework\App\Arguments\ArgumentInterpreter;
use Magento\Framework\App\Arguments\ValidationState;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Data\Argument\Interpreter\ArrayType;
use Magento\Framework\Data\Argument\Interpreter\BaseStringUtils;
use Magento\Framework\Data\Argument\Interpreter\Boolean;
use Magento\Framework\Data\Argument\Interpreter\Composite;
use Magento\Framework\Data\Argument\Interpreter\Constant;
use Magento\Framework\Data\Argument\Interpreter\DataObject;
use Magento\Framework\Data\Argument\Interpreter\NullType;
use Magento\Framework\Data\Argument\Interpreter\Number;
use Magento\Framework\ObjectManager\Config\Config;
use Magento\Framework\ObjectManager\Config\Mapper\Dom as DomMapper;
use Magento\Framework\ObjectManager\Config\Reader\Dom as DiReader;
use Magento\Framework\ObjectManager\Config\SchemaLocator;
use Magento\Framework\ObjectManager\Definition\Runtime as RuntimeDefinitions;
use Magento\Framework\ObjectManager\Factory\Dynamic\Developer;
use Magento\Framework\ObjectManager\ObjectManager;
use Magento\Framework\ObjectManager\Relations\Runtime as RuntimeRelations;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\BooleanUtils;

/**
 * Magento's own object manager, configured from this module's etc/di.xml and nothing else.
 *
 * The wiring is read by the framework's DI reader - validated against the framework's schema and
 * mapped by the framework's mapper - and objects are built by the framework's factory, so a test
 * that asks this container for something proves what an installation would hand out: a
 * preference, a virtual type, an argument array and its order. That is what a test may claim
 * about wiring; reading di.xml with XPath and re-implementing the container would only prove the
 * test's own reading of the file.
 *
 * Only the module's file is loaded - beneath it, the files of the dependencies a test names, in the
 * order an installation merges them - so anything none of them configures is built from constructor
 * defaults. A test asks for what this module wires, not for what the rest of an installation does;
 * where a test needs a collaborator that only an installation would provide (a cache, a filesystem
 * lookup), it hands that object over as a shared instance rather than building the installation.
 */
class ModuleContainer
{
    /**
     * @param string[] $dependencies Modules this module declares a dependency on, whose wiring is read
     *        before its own because an installation merges them first
     */
    public function __construct(private readonly array $dependencies = [])
    {
    }

    /**
     * Build an object manager from the module's global DI configuration
     *
     * @param string $area 'global', or an area whose etc/<area>/di.xml is layered on top of it
     * @param array<string, object> $sharedInstances Objects the container hands out as they are,
     *        keyed by the type or virtual type they stand in for
     * @return ObjectManagerInterface
     */
    public function build(string $area = 'global', array $sharedInstances = []): ObjectManagerInterface
    {
        $definitions = new RuntimeDefinitions();
        $config = $this->configuration($area, $definitions);

        $factory = new Developer($config, null, $definitions, []);
        $objectManager = new ObjectManager($factory, $config, $sharedInstances);
        $factory->setObjectManager($objectManager);

        return $objectManager;
    }

    /**
     * The constructor arguments the container passes to a type, as its configuration resolves them
     *
     * @param string $type Class or virtual type name
     * @return array<string, mixed>
     */
    public function argumentsOf(string $type): array
    {
        $arguments = [];

        foreach ($this->configuration('global', new RuntimeDefinitions())->getArguments($type) as $name => $value) {
            $arguments[(string)$name] = $value;
        }

        return $arguments;
    }

    /**
     * The merged object manager configuration for an area
     *
     * @param string $area 'global', or an area whose etc/<area>/di.xml is layered on top of it
     * @param RuntimeDefinitions $definitions Constructor definitions the configuration is read against
     * @return Config
     */
    private function configuration(string $area, RuntimeDefinitions $definitions): Config
    {
        $config = new Config(new RuntimeRelations(), $definitions);
        $reader = $this->reader();

        $config->extend($reader->read('global'));
        if ($area !== 'global') {
            $config->extend($reader->read($area));
        }

        return $config;
    }

    /**
     * The framework's DI reader, pointed at this module's files
     *
     * @return DiReader
     */
    private function reader(): DiReader
    {
        $booleanUtils = new BooleanUtils();
        $constant = new Constant();
        $interpreter = new Composite(
            [
                'boolean' => new Boolean($booleanUtils),
                'string' => new BaseStringUtils($booleanUtils),
                'number' => new Number(),
                'null' => new NullType(),
                'object' => new DataObject($booleanUtils),
                'const' => $constant,
                'init_parameter' => new ArgumentInterpreter($constant),
            ],
            DiReader::TYPE_ATTRIBUTE
        );
        $interpreter->addInterpreter('array', new ArrayType($interpreter));

        return new DiReader(
            $this->fileResolver(),
            new DomMapper($interpreter),
            new SchemaLocator(),
            new ValidationState('developer')
        );
    }

    /**
     * A resolver answering with the dependencies' and this module's di.xml for the scope asked about
     *
     * @return FileResolverInterface
     */
    private function fileResolver(): FileResolverInterface
    {
        $registrar = new ComponentRegistrar();
        $etcDirectories = [];

        foreach ($this->dependencies as $module) {
            $etcDirectories[] = $registrar->getPath(ComponentRegistrar::MODULE, $module) . '/etc';
        }

        $etcDirectories[] = dirname(__DIR__, 3) . '/etc';

        return new class ($etcDirectories) implements FileResolverInterface {
            /**
             * @param string[] $etcDirectories
             */
            public function __construct(private readonly array $etcDirectories)
            {
            }

            /**
             * @inheritDoc
             *
             * @return array<string, string>
             */
            public function get($filename, $scope)
            {
                $files = [];

                foreach ($this->etcDirectories as $etc) {
                    $path = $scope === 'global'
                        ? $etc . '/' . $filename
                        : $etc . '/' . $scope . '/' . $filename;

                    if (is_file($path)) {
                        $files[$path] = (string)file_get_contents($path);
                    }
                }

                return $files;
            }
        };
    }
}
