<?php

// A minimal unary gRPC provider over HTTP/2 without TLS (h2c, prior knowledge). Every call is answered with the
// message in the file $argv[2], framed for gRPC, and a grpc-status 0 trailer. Request headers are not decoded.

$args = array_map(fn (mixed $arg): string => is_string($arg) ? $arg : '', (array) ($_SERVER['argv'] ?? []));
[, $port, $messageFile] = $args + ['', '', ''];
$message = (string) file_get_contents($messageFile);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if (!$server) {
    fwrite(STDERR, "$error\n");
    exit(1);
}

const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

function frame(int $type, int $flags, int $stream, string $payload): string
{
    return substr(pack('N', strlen($payload)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $payload;
}

function literal(string $name, string $value): string
{
    // Literal header field without indexing, new name, no Huffman coding (lengths < 127)
    return "\x00" . chr(strlen($name)) . $name . chr(strlen($value)) . $value;
}

function u32(string $bytes): int
{
    $value = unpack('N', $bytes);

    return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
}

/** @param resource $conn */
function respond($conn, int $stream, string $message): void
{
    $headers = "\x88" . literal('content-type', 'application/grpc'); // :status 200
    $data = "\x00" . pack('N', strlen($message)) . $message;
    fwrite($conn, frame(0x1, 0x4, $stream, $headers)
        . frame(0x0, 0x0, $stream, $data)
        . frame(0x1, 0x5, $stream, literal('grpc-status', '0')));
}

$clients = [];
$buffers = []; // unparsed bytes per connection
$prefaced = []; // whether the connection preface was read
while (true) {
    $read = array_merge([$server], array_values($clients));
    $write = $except = null;
    if (false === stream_select($read, $write, $except, null)) {
        break;
    }
    foreach ($read as $sock) {
        if ($sock === $server) {
            $conn = @stream_socket_accept($server, 0);
            if ($conn) {
                $id = (int) $conn;
                $clients[$id] = $conn;
                $buffers[$id] = '';
                $prefaced[$id] = false;
                fwrite($conn, frame(0x4, 0x0, 0, '')); // SETTINGS
            }
            continue;
        }
        $id = (int) $sock;
        $chunk = fread($sock, 65536);
        if ('' === $chunk || false === $chunk) {
            fclose($sock);
            unset($clients[$id], $buffers[$id], $prefaced[$id]);
            continue;
        }
        $buf = ($buffers[$id] ?? '') . $chunk;
        if (!($prefaced[$id] ?? false)) {
            if (strlen($buf) < strlen(PREFACE)) {
                $buffers[$id] = $buf;
                continue;
            }
            $buf = substr($buf, strlen(PREFACE));
            $prefaced[$id] = true;
        }
        while (strlen($buf) >= 9) {
            $length = u32("\x00" . substr($buf, 0, 3));
            if (strlen($buf) < 9 + $length) {
                break;
            }
            $type = ord($buf[3]);
            $flags = ord($buf[4]);
            $stream = u32(substr($buf, 5, 4)) & 0x7FFFFFFF;
            $payload = substr($buf, 9, $length);
            $buf = substr($buf, 9 + $length);
            if (0x4 === $type && !($flags & 0x1)) {
                fwrite($sock, frame(0x4, 0x1, 0, '')); // SETTINGS ACK
            } elseif (0x6 === $type && !($flags & 0x1)) {
                fwrite($sock, frame(0x6, 0x1, 0, $payload)); // PING ACK
            } elseif ((0x0 === $type || 0x1 === $type) && ($flags & 0x1) && $stream > 0) {
                respond($sock, $stream, $message); // the request ended (END_STREAM)
            }
        }
        $buffers[$id] = $buf;
    }
}
