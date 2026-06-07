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

        $factory = $this->factoryReturning(
            $client,
            auth: $this->auth(apiKey: 'secret-key'),
            scopes: ['scope-a'],
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

        $factory = $this->factoryReturning(
            $client,
            auth: $this->auth(clientId: 'cid', clientSecret: 'csecret'),
            scopes: ['scope-b'],
        );

        $factory();
    }

    public function testAuthConfigStringIsApplied(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setAuthConfig')->with('/etc/google.json');

        $factory = $this->factoryReturning(
            $client,
            auth: $this->auth(authConfig: '/etc/google.json'),
        );

        $factory();
    }

    public function testAuthConfigArrayIsApplied(): void
    {
        $payload = ['type' => 'service_account', 'project_id' => 'demo'];

        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setAuthConfig')->with($payload);

        $factory = $this->factoryReturning(
            $client,
            auth: $this->auth(authConfig: $payload),
        );

        $factory();
    }

    public function testApplicationNameIsForwardedWhenProvided(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setApplicationName')->with('My App');

        $factory = $this->factoryReturning(
            $client,
            auth: $this->auth(apiKey: 'k'),
            scopes: ['s'],
            applicationName: 'My App',
        );

        $factory();
    }

    public function testEmptyScopesArrayIsNotForwarded(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::never())->method('setScopes');

        $factory = $this->factoryReturning(
            $client,
            auth: $this->auth(apiKey: 'k'),
            scopes: [],
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

        $factory = $this->factoryReturning(
            $client,
            auth: [
                'api_key' => 'k',
                'client_id' => '',
                'client_secret' => '',
                'auth_config' => '',
            ],
            scopes: [],
            applicationName: '',
        );

        $factory();
    }

    public function testEmptyAuthConfigArrayIsTreatedAsAbsent(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::once())->method('setDeveloperKey')->with('k');
        $client->expects(self::never())->method('setAuthConfig');

        $factory = $this->factoryReturning(
            $client,
            auth: $this->auth(apiKey: 'k', authConfig: []),
        );

        $factory();
    }

    public function testMissingCredentialsThrows(): void
    {
        $client = $this->createMock(GoogleClient::class);
        $client->expects(self::never())->method('setDeveloperKey');

        $factory = $this->factoryReturning($client, auth: $this->auth());

        $this->expectException(MissingCredentialsException::class);
        $factory();
    }

    /**
     * @param array{api_key: string|null, client_id: string|null, client_secret: string|null, auth_config: string|array<string, mixed>|null} $auth
     * @param list<string>                                                                                                                   $scopes
     */
    private function factoryReturning(
        GoogleClient $client,
        array $auth,
        array $scopes = [],
        ?string $applicationName = null,
    ): GoogleClientFactory {
        return new class($auth, $scopes, $applicationName, $client) extends GoogleClientFactory {
            public function __construct(
                array $auth,
                array $scopes,
                ?string $applicationName,
                private readonly GoogleClient $stub,
            ) {
                parent::__construct($auth, $scopes, $applicationName);
            }

            protected function newClient(): GoogleClient
            {
                return $this->stub;
            }
        };
    }

    /**
     * @param string|array<string, mixed>|null $authConfig
     *
     * @return array{
     *     api_key: string|null,
     *     client_id: string|null,
     *     client_secret: string|null,
     *     auth_config: string|array<string, mixed>|null,
     * }
     */
    private function auth(
        ?string $apiKey = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        string|array|null $authConfig = null,
    ): array {
        return [
            'api_key' => $apiKey,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'auth_config' => $authConfig,
        ];
    }
}
