<?php

namespace App\Services\Coder;

/** Test that an address speaks SSH. The server sends its banner first, so the test reads it and sends nothing. */
class SondaSsh
{
    /** @return string|null the first line that the server sent, or null if the connection or the read fails */
    public function banner(string $host, int $puerto, int $segundos): ?string
    {
        $direccion = str_contains($host, ':') ? "[{$host}]" : $host;
        $socket = @stream_socket_client("tcp://{$direccion}:{$puerto}", $codigo, $mensaje, $segundos);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, $segundos);
        $linea = fgets($socket, 256);
        fclose($socket);

        return is_string($linea) ? rtrim($linea) : null;
    }
}
