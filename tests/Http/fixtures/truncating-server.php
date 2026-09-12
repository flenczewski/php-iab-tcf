<?php

declare(strict_types=1);

/**
 * Answers one request with a Content-Length far larger than the body it then
 * sends before hanging up. PHP's built-in server always frames its responses
 * correctly, so a raw socket is the only way to produce a truncated one.
 *
 * Usage: php truncating-server.php <port>
 */
$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:{$port}");
if ($server === false) {
    exit(1);
}

while (($connection = @stream_socket_accept($server, 30)) !== false) {
    fgets($connection);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 100000\r\nConnection: close\r\n\r\n");
    fwrite($connection, '0123456789');
    fclose($connection);
}
