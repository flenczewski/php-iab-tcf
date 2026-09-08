<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Gvl;

use Flenczewski\IabTcf\Http\HttpClient;
use Flenczewski\IabTcf\Http\StreamHttpClient;

/**
 * Fetches the Global Vendor List over the network. Prefer {@see Gvl::bundled()}
 * for most use cases (fast, offline, no network dependency at runtime) — use
 * this class only when you deliberately need the freshest possible list.
 *
 * Pass an {@see HttpClient} to control how the request is made; the default
 * {@see StreamHttpClient} needs no dependencies but you can supply
 * {@see \Flenczewski\IabTcf\Http\Psr18HttpClient} to reuse a PSR-18 client.
 */
final class GvlFetcher
{
    private const BASE_URL = 'https://vendor-list.consensu.org/v3/';

    public function __construct(private readonly ?HttpClient $httpClient = null)
    {
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
        return ($this->httpClient ?? new StreamHttpClient())->get($url);
    }
}
