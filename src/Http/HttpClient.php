<?php

declare(strict_types=1);

namespace Flenczewski\IabTcf\Http;

/**
 * Minimal GET-only HTTP seam. Deliberately not PSR-18: this package has no
 * runtime dependencies, and Psr18HttpClient adapts a PSR-18 client onto this
 * interface for consumers who already have one.
 */
interface HttpClient
{
    /**
     * @throws \Flenczewski\IabTcf\Exception\GvlException on transport failure or a non-2xx status
     */
    public function get(string $url): string;
}
