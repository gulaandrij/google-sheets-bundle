<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Sheets as GoogleSheets;
use Gulaandrij\GoogleSheetsBundle\Exception\MissingCredentialsException;
use Gulaandrij\GoogleSheetsBundle\GoogleSheetsBundle;
use Gulaandrij\GoogleSheetsBundle\Profiler\SheetsCollector;
use Gulaandrij\GoogleSheetsBundle\Profiler\TraceableSheetsService;
use Gulaandrij\GoogleSheetsBundle\Service\GoogleClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use Gulaandrij\GoogleSheetsBundle\Tests\Fixtures\TestKernel;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolution\Google\Sheets\SheetsClient;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * @internal
 */
#[CoversClass(GoogleSheetsBundle::class)]
final class GoogleSheetsBundleTest extends TestCase
{
    private static int $counter = 0;

    private ?TestKernel $kernel = null;

    public function testFactoryServicesAreAvailableWithMinimalConfig(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
        ]);

        self::assertTrue($kernel->getContainer()->has(SheetsClientFactory::class));
        self::assertFalse(
            $kernel->getContainer()->has(SheetsService::class),
            'Without any configured spreadsheets the bare SheetsService alias must NOT be registered',
        );
    }

    public function testNamedSpreadsheetGetsItsOwnService(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'allocators' => ['id' => '1abc_allocators'],
            ],
        ]);

        $container = $kernel->getContainer();
        self::assertTrue($container->has('google_sheets.sheets_service.allocators'));

        $service = $container->get('google_sheets.sheets_service.allocators');
        self::assertInstanceOf(SheetsService::class, $service);
        self::assertSame('1abc_allocators', $service->getSpreadsheetId());
        self::assertNull($service->getBoundSheet());
    }

    public function testSheetNameFromConfigIsBoundToService(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'allocators' => ['id' => '1abc', 'sheet' => 'Allocator List'],
            ],
        ]);

        $service = $kernel->getContainer()->get('google_sheets.sheets_service.allocators');
        self::assertInstanceOf(SheetsService::class, $service);
        self::assertSame('Allocator List', $service->getBoundSheet());
    }

    public function testSingleSpreadsheetBackingTheBareSheetsServiceAlias(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'reports' => ['id' => '1xyz_reports'],
            ],
        ]);

        $container = $kernel->getContainer();
        self::assertTrue($container->has(SheetsService::class));

        $service = $container->get(SheetsService::class);
        self::assertInstanceOf(SheetsService::class, $service);
        self::assertSame('1xyz_reports', $service->getSpreadsheetId());
    }

    public function testMultipleSpreadsheetsWithExplicitDefault(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'allocators' => ['id' => '1abc_allocators'],
                'reports' => ['id' => '1xyz_reports'],
            ],
            'default_spreadsheet' => 'reports',
        ]);

        $container = $kernel->getContainer();

        $allocators = $container->get('google_sheets.sheets_service.allocators');
        $reports = $container->get('google_sheets.sheets_service.reports');
        self::assertInstanceOf(SheetsService::class, $allocators);
        self::assertInstanceOf(SheetsService::class, $reports);
        self::assertSame('1abc_allocators', $allocators->getSpreadsheetId());
        self::assertSame('1xyz_reports', $reports->getSpreadsheetId());

        $default = $container->get(SheetsService::class);
        self::assertInstanceOf(SheetsService::class, $default);
        self::assertSame('1xyz_reports', $default->getSpreadsheetId());
    }

    public function testMultipleSpreadsheetsWithoutDefaultFailsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/default_spreadsheet must be set/');

        $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'allocators' => ['id' => '1abc_allocators'],
                'reports' => ['id' => '1xyz_reports'],
            ],
        ]);
    }

    public function testDefaultPointingAtMissingSpreadsheetFailsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/default_spreadsheet must reference an entry/');

        $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'allocators' => ['id' => '1abc_allocators'],
            ],
            'default_spreadsheet' => 'reports',
        ]);
    }

    public function testInvalidSpreadsheetKeyFailsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/spreadsheets keys must contain only letters/');

        $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'my reports' => ['id' => '1abc'], // space disallowed
            ],
        ]);
    }

    public function testNumericSpreadsheetKeyFailsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/spreadsheets keys must contain only letters/');

        // YAML key `123:` becomes int 123 in PHP — caught by the identifier
        // validator since integer keys don't satisfy the regex.
        $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                123 => ['id' => '1abc'],
            ],
        ]);
    }

    public function testEmptyScopeFailsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/scopes entries must be non-empty/');

        $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'scopes' => ['https://www.googleapis.com/auth/spreadsheets', ''],
        ]);
    }

    public function testAutowireByVariableNameAliasIsRegistered(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'allocators' => ['id' => '1abc_allocators'],
                'reports' => ['id' => '1xyz_reports'],
            ],
            'default_spreadsheet' => 'allocators',
        ]);

        $container = $kernel->getContainer();

        self::assertTrue($container->has(SheetsService::class.' $allocators'));
        self::assertTrue($container->has(SheetsService::class.' $reports'));

        $allocators = $container->get(SheetsService::class.' $allocators');
        $reports = $container->get(SheetsService::class.' $reports');
        self::assertInstanceOf(SheetsService::class, $allocators);
        self::assertInstanceOf(SheetsService::class, $reports);
        self::assertSame('1abc_allocators', $allocators->getSpreadsheetId());
        self::assertSame('1xyz_reports', $reports->getSpreadsheetId());
    }

    public function testHyphenAndUnderscoreNamesCamelCaseTheBinding(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'spreadsheets' => [
                'billing_data' => ['id' => '1one'],
                'my-reports' => ['id' => '1two'],
            ],
            'default_spreadsheet' => 'billing_data',
        ]);

        $container = $kernel->getContainer();
        self::assertTrue($container->has(SheetsService::class.' $billingData'));
        self::assertTrue($container->has(SheetsService::class.' $myReports'));
    }

    public function testInstantiatingTheServiceWithoutCredentialsThrows(): void
    {
        $kernel = $this->bootKernel([
            'spreadsheets' => ['x' => ['id' => '1abc']],
        ]);

        $this->expectException(MissingCredentialsException::class);
        $kernel->getContainer()->get(SheetsService::class);
    }

    public function testCustomScopesAreForwardedToTheGoogleClient(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
            'scopes' => ['https://example.com/custom-scope'],
        ]);

        $client = $kernel->getContainer()->get(GoogleClient::class);
        self::assertInstanceOf(GoogleClient::class, $client);
        self::assertSame(['https://example.com/custom-scope'], $client->getScopes());
    }

    public function testDefaultScopesAreReadOnly(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
        ]);

        $client = $kernel->getContainer()->get(GoogleClient::class);
        self::assertInstanceOf(GoogleClient::class, $client);
        self::assertSame([
            GoogleSheets::SPREADSHEETS_READONLY,
            GoogleSheets::DRIVE_READONLY,
        ], $client->getScopes());
    }

    public function testApplicationNameAndOAuthCredentialsAreForwarded(): void
    {
        $kernel = $this->bootKernel([
            'application_name' => 'My App',
            'auth' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
            ],
        ]);

        $client = $kernel->getContainer()->get(GoogleClient::class);
        self::assertInstanceOf(GoogleClient::class, $client);
        self::assertSame('My App', $client->getConfig('application_name'));
        self::assertSame('cid', $client->getClientId());
    }

    public function testClientFactoryAcceptsAuthConfigAsArray(): void
    {
        $kernel = $this->bootKernel([
            'auth' => [
                'auth_config' => [
                    'type' => 'service_account',
                    'project_id' => 'demo',
                    'private_key_id' => 'k',
                    'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----",
                    'client_email' => 'demo@example.iam.gserviceaccount.com',
                    'client_id' => '123',
                ],
            ],
        ]);

        $factory = $kernel->getContainer()->get('google_sheets.client_factory');
        self::assertInstanceOf(GoogleClientFactory::class, $factory);

        $auth = (new ReflectionProperty($factory, 'auth'))->getValue($factory);

        self::assertIsArray($auth);
        self::assertIsArray($auth['auth_config']);
        self::assertSame('service_account', $auth['auth_config']['type']);
    }

    public function testInvalidCachePoolReferenceFailsAtBootWithBundleScopedError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/cache\.pool refers to service "missing\.pool"/');

        $this->bootKernel(
            [
                'auth' => ['api_key' => 'test-key'],
                'spreadsheets' => [
                    'allocators' => [
                        'id' => '1abc',
                        'sheet' => 'List',
                        'cache' => ['ttl' => 60, 'pool' => 'missing.pool'],
                    ],
                ],
            ],
            debug: false,
        );
    }

    public function testGoogleDriveServiceIsRegisteredAndAutowireable(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
        ]);

        $container = $kernel->getContainer();
        self::assertTrue($container->has(GoogleDrive::class));
        self::assertInstanceOf(GoogleDrive::class, $container->get(GoogleDrive::class));
    }

    public function testSheetsClientIsRegisteredAsNonShared(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
        ]);

        $a = $kernel->getContainer()->get(SheetsClient::class);
        $b = $kernel->getContainer()->get(SheetsClient::class);

        self::assertInstanceOf(SheetsClient::class, $a);
        self::assertInstanceOf(SheetsClient::class, $b);
        self::assertNotSame($a, $b);
    }

    public function testDebugModeWrapsSheetsServiceWithTraceableSubclassAndRegistersCollector(): void
    {
        $kernel = $this->bootKernel(
            [
                'auth' => ['api_key' => 'test-key'],
                'spreadsheets' => [
                    'allocators' => ['id' => '1abc', 'sheet' => 'List'],
                ],
            ],
            debug: true,
        );

        $container = $kernel->getContainer();

        self::assertTrue($container->has('google_sheets.profiler.collector'));
        $collector = $container->get('google_sheets.profiler.collector');
        self::assertInstanceOf(SheetsCollector::class, $collector);

        $service = $container->get(SheetsService::class);
        self::assertInstanceOf(TraceableSheetsService::class, $service);
    }

    public function testProdModeUsesPlainSheetsServiceAndSkipsCollector(): void
    {
        $kernel = $this->bootKernel(
            [
                'auth' => ['api_key' => 'test-key'],
                'spreadsheets' => [
                    'allocators' => ['id' => '1abc', 'sheet' => 'List'],
                ],
            ],
            debug: false,
        );

        $container = $kernel->getContainer();

        self::assertFalse($container->has('google_sheets.profiler.collector'));

        $service = $container->get(SheetsService::class);
        self::assertInstanceOf(SheetsService::class, $service);
        self::assertNotInstanceOf(TraceableSheetsService::class, $service);
    }

    /**
     * @param array<string, mixed> $bundleConfig
     */
    private function bootKernel(array $bundleConfig, bool $debug = true): TestKernel
    {
        ++self::$counter;
        $kernel = new TestKernel($bundleConfig, 'tk-'.self::$counter, $debug);
        $kernel->boot();
        $this->kernel = $kernel;

        return $kernel;
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        $root = sys_get_temp_dir().'/google-sheets-bundle-tests';
        if (is_dir($root)) {
            self::deleteTree($root);
        }
    }

    private static function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                @unlink($path);
            }

            return;
        }

        $entries = scandir($path);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            self::deleteTree($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
