<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server, used by StreamHttpClientTest to exercise
 * real HTTP status handling. The path selects the status code.
 */
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

if ($path === '/ok') {
    header('Content-Type: application/json');
    echo '{"vendorListVersion":175}';
    return true;
}

if ($path === '/echo-accept') {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    echo is_string($accept) ? $accept : '';
    return true;
}

if ($path === '/redirect') {
    header('Location: /ok', true, 302);
    echo 'moved';
    return true;
}

if (is_string($path) && preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
    http_response_code((int) $m[1]);
    echo 'status ' . $m[1];
    return true;
}

http_response_code(404);
echo 'not found';
return true;
