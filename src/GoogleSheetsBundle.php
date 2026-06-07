<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Sheets as GoogleSheets;
use Gulaandrij\GoogleSheetsBundle\Command\DoctorCommand;
use Gulaandrij\GoogleSheetsBundle\Command\ListSpreadsheetsCommand;
use Gulaandrij\GoogleSheetsBundle\Command\PeekCommand;
use Gulaandrij\GoogleSheetsBundle\Command\TabsCommand;
use Gulaandrij\GoogleSheetsBundle\Profiler\SheetsCollector;
use Gulaandrij\GoogleSheetsBundle\Profiler\TraceableSheetsService;
use Gulaandrij\GoogleSheetsBundle\Service\CachedSheetsService;
use Gulaandrij\GoogleSheetsBundle\Service\GoogleClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use LogicException;
use Revolution\Google\Sheets\SheetsClient;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

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
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $v): bool => !is_string($v) || '' === trim($v))
                            ->thenInvalid('google_sheets.scopes entries must be non-empty strings.')
                        ->end()
                    ->end()
                    ->defaultValue([
                        GoogleSheets::SPREADSHEETS_READONLY,
                        GoogleSheets::DRIVE_READONLY,
                    ])
                ->end()
                ->arrayNode('spreadsheets')
                    ->info('Map of `name => {id, sheet?}`. Each named entry becomes a SheetsService instance, autowireable as `SheetsService $<name>`.')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('id')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->info('The Google Sheets spreadsheet ID (from the URL).')
                            ->end()
                            ->scalarNode('sheet')
                                ->defaultNull()
                                ->info('Optional default tab name. When set, SheetsService methods may be called without a $sheetName argument.')
                            ->end()
                            ->arrayNode('cache')
                                ->info('Opt-in read-result caching. When set, reads (readRaw/readAssoc/firstRow/listSheets/spreadsheetProperties/sheetProperties) are memoised through a Symfony cache pool. Not applied when kernel.debug is true — the profiler shows real calls.')
                                ->children()
                                    ->integerNode('ttl')
                                        ->isRequired()
                                        ->min(1)
                                        ->info('Cache TTL in seconds.')
                                    ->end()
                                    ->scalarNode('pool')
                                        ->defaultValue('cache.app')
                                        ->info('Symfony cache pool service ID. Must implement Symfony\Contracts\Cache\CacheInterface.')
                                    ->end()
                                ->end()
                            ->end()
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
                    if (!is_array($spreadsheets)) {
                        return false;
                    }
                    foreach (array_keys($spreadsheets) as $name) {
                        if (!is_string($name) || !self::isValidSpreadsheetName($name)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->thenInvalid('google_sheets.spreadsheets keys must contain only letters, digits, underscores, dashes or dots, and must start with a letter or underscore (so they can be camelCased into a PHP variable name).')
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
            ->set('google_sheets.google_drive', GoogleDrive::class)
            ->args([service('google_sheets.google_client')])
        ;

        $services
            ->set('google_sheets.sheets_client_factory', SheetsClientFactory::class)
            ->args([
                service('google_sheets.google_service'),
                service('google_sheets.google_drive'),
            ])
            ->public()
        ;

        // Each request for the SheetsClient gets a brand-new instance so the
        // stateful selectors (range, majorDimension, valueRenderOption,
        // dateTimeRenderOption) cannot leak between consumers.
        $services
            ->set('google_sheets.sheets_client', SheetsClient::class)
            ->factory([service('google_sheets.sheets_client_factory'), 'create'])
            ->share(false)
        ;

        $services->alias(SheetsClientFactory::class, 'google_sheets.sheets_client_factory')->public();
        $services->alias(SheetsClient::class, 'google_sheets.sheets_client');
        $services->alias(GoogleClient::class, 'google_sheets.google_client');
        $services->alias(GoogleSheets::class, 'google_sheets.google_service');
        $services->alias(GoogleDrive::class, 'google_sheets.google_drive');

        $debug = (bool) $builder->getParameter('kernel.debug');

        if ($debug) {
            $services
                ->set('google_sheets.profiler.collector', SheetsCollector::class)
                ->tag('data_collector', [
                    'template' => '@GoogleSheets/Collector/sheets.html.twig',
                    'id' => 'google_sheets',
                    'priority' => 250,
                ])
            ;
        }

        if ([] === $spreadsheets) {
            return;
        }

        foreach ($spreadsheets as $name => $entry) {
            $serviceId = 'google_sheets.sheets_service.'.$name;

            $denormalizer = service(DenormalizerInterface::class)->nullOnInvalid();
            $factoryRef = service('google_sheets.sheets_client_factory');
            $cache = $entry['cache'];

            if ($debug) {
                // Profiler trace wins over caching in debug mode — the panel
                // should show real Sheets calls, not cache hits.
                $services
                    ->set($serviceId, TraceableSheetsService::class)
                    ->args([
                        $factoryRef,
                        $entry['id'],
                        $entry['sheet'],
                        $denormalizer,
                        service('google_sheets.profiler.collector'),
                        $name,
                    ])
                    ->public()
                ;
            } elseif (null !== $cache) {
                if (!$builder->has($cache['pool'])) {
                    throw new LogicException(sprintf(
                        'google_sheets.spreadsheets["%s"].cache.pool refers to service "%s", which is not registered. '
                        .'Configure it via framework.cache.pools.%s, or use the default "cache.app" (enabled by FrameworkBundle).',
                        $name,
                        $cache['pool'],
                        $cache['pool'],
                    ));
                }
                $services
                    ->set($serviceId, CachedSheetsService::class)
                    ->args([
                        $factoryRef,
                        $entry['id'],
                        $entry['sheet'],
                        service($cache['pool']),
                        $cache['ttl'],
                        $name,
                        $denormalizer,
                    ])
                    ->public()
                ;
            } else {
                $services
                    ->set($serviceId, SheetsService::class)
                    ->args([
                        $factoryRef,
                        $entry['id'],
                        $entry['sheet'],
                        $denormalizer,
                    ])
                    ->public()
                ;
            }

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

        // Service locator so the registry can pull `SheetsService` instances by name without
        // depending on individual service IDs.
        $locatorMap = [];
        foreach ($spreadsheets as $name => $_entry) {
            $locatorMap[$name] = service('google_sheets.sheets_service.'.$name);
        }

        $services
            ->set('google_sheets.registry', SheetsRegistry::class)
            ->args([
                $spreadsheets,
                service_locator($locatorMap),
            ])
            ->public()
        ;
        $services->alias(SheetsRegistry::class, 'google_sheets.registry')->public();

        // Console commands. Tagged manually because AbstractBundle does not
        // enable autoconfiguration for our namespace.
        foreach ([ListSpreadsheetsCommand::class, TabsCommand::class, PeekCommand::class, DoctorCommand::class] as $cmd) {
            $services
                ->set($cmd)
                ->args([service('google_sheets.registry')])
                ->tag('console.command')
            ;
        }
    }

    /**
     * Validate the resolved config and return the factory args plus the named
     * spreadsheets map.
     *
     * @param array<int|string, mixed> $config
     *
     * @return array{
     *     0: array{api_key: string|null, client_id: string|null, client_secret: string|null, auth_config: string|array<string, mixed>|null},
     *     1: list<string>,
     *     2: string|null,
     *     3: array<string, array{id: string, sheet: string|null, cache: array{ttl: int, pool: string}|null}>,
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
            throw new LogicException('google_sheets.spreadsheets must be a map of name => entry.');
        }
        $spreadsheetMap = [];
        foreach ($spreadsheets as $name => $entry) {
            if (!is_string($name) || '' === $name) {
                throw new LogicException('google_sheets.spreadsheets keys must be non-empty strings.');
            }
            if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id']) || '' === $entry['id']) {
                throw new LogicException(sprintf('google_sheets.spreadsheets["%s"].id must be a non-empty string.', $name));
            }
            $sheet = $entry['sheet'] ?? null;
            if (null !== $sheet && (!is_string($sheet) || '' === $sheet)) {
                throw new LogicException(sprintf('google_sheets.spreadsheets["%s"].sheet must be a non-empty string or omitted.', $name));
            }
            $cache = null;
            $rawCache = $entry['cache'] ?? null;
            if (is_array($rawCache) && [] !== $rawCache) {
                $ttl = $rawCache['ttl'] ?? null;
                if (!is_int($ttl) || $ttl < 1) {
                    throw new LogicException(sprintf('google_sheets.spreadsheets["%s"].cache.ttl must be a positive integer.', $name));
                }
                $pool = $rawCache['pool'] ?? 'cache.app';
                if (!is_string($pool) || '' === $pool) {
                    throw new LogicException(sprintf('google_sheets.spreadsheets["%s"].cache.pool must be a non-empty string.', $name));
                }
                $cache = ['ttl' => $ttl, 'pool' => $pool];
            }
            $spreadsheetMap[$name] = ['id' => $entry['id'], 'sheet' => $sheet, 'cache' => $cache];
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
     * A spreadsheet name is valid when it converts to a non-empty PHP variable
     * name. Allowed characters: letters, digits, underscores, dashes, dots.
     * Must start with a letter or underscore (so the camelCased result starts
     * with a letter and is bindable as `SheetsService $name`).
     */
    private static function isValidSpreadsheetName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $name);
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
