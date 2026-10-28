<?php

namespace RepeatedHeadersConsumer\Service;

use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * Sends a request with each header field line written exactly as given.
 *
 * HTTP clients such as Guzzle merge, reorder or reject repeated header lines,
 * so the request is written to a plain TCP socket instead.
 */
class HttpClientService
{
    public function __construct(private UriInterface $baseUri)
    {
    }

    /**
     * @param array<array{string, string}> $fieldLines list of [name, value] pairs, one per field line
     *
     * @return int the response status code
     */
    public function getHeaders(array $fieldLines): int
    {
        $host = $this->baseUri->getHost();
        $port = (int) $this->baseUri->getPort();
        $socket = stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, 5);
        if ($socket === false) {
            throw new RuntimeException(sprintf('Can not connect to %s:%d: %s (%d)', $host, $port, $errorMessage, $errorCode));
        }

        try {
            stream_set_timeout($socket, 5);
            $request = "GET /headers HTTP/1.1\r\n";
            $request .= "Host: {$host}:{$port}\r\n";
            foreach ($fieldLines as [$name, $value]) {
                $request .= "{$name}: {$value}\r\n";
            }
            $request .= "Connection: close\r\n\r\n";
            fwrite($socket, $request);

            $statusLine = fgets($socket);
            if ($statusLine === false || !preg_match('/^HTTP\/1\.[01] (\d{3})/', $statusLine, $matches)) {
                throw new RuntimeException(sprintf('Invalid status line: %s', var_export($statusLine, true)));
            }
            // Drain the rest of the response so the mock server finishes handling the request.
            stream_get_contents($socket);

            return (int) $matches[1];
        } finally {
            fclose($socket);
        }
    }
}
