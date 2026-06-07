<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Fixtures;

use Gulaandrij\GoogleSheetsBundle\GoogleSheetsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel implements CompilerPassInterface
{
    /**
     * @param array<string, mixed> $bundleConfig
     */
    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly string $cacheNamespace = 'default',
        bool $debug = true,
    ) {
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new GoogleSheetsBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'router' => ['utf8' => true, 'resource' => 'kernel::loadRoutes'],
            ]);

            $container->loadFromExtension('google_sheets', $this->bundleConfig);

            $container->addCompilerPass($this);
        });
    }

    /**
     * Expose every `google_sheets.*` service publicly so tests can introspect
     * the wiring without going through service locators or reflection.
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, 'google_sheets.')) {
                $definition->setPublic(true);
            }
        }

        foreach ($container->getAliases() as $id => $alias) {
            $target = (string) $alias;
            if (str_starts_with($id, 'google_sheets.') || str_starts_with($target, 'google_sheets.')) {
                $alias->setPublic(true);
            }
        }
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/google-sheets-bundle-tests/'.$this->cacheNamespace.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/google-sheets-bundle-tests/'.$this->cacheNamespace.'/log';
    }
}
