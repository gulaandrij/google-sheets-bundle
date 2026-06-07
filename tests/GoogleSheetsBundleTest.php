<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Gulaandrij\GoogleSheetsBundle\Exception\MissingCredentialsException;
use Gulaandrij\GoogleSheetsBundle\GoogleSheetsBundle;
use Gulaandrij\GoogleSheetsBundle\Service\GoogleClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use Gulaandrij\GoogleSheetsBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolution\Google\Sheets\SheetsClient;

/**
 * @internal
 */
#[CoversClass(GoogleSheetsBundle::class)]
final class GoogleSheetsBundleTest extends TestCase
{
    private static int $counter = 0;

    private ?TestKernel $kernel = null;

    public function testSheetsServiceIsAvailableWithMinimalConfig(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
        ]);

        self::assertTrue($kernel->getContainer()->has(SheetsService::class));
        self::assertTrue($kernel->getContainer()->has(SheetsClientFactory::class));
    }

    public function testInstantiatingTheServiceWithoutCredentialsThrows(): void
    {
        $kernel = $this->bootKernel([]);

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

    public function testSheetsClientIsRegisteredAsNonShared(): void
    {
        $kernel = $this->bootKernel([
            'auth' => ['api_key' => 'test-key'],
        ]);

        $a = $kernel->getContainer()->get(SheetsClient::class);
        $b = $kernel->getContainer()->get(SheetsClient::class);

        self::assertInstanceOf(SheetsClient::class, $a);
        self::assertInstanceOf(SheetsClient::class, $b);
        self::assertNotSame($a, $b, 'sheets_client must be non-shared so stateful selectors do not leak between consumers');
    }

    /**
     * @param array<string, mixed> $bundleConfig
     */
    private function bootKernel(array $bundleConfig): TestKernel
    {
        ++self::$counter;
        $kernel = new TestKernel($bundleConfig, 'tk-'.self::$counter);
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
