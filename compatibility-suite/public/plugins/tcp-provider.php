<?php

// A line based TCP provider: answers $argv[2] to the line $argv[1], and "MATTerrorMATT" to anything else.

$args = array_map(fn (mixed $arg): string => is_string($arg) ? $arg : '', (array) ($_SERVER['argv'] ?? []));
[, $port, $line, $reply] = $args + ['', '', '', ''];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if (!$server) {
    fwrite(STDERR, "$error\n");
    exit(1);
}

while ($conn = @stream_socket_accept($server, -1)) {
    while (false !== ($got = fgets($conn))) {
        $answer = rtrim($got, "\r\n") === $line ? $reply : 'MATTerrorMATT';
        if (false === @fwrite($conn, "$answer\n")) {
            break;
        }
    }
    fclose($conn);
}
