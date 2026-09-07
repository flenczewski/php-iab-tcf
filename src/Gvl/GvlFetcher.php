<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Gvl;

use Flenczewski\IabTcf\Exception\GvlException;

/**
 * Fetches the Global Vendor List over the network. Prefer {@see Gvl::bundled()}
 * for most use cases (fast, offline, no network dependency at runtime) — use
 * this class only when you deliberately need the freshest possible list.
 */
final class GvlFetcher
{
    private const BASE_URL = 'https://vendor-list.consensu.org/v3/';

    /** @param (callable(string): (string|false))|null $httpGet injectable for testing; defaults to file_get_contents() */
    public function __construct(private $httpGet = null)
    {
        $this->httpGet ??= static fn (string $url): string|false => file_get_contents($url);
    }

    public function fetchLatest(): Gvl
    {
        return Gvl::fromJson($this->fetchLatestRaw());
    }

    public function fetchVersion(int $version): Gvl
    {
        return Gvl::fromJson($this->fetchVersionRaw($version));
    }

    /**
     * Returns the raw, unparsed JSON payload. Prefer this (over fetchLatest())
     * when you need to persist the exact server response — Gvl/Vendor only
     * model the fields relevant to consent validation, so round-tripping
     * through them would silently drop everything else (descriptions,
     * illustrations, standardTexts, dataCategories, ...).
     */
    public function fetchLatestRaw(): string
    {
        return $this->get(self::urlForLatest());
    }

    public function fetchVersionRaw(int $version): string
    {
        return $this->get(self::urlForVersion($version));
    }

    public static function urlForLatest(): string
    {
        return self::BASE_URL . 'vendor-list.json';
    }

    public static function urlForVersion(int $version): string
    {
        return self::BASE_URL . "archives/vendor-list-v{$version}.json";
    }

    private function get(string $url): string
    {
        $result = ($this->httpGet)($url);
        if ($result === false) {
            throw new GvlException("Failed to fetch the Global Vendor List from {$url}.");
        }

        return $result;
    }
}
