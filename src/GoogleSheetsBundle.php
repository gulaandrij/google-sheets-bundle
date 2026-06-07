<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Gulaandrij\GoogleSheetsBundle\Service\GoogleClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use Revolution\Google\Sheets\SheetsClient;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class GoogleSheetsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'google_sheets';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('application_name')
                    ->defaultNull()
                    ->info('Optional application name forwarded to the Google API client.')
                ->end()
                ->arrayNode('auth')
                    ->info('Credentials for the Google API client. Provide at least one method.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('api_key')
                            ->defaultNull()
                            ->info('Simple API key (developer key). Sufficient for public spreadsheets.')
                        ->end()
                        ->scalarNode('client_id')
                            ->defaultNull()
                            ->info('OAuth client ID, used together with client_secret.')
                        ->end()
                        ->scalarNode('client_secret')
                            ->defaultNull()
                            ->info('OAuth client secret, used together with client_id.')
                        ->end()
                        ->scalarNode('auth_config')
                            ->defaultNull()
                            ->info('Path to a service-account JSON file, or the JSON document itself as a string.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('scopes')
                    ->info('OAuth scopes the client is initialised with.')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        GoogleSheets::SPREADSHEETS_READONLY,
                        GoogleSheets::DRIVE_READONLY,
                    ])
                ->end()
            ->end()
        ;
    }

    /**
     * @param array{
     *     application_name: string|null,
     *     auth: array{api_key: string|null, client_id: string|null, client_secret: string|null, auth_config: string|null},
     *     scopes: list<string>,
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services
            ->set('google_sheets.client_factory', GoogleClientFactory::class)
            ->args([
                $config['auth'],
                $config['scopes'],
                $config['application_name'],
            ])
        ;

        $services
            ->set('google_sheets.google_client', GoogleClient::class)
            ->factory([service('google_sheets.client_factory'), '__invoke'])
        ;

        $services
            ->set('google_sheets.google_service', GoogleSheets::class)
            ->args([service('google_sheets.google_client')])
        ;

        $services
            ->set('google_sheets.sheets_client', SheetsClient::class)
            ->call('setService', [service('google_sheets.google_service')])
        ;

        $services
            ->set('google_sheets.sheets_service', SheetsService::class)
            ->args([service('google_sheets.sheets_client')])
            ->public()
        ;

        $services->alias(SheetsService::class, 'google_sheets.sheets_service')->public();
        $services->alias(SheetsClient::class, 'google_sheets.sheets_client');
        $services->alias(GoogleClient::class, 'google_sheets.google_client');
        $services->alias(GoogleSheets::class, 'google_sheets.google_service');
    }
}
