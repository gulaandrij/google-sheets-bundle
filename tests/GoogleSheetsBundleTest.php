<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests;

use Gulaandrij\GoogleSheetsBundle\Exception\MissingCredentialsException;
use Gulaandrij\GoogleSheetsBundle\GoogleSheetsBundle;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use Gulaandrij\GoogleSheetsBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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

        $service = $kernel->getContainer()->get(SheetsService::class);
        self::assertInstanceOf(SheetsService::class, $service);

        $googleService = $service->client()->getService();
        $googleClient = $googleService->getClient();

        self::assertSame(['https://example.com/custom-scope'], $googleClient->getScopes());
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

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            self::deleteTree($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
