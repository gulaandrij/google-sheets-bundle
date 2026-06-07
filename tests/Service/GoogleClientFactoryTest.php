<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Service;

use Google\Client as GoogleClient;
use Gulaandrij\GoogleSheetsBundle\Exception\MissingCredentialsException;
use Gulaandrij\GoogleSheetsBundle\Service\GoogleClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GoogleClientFactory::class)]
final class GoogleClientFactoryTest extends TestCase
{
    public function testApiKeyIsAppliedToTheClient(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setDeveloperKey')->with('secret-key');
        $client->expects(self::once())->method('setScopes')->with(['scope-a']);
        $client->expects(self::never())->method('setClientId');
        $client->expects(self::never())->method('setClientSecret');
        $client->expects(self::never())->method('setAuthConfig');
        $client->expects(self::never())->method('setApplicationName');

        $factory = new GoogleClientFactory(
            auth: $this->auth(apiKey: 'secret-key'),
            scopes: ['scope-a'],
            clientBuilder: static fn () => $client,
        );

        self::assertSame($client, $factory());
    }

    public function testOAuthCredentialsAreApplied(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setClientId')->with('cid');
        $client->expects(self::once())->method('setClientSecret')->with('csecret');
        $client->expects(self::once())->method('setScopes')->with(['scope-b']);
        $client->expects(self::never())->method('setDeveloperKey');
        $client->expects(self::never())->method('setAuthConfig');

        $factory = new GoogleClientFactory(
            auth: $this->auth(clientId: 'cid', clientSecret: 'csecret'),
            scopes: ['scope-b'],
            clientBuilder: static fn () => $client,
        );

        $factory();
    }

    public function testAuthConfigIsApplied(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setAuthConfig')->with('/etc/google.json');

        $factory = new GoogleClientFactory(
            auth: $this->auth(authConfig: '/etc/google.json'),
            scopes: [],
            clientBuilder: static fn () => $client,
        );

        $factory();
    }

    public function testApplicationNameIsForwardedWhenProvided(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setApplicationName')->with('My App');

        $factory = new GoogleClientFactory(
            auth: $this->auth(apiKey: 'k'),
            scopes: ['s'],
            applicationName: 'My App',
            clientBuilder: static fn () => $client,
        );

        $factory();
    }

    public function testEmptyScopesArrayIsNotForwarded(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::never())->method('setScopes');

        $factory = new GoogleClientFactory(
            auth: $this->auth(apiKey: 'k'),
            scopes: [],
            clientBuilder: static fn () => $client,
        );

        $factory();
    }

    public function testEmptyStringsAreTreatedAsAbsent(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setDeveloperKey')->with('k');
        $client->expects(self::never())->method('setClientId');
        $client->expects(self::never())->method('setClientSecret');
        $client->expects(self::never())->method('setAuthConfig');
        $client->expects(self::never())->method('setApplicationName');

        $factory = new GoogleClientFactory(
            auth: [
                'api_key' => 'k',
                'client_id' => '',
                'client_secret' => '',
                'auth_config' => '',
            ],
            scopes: [],
            applicationName: '',
            clientBuilder: static fn () => $client,
        );

        $factory();
    }

    public function testMissingCredentialsThrows(): void
    {
        $factory = new GoogleClientFactory(
            auth: $this->auth(),
            scopes: [],
            clientBuilder: static fn () => self::fail('Builder should not be invoked when credentials are missing.'),
        );

        $this->expectException(MissingCredentialsException::class);
        $factory();
    }

    /**
     * @return array{
     *     api_key: string|null,
     *     client_id: string|null,
     *     client_secret: string|null,
     *     auth_config: string|null,
     * }
     */
    private function auth(
        ?string $apiKey = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $authConfig = null,
    ): array {
        return [
            'api_key' => $apiKey,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'auth_config' => $authConfig,
        ];
    }
}
