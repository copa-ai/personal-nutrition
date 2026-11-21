<?php

namespace Tests\Support;

use App\Services\Coder\SondaSsh;

/** An SSH test that opens no socket. */
class SondaSshFalsa extends SondaSsh
{
    public ?string $respuesta = 'SSH-2.0-OpenSSH_9.6';

    /** @var list<array{0: string, 1: int}> */
    public array $pruebas = [];

    public function banner(string $host, int $puerto, int $segundos): ?string
    {
        $this->pruebas[] = [$host, $puerto];

        return $this->respuesta;
    }
}
