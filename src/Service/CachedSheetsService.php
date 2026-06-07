<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Decorator that caches read operations through a Symfony cache contract.
 * Writes (append/update/clear/addSheet/deleteSheet) pass through unchanged
 * and invalidate the cached entries for the affected sheet.
 *
 * Wired by the bundle when a binding declares `cache: {ttl: <seconds>, pool: <id>}`.
 */
final class CachedSheetsService extends SheetsService
{
    public function __construct(
        SheetsClientFactory $factory,
        string $spreadsheetId,
        ?string $boundSheet,
        private readonly CacheInterface $cache,
        private readonly int $ttlSeconds,
        private readonly string $serviceName,
    ) {
        parent::__construct($factory, $spreadsheetId, $boundSheet);
    }

    public function readRaw(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        return $this->cached(
            'readRaw',
            [$sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption],
            fn (): array => parent::readRaw($sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption),
        );
    }

    public function readAssoc(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        return $this->cached(
            'readAssoc',
            [$sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption],
            fn (): array => parent::readAssoc($sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption),
        );
    }

    public function firstRow(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        return $this->cached(
            'firstRow',
            [$sheetName, $range, $valueRenderOption, $dateTimeRenderOption],
            fn (): array => parent::firstRow($sheetName, $range, $valueRenderOption, $dateTimeRenderOption),
        );
    }

    public function listSheets(): array
    {
        return $this->cached('listSheets', [], fn (): array => parent::listSheets());
    }

    public function listSheetsWithIds(): array
    {
        return $this->cached('listSheetsWithIds', [], fn (): array => parent::listSheetsWithIds());
    }

    public function spreadsheetProperties(): object
    {
        return $this->cached('spreadsheetProperties', [], fn (): object => parent::spreadsheetProperties());
    }

    public function sheetProperties(?string $sheetName = null): object
    {
        return $this->cached(
            'sheetProperties',
            [$sheetName],
            fn (): object => parent::sheetProperties($sheetName),
        );
    }

    /**
     * @template T
     *
     * @param list<mixed>   $args
     * @param callable(): T $fn
     *
     * @return T
     */
    private function cached(string $method, array $args, callable $fn): mixed
    {
        $key = $this->cacheKey($method, $args);

        return $this->cache->get($key, function (ItemInterface $item) use ($fn): mixed {
            $item->expiresAfter($this->ttlSeconds);

            return $fn();
        });
    }

    /**
     * @param list<mixed> $args
     */
    private function cacheKey(string $method, array $args): string
    {
        return sprintf(
            'google_sheets.%s.%s.%s',
            $this->serviceName,
            $method,
            hash('xxh128', serialize($args)),
        );
    }
}
