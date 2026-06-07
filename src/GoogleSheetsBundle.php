<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Gulaandrij\GoogleSheetsBundle\Service\GoogleClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use LogicException;
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
                        ->variableNode('auth_config')
                            ->defaultNull()
                            ->info('Path to a service-account JSON file, the JSON document itself as a string, or a decoded array.')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => null !== $value && !is_string($value) && !is_array($value))
                                ->thenInvalid('google_sheets.auth.auth_config must be a string (path or JSON) or an array; got %s.')
                            ->end()
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
                ->arrayNode('spreadsheets')
                    ->info('Map of `name => spreadsheetId`. Each named entry gets its own SheetsService instance, autowireable as `SheetsService $<name>`.')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $v): bool => !is_string($v) || '' === $v)
                            ->thenInvalid('Each entry under google_sheets.spreadsheets must be a non-empty string spreadsheet ID.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('default_spreadsheet')
                    ->defaultNull()
                    ->info('Name of the spreadsheets entry that backs the unqualified `SheetsService` autowire alias. Required when more than one spreadsheet is configured.')
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static function (array $config): bool {
                    $spreadsheets = $config['spreadsheets'] ?? [];
                    $default = $config['default_spreadsheet'] ?? null;

                    return is_array($spreadsheets) && count($spreadsheets) > 1 && null === $default;
                })
                ->thenInvalid('google_sheets.default_spreadsheet must be set when more than one spreadsheet is configured.')
            ->end()
            ->validate()
                ->ifTrue(static function (array $config): bool {
                    $spreadsheets = $config['spreadsheets'] ?? [];
                    $default = $config['default_spreadsheet'] ?? null;

                    return is_string($default) && is_array($spreadsheets) && !array_key_exists($default, $spreadsheets);
                })
                ->thenInvalid('google_sheets.default_spreadsheet must reference an entry declared under google_sheets.spreadsheets.')
            ->end()
        ;
    }

    /**
     * @param array<int|string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        [$auth, $scopes, $applicationName, $spreadsheets, $defaultName] = $this->extractFactoryArgs($config);

        $services = $container->services();

        $services
            ->set('google_sheets.client_factory', GoogleClientFactory::class)
            ->args([
                $auth,
                $scopes,
                $applicationName,
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
            ->set('google_sheets.sheets_client_factory', SheetsClientFactory::class)
            ->args([service('google_sheets.google_service')])
        ;

        // Each request for the SheetsClient gets a brand-new instance so the
        // stateful selectors (range, majorDimension, valueRenderOption,
        // dateTimeRenderOption) cannot leak between consumers.
        $services
            ->set('google_sheets.sheets_client', SheetsClient::class)
            ->factory([service('google_sheets.sheets_client_factory'), 'create'])
            ->share(false)
        ;

        $services->alias(SheetsClientFactory::class, 'google_sheets.sheets_client_factory');
        $services->alias(SheetsClient::class, 'google_sheets.sheets_client');
        $services->alias(GoogleClient::class, 'google_sheets.google_client');
        $services->alias(GoogleSheets::class, 'google_sheets.google_service');

        if ([] === $spreadsheets) {
            return;
        }

        foreach ($spreadsheets as $name => $spreadsheetId) {
            $serviceId = 'google_sheets.sheets_service.'.$name;

            $services
                ->set($serviceId, SheetsService::class)
                ->args([
                    service('google_sheets.sheets_client_factory'),
                    $spreadsheetId,
                ])
                ->public()
            ;

            // Autowire by variable name: `SheetsService $allocators` resolves
            // to this concrete instance when `name` is "allocators".
            $services
                ->alias(SheetsService::class.' $'.self::variableName($name), $serviceId)
                ->public()
            ;
        }

        if (null === $defaultName) {
            // Single-spreadsheet case: implicitly use that one as the default.
            $defaultName = array_key_first($spreadsheets);
        }

        $services->alias(SheetsService::class, 'google_sheets.sheets_service.'.$defaultName)->public();
        $services->alias('google_sheets.sheets_service', 'google_sheets.sheets_service.'.$defaultName)->public();
    }

    /**
     * Validate the resolved config and return the factory args plus the named
     * spreadsheets map. Runtime validation here lets us keep loadExtension's
     * signature exactly contravariant with the parent while still passing
     * precisely-typed values into the container.
     *
     * @param array<int|string, mixed> $config
     *
     * @return array{
     *     0: array{api_key: string|null, client_id: string|null, client_secret: string|null, auth_config: string|array<string, mixed>|null},
     *     1: list<string>,
     *     2: string|null,
     *     3: array<string, string>,
     *     4: string|null,
     * }
     */
    private function extractFactoryArgs(array $config): array
    {
        $auth = $config['auth'] ?? null;
        if (!is_array($auth)) {
            throw new LogicException('google_sheets.auth must resolve to an array — config tree should guarantee this.');
        }

        $apiKey = $this->stringOrNull($auth['api_key'] ?? null);
        $clientId = $this->stringOrNull($auth['client_id'] ?? null);
        $clientSecret = $this->stringOrNull($auth['client_secret'] ?? null);

        $authConfig = $auth['auth_config'] ?? null;
        if (null !== $authConfig && !is_string($authConfig) && !is_array($authConfig)) {
            throw new LogicException('google_sheets.auth.auth_config must be string|array|null — config tree should guarantee this.');
        }
        /** @var string|array<string, mixed>|null $authConfig */
        $scopes = $config['scopes'] ?? [];
        if (!is_array($scopes)) {
            throw new LogicException('google_sheets.scopes must be a list of strings — config tree should guarantee this.');
        }
        $scopeList = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                throw new LogicException('google_sheets.scopes entries must be strings.');
            }
            $scopeList[] = $scope;
        }

        $applicationName = $this->stringOrNull($config['application_name'] ?? null);

        $spreadsheets = $config['spreadsheets'] ?? [];
        if (!is_array($spreadsheets)) {
            throw new LogicException('google_sheets.spreadsheets must be a map of name => id.');
        }
        $spreadsheetMap = [];
        foreach ($spreadsheets as $name => $id) {
            if (!is_string($name) || '' === $name) {
                throw new LogicException('google_sheets.spreadsheets keys must be non-empty strings.');
            }
            if (!is_string($id) || '' === $id) {
                throw new LogicException(sprintf('google_sheets.spreadsheets["%s"] must be a non-empty string.', $name));
            }
            $spreadsheetMap[$name] = $id;
        }

        $defaultName = $this->stringOrNull($config['default_spreadsheet'] ?? null);

        return [
            [
                'api_key' => $apiKey,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'auth_config' => $authConfig,
            ],
            $scopeList,
            $applicationName,
            $spreadsheetMap,
            $defaultName,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new LogicException(sprintf('Expected string or null, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    /**
     * Convert a config key like `allocators` / `my-reports` / `billing_data`
     * into the camelCase variable name used by autowire-by-name bindings
     * (`$allocators`, `$myReports`, `$billingData`).
     */
    private static function variableName(string $name): string
    {
        $upper = ucwords($name, '_-.');
        $stripped = str_replace(['_', '-', '.'], '', $upper);

        return lcfirst($stripped);
    }
}
