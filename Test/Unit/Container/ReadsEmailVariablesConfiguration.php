<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Test\Unit\Container;

use Hryvinskyi\EmailTemplateEditor\Api\Knowledge\VariableKnowledgeProviderInterface;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\Config\Data as KnowledgeConfigData;
use Hryvinskyi\EmailTemplateEditor\Model\Knowledge\XmlKnowledgeProvider;
use Magento\Config\Model\Config\Structure\SearchInterface;
use Magento\Framework\App\Arguments\ValidationState;
use Magento\Framework\Config\CacheInterface;
use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

/**
 * email_variables.xml read the way an installation reads it.
 *
 * The module's own wiring is built by Magento's object manager: the reader virtual type with its
 * merge identities, this module's schema and converter, the configuration data object and the
 * knowledge provider on top. Only what an installation would supply from outside the module is handed
 * over - the files to read, a cold cache, developer-mode validation, where the module lives on disk and
 * a URL builder that spells out what it was asked to build - so a schema the files break, a merge
 * identity that does not hold or an entry the converter refuses fails here as it would on an install.
 *
 * Magento_Variable's wiring is read beneath the module's, because which configuration paths an email
 * can read is decided there.
 */
trait ReadsEmailVariablesConfiguration
{
    /**
     * The knowledge an installation builds from the given files, merged in the order given
     *
     * @param array<string, string> $files File contents keyed by a path naming them in error messages
     * @param LoggerInterface $logger Receives what the converter refuses
     * @return VariableKnowledgeProviderInterface
     */
    private function knowledgeFrom(array $files, LoggerInterface $logger): VariableKnowledgeProviderInterface
    {
        $provider = $this->knowledgeContainer($files, $logger)->create(XmlKnowledgeProvider::class);
        self::assertInstanceOf(VariableKnowledgeProviderInterface::class, $provider);

        return $provider;
    }

    /**
     * The object manager the knowledge is built by, reading the given files
     *
     * @param array<string, string> $files File contents keyed by a path naming them in error messages
     * @param LoggerInterface $logger Receives what the converter refuses
     * @return ObjectManagerInterface
     */
    private function knowledgeContainer(array $files, LoggerInterface $logger): ObjectManagerInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $moduleDirReader = $this->createMock(ModuleDirReader::class);
        $moduleDirReader->method('getModuleDir')->willReturn(dirname(__DIR__, 3) . '/etc');

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (?string $route = null, ?array $params = null): string =>
                $route . '?' . http_build_query($params ?? [])
        );

        $fileResolver = new InMemoryFileResolver(['email_variables.xml' => $files]);

        $container = (new ModuleContainer(['Magento_Variable']))->build('global', [
            FileResolverInterface::class => $fileResolver,
            ValidationStateInterface::class => new ValidationState('developer'),
            CacheInterface::class => $cache,
            SerializerInterface::class => new Json(),
            ModuleDirReader::class => $moduleDirReader,
            UrlInterface::class => $url,
            'Hryvinskyi\EmailTemplateEditor\Logger\Logger' => $logger,
            // The configuration structure is only searched for labels; which paths can be read is
            // the separate list Magento_Variable wires, and that is what is asked about.
            'Magento\Variable\Model\Config\StructureVirtual' => $this->createMock(SearchInterface::class),
        ]);

        // The configuration data object takes its serializer from the application's global object
        // manager when none is wired, which a test does not have; the same serializer is handed over.
        $container->configure([
            KnowledgeConfigData::class => ['arguments' => ['serializer' => ['instance' => SerializerInterface::class]]],
        ]);

        return $container;
    }

    /**
     * The knowledge file this module ships, as a file to read
     *
     * @return array<string, string>
     */
    private function shippedKnowledgeFile(): array
    {
        $path = dirname(__DIR__, 3) . '/etc/email_variables.xml';

        return [$path => (string)file_get_contents($path)];
    }
}
