<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use Closure;
use Google\Client as GoogleClient;
use Gulaandrij\GoogleSheetsBundle\Exception\MissingCredentialsException;
use LogicException;

/**
 * Builds a configured `Google\Client` from bundle configuration.
 *
 * The factory accepts every supported authentication method and lets the caller
 * pick whichever fits their setup. At least one of `api_key`,
 * `client_id`/`client_secret`, or `auth_config` must be provided.
 *
 * @phpstan-type AuthConfig array{
 *     api_key: string|null,
 *     client_id: string|null,
 *     client_secret: string|null,
 *     auth_config: string|array<string, mixed>|null,
 * }
 */
final class GoogleClientFactory
{
    /**
     * @param AuthConfig   $auth
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly array $auth,
        private readonly array $scopes,
        private readonly ?string $applicationName = null,
        private readonly ?Closure $clientBuilder = null,
    ) {
    }

    public function __invoke(): GoogleClient
    {
        $authConfig = $this->auth['auth_config'];
        $hasAuthConfig = (is_string($authConfig) && '' !== $authConfig)
            || (is_array($authConfig) && [] !== $authConfig);

        $hasAnyCredential = (null !== $this->auth['api_key'] && '' !== $this->auth['api_key'])
            || (null !== $this->auth['client_id'] && '' !== $this->auth['client_id'])
            || $hasAuthConfig;

        if (!$hasAnyCredential) {
            throw new MissingCredentialsException(MissingCredentialsException::DEFAULT_MESSAGE);
        }

        $client = $this->buildClient();

        if (null !== $this->applicationName && '' !== $this->applicationName) {
            $client->setApplicationName($this->applicationName);
        }

        if (null !== $this->auth['api_key'] && '' !== $this->auth['api_key']) {
            $client->setDeveloperKey($this->auth['api_key']);
        }

        if (null !== $this->auth['client_id'] && '' !== $this->auth['client_id']) {
            $client->setClientId($this->auth['client_id']);
        }

        if (null !== $this->auth['client_secret'] && '' !== $this->auth['client_secret']) {
            $client->setClientSecret($this->auth['client_secret']);
        }

        if ($hasAuthConfig) {
            $client->setAuthConfig($authConfig);
        }

        if ([] !== $this->scopes) {
            $client->setScopes($this->scopes);
        }

        return $client;
    }

    private function buildClient(): GoogleClient
    {
        if (null === $this->clientBuilder) {
            return new GoogleClient();
        }

        $result = ($this->clientBuilder)();
        if (!$result instanceof GoogleClient) {
            throw new LogicException(sprintf('GoogleClientFactory $clientBuilder must return a %s instance.', GoogleClient::class));
        }

        return $result;
    }
}
